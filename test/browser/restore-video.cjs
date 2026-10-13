'use strict';
// Real external YouTube; local application, sessions, invitations and mail isolated.
const { chromium, firefox, webkit } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');
const net = require('node:net');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const root = path.resolve(__dirname, '../..');
const php = process.env.TEST_PHP || 'php';
const arg = (key, fallback) => process.argv.find(a => a.startsWith(key + '='))?.slice(key.length + 1) || fallback;
const out = path.resolve(__dirname, arg('--output', 'restored-video'));
const engines = arg('--engines', 'chrome,firefox,webkit').split(',');
const views = [
  { name: 'desktop', width: 1440, height: 900, touch: false },
  { name: 'portrait', width: 390, height: 844, touch: true },
  { name: 'small', width: 320, height: 700, touch: true }
];
const fixture = (...a) => {
  const output = cp.execFileSync(php, [path.join(__dirname, 'fixture.php'), ...a], { encoding: 'utf8' });
  return output.trim() ? JSON.parse(output) : null;
};
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const sha = file => crypto.createHash('sha256').update(fs.readFileSync(path.join(root, file))).digest('hex');
const report = {
  initial_head: cp.execFileSync('git', ['-c', `safe.directory=${root.replaceAll('\\', '/')}`, 'rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim(),
  source_sha256: sha('index.php'), thumbnail_sha256: sha('video-thumbnail.png'),
  node: process.version, playwright: require('playwright/package.json').version,
  real_youtube: true, playback_claim: 'Only observed advancing currentTime with paused=false counts as playback.',
  started_at: new Date().toISOString(), engines: [], cases: []
};
fs.mkdirSync(out, { recursive: true });
const save = () => fs.writeFileSync(path.join(out, 'results.json'), JSON.stringify(report, null, 2) + '\n');

async function runEngine(engine) {
  const site = fixture('create');
  const socket = net.createServer();
  await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
  const port = socket.address().port;
  await new Promise(resolve => socket.close(resolve));
  const base = `http://127.0.0.1:${port}`;
  const log = fs.openSync(path.join(site.dir, 'server.log'), 'a');
  const server = cp.spawn(php, ['-d', `session.save_path=${site.dir}/data`, '-S', `127.0.0.1:${port}`, '-t', site.dir], { stdio: ['ignore', log, log], windowsHide: true });
  fs.closeSync(log);
  let browser;
  try {
    let ready = false;
    for (let i = 0; i < 60 && !ready; i++) {
      ready = await new Promise(resolve => {
        const probe = net.createConnection({ host: '127.0.0.1', port });
        probe.once('connect', () => { probe.destroy(); resolve(true); });
        probe.once('error', () => { probe.destroy(); resolve(false); });
      });
      if (!ready) await delay(100);
    }
    assert(ready, 'Isolated PHP starts');
    const options = { headless: true };
    if (engine === 'chrome') options.executablePath = process.env.TEST_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
    browser = await ({ chrome: chromium, firefox, webkit }[engine]).launch(options);
    const engineResult = { engine, version: browser.version() };
    report.engines.push(engineResult);
    for (const view of views) {
      const name = `${engine}-${view.name}`;
      const result = { name, engine, viewport: view, passed: false, screenshots: [], javascript_errors: [], console_errors: [], failed_requests: [], http_errors: [], csp_violations: [], embed_responses: [], touch_events: 0, playback_verified: false };
      report.cases.push(result);
      const context = await browser.newContext({ viewport: { width: view.width, height: view.height }, hasTouch: view.touch, isMobile: engine !== 'firefox' && view.touch });
      const page = await context.newPage();
      let navigationExpected = false;
      page.on('pageerror', e => result.javascript_errors.push(e.message));
      page.on('console', m => { if (m.type() === 'error') result.console_errors.push({ message: m.text(), location: m.location() }); });
      page.on('requestfailed', r => { const url = new URL(r.url()); result.failed_requests.push({ url: url.origin + url.pathname, error: r.failure()?.errorText, during_intentional_navigation: navigationExpected }); });
      page.on('response', r => {
        if (r.url().startsWith('https://www.youtube.com/embed/')) result.embed_responses.push({ url: r.url(), status: r.status() });
        if (r.status() >= 400) result.http_errors.push({ url: r.url(), status: r.status() });
      });
      await context.addInitScript(() => {
        window.acceptanceCsp = []; window.acceptanceTouches = 0;
        document.addEventListener('securitypolicyviolation', e => window.acceptanceCsp.push({ directive: e.violatedDirective, blocked: e.blockedURI }));
        document.addEventListener('touchstart', () => window.acceptanceTouches++, { capture: true, passive: true });
      });
      let posts = 0;
      page.on('request', r => { if (r.url().startsWith(base) && r.method() === 'POST') posts++; });
      const screenshot = async label => {
        const filename = `${name}-${label}.png`;
        await page.getByRole('region', { name: 'Esittelyvideo' }).screenshot({ path: path.join(out, filename), animations: 'disabled' });
        result.screenshots.push(filename);
      };
      try {
        const response = await page.goto(base + '/index.php', { waitUntil: 'load' });
        const csp = response.headers()['content-security-policy'];
        assert.match(csp, /script-src 'self' 'nonce-[^']+'/);
        assert(!csp.includes("script-src 'self' 'unsafe-inline'"), 'CSP script security preserved');
        const button = page.getByRole('button', { name: 'Katso ohjevideo' });
        const frame = page.locator('#video-iframe iframe');
        assert(await button.isVisible());
        assert.equal(await frame.count(), 0, 'No iframe before interaction');
        assert.equal(result.embed_responses.length, 0, 'No YouTube request before interaction');
        const imageResponse = await context.request.get(base + '/video-thumbnail.png');
        assert.equal(imageResponse.status(), 200);
        assert.deepEqual(await imageResponse.body(), fs.readFileSync(path.join(root, 'video-thumbnail.png')), 'Exact supplied thumbnail asset');
        await page.waitForFunction(async () => {
          const button = document.getElementById('video-thumbnail');
          const image = new Image(); image.src = new URL('video-thumbnail.png', location.href).href;
          try { await image.decode(); } catch { return false; }
          return image.naturalWidth > 0 && getComputedStyle(button).backgroundImage.includes('video-thumbnail.png');
        });
        result.thumbnail = await button.evaluate(e => {
          const box = e.getBoundingClientRect(), play = e.firstElementChild, p = play.getBoundingClientRect(), s = getComputedStyle(play), triangle = getComputedStyle(play.firstElementChild);
          return { width: box.width, height: box.height, border_radius: getComputedStyle(e).borderRadius, background: getComputedStyle(e).backgroundImage, play_width: p.width, play_height: p.height, play_background: s.backgroundColor, play_radius: s.borderRadius, center_dx: p.x + p.width / 2 - box.x - box.width / 2, center_dy: p.y + p.height / 2 - box.y - box.height / 2, triangle_color: triangle.borderLeftColor, triangle_width: triangle.borderLeftWidth, overflow: document.documentElement.scrollWidth > innerWidth + 1 };
        });
        const t = result.thumbnail;
        assert(Math.abs(t.width / t.height - 16 / 9) < 0.02);
        assert.equal(t.border_radius, '8px'); assert.equal(t.play_radius, '50%');
        assert.equal(t.play_width, 80); assert.equal(t.play_height, 80);
        assert.equal(t.play_background, 'rgba(0, 0, 0, 0.8)');
        assert.equal(t.triangle_color, 'rgb(255, 255, 255)'); assert.equal(t.triangle_width, '25px');
        assert(Math.abs(t.center_dx) < 1 && Math.abs(t.center_dy) < 1, 'Play centered');
        assert(!t.overflow);
        await button.scrollIntoViewIfNeeded();
        await screenshot('thumbnail');
        if (!view.touch) {
          await button.hover();
          await page.waitForFunction(() => getComputedStyle(document.querySelector('#video-thumbnail > div')).transform.startsWith('matrix(1.1,'));
          assert.equal(await button.locator(':scope > div').evaluate(e => getComputedStyle(e).backgroundColor), 'rgba(255, 255, 255, 0.2)');
          await page.mouse.move(0, 0);
          await page.waitForFunction(() => getComputedStyle(document.querySelector('#video-thumbnail > div')).transform.startsWith('matrix(1,'));
          assert.equal(await button.locator(':scope > div').evaluate(e => getComputedStyle(e).backgroundColor), 'rgba(0, 0, 0, 0.8)');
          result.hover_verified = true;
        }
        const token = await page.locator('input[name=workflow_token]').first().getAttribute('value');
        const before = await page.locator('#startForm').boundingBox();
        if (view.touch) await button.tap(); else await button.click();
        await frame.waitFor({ state: 'visible' });
        assert.equal(await frame.count(), 1);
        assert.equal(await frame.getAttribute('src'), 'https://www.youtube.com/embed/X2fGUT1MzM0?autoplay=1');
        assert.equal(await frame.getAttribute('referrerpolicy'), 'strict-origin-when-cross-origin');
        assert((await frame.getAttribute('allow')).includes('autoplay'));
        assert.equal(await button.isVisible(), false);
        const box = await frame.boundingBox(), after = await page.locator('#startForm').boundingBox();
        assert(Math.abs(box.width / box.height - 16 / 9) < 0.02);
        assert(Math.abs(box.width - t.width) < 1 && Math.abs(box.height - t.height) < 1);
        assert(Math.abs(before.y - after.y) < 1, 'Questionnaire does not shift after click');
        assert.equal(posts, 0, 'Video does not submit form');
        assert.equal(await page.locator('input[name=workflow_token]').first().getAttribute('value'), token);
        result.autoplay_requested = true;
        // Inspect actual external media, without route replacements. Autoplay policy is observed, not bypassed.
        try {
          const body = page.frameLocator('#video-iframe iframe').locator('body');
          await body.waitFor({ state: 'visible', timeout: 30000 });
          await page.frameLocator('#video-iframe iframe').locator('.html5-video-player').waitFor({ state: 'visible', timeout: 30000 });
          result.player_visible = true;
          let previousTime = null;
          for (let i = 0; i < 20; i++) {
            result.media_state = await body.evaluate(body => {
              const video = body.querySelector('video');
              return video ? { currentTime: video.currentTime, paused: video.paused, readyState: video.readyState, error: video.error ? { code: video.error.code, message: video.error.message } : null } : null;
            });
            if (result.media_state && !result.media_state.paused && previousTime !== null && result.media_state.currentTime > previousTime + 0.1) { result.playback_verified = true; break; }
            previousTime = result.media_state?.currentTime ?? null;
            await delay(250);
          }
          result.player_errors = await body.locator('.ytp-error').filter({ visible: true }).allTextContents();
          if (result.player_errors.length || result.media_state?.error) result.media_limitation = 'External YouTube player reports an error; see recorded details.';
          else if (!result.playback_verified) result.media_limitation = 'Autoplay requested; advancing playback not verified (browser policy or external media availability).';
        } catch (e) { result.media_limitation = e.message; }
        await screenshot('player');
        result.touch_events = await page.evaluate(() => window.acceptanceTouches);
        if (view.touch) assert(result.touch_events > 0, 'Actual emulated touch observed');
        result.csp_violations = await page.evaluate(() => window.acceptanceCsp);
        assert.equal(result.csp_violations.length, 0, 'No application CSP violations');
        assert.equal(result.javascript_errors.length, 0, 'No JS exceptions');
        result.expected_cookie_messages = result.console_errors.filter(e => engine === 'firefox' && e.location.url.startsWith('https://www.youtube.com/') && /Cookie.*__Secure-(BUCKET|YEC).*SameSite/.test(e.message));
        result.unexpected_console_errors = result.console_errors.filter(e => !result.expected_cookie_messages.includes(e));
        assert.equal(result.unexpected_console_errors.length, 0, 'No unexpected console errors (known YouTube SameSite messages recorded separately)');
        assert.equal(result.http_errors.filter(e => e.url.startsWith(base)).length, 0, 'No local missing assets');
        // Check keyboard support separately on a fresh intro, without mutating answers.
        navigationExpected = true;
        if (!view.touch) {
          await page.goto(base + '/index.php', { waitUntil: 'load' });
          await button.focus(); await page.keyboard.press('Enter');
          await frame.waitFor({ state: 'visible' }); assert.equal(await frame.count(), 1);
          result.keyboard_verified = true;
        }
        await page.locator('#nickname').fill('Video browser acceptance Ää');
        await page.locator('#ennea').selectOption('en tiedä');
        await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.locator('button[name=start]').click()]);
        assert.equal(posts, 1, 'Exactly one questionnaire start POST after video');
        assert.equal(await page.locator('iframe').count(), 0, 'Video scoped to intro');
        assert(await page.locator('input[name=choice]').count() > 0, 'Questionnaire starts normally after video');
        result.start_after_video_verified = true;
        result.passed = true;
      } catch (e) {
        result.error = e.stack;
        await screenshot('failure').catch(() => {});
      } finally {
        await context.close();
        result.expected_cookie_messages = result.console_errors.filter(e => engine === 'firefox' && e.location.url.startsWith('https://www.youtube.com/') && /Cookie.*__Secure-(BUCKET|YEC).*SameSite/.test(e.message));
        result.unexpected_console_errors = result.console_errors.filter(e => !result.expected_cookie_messages.includes(e));
        if (result.unexpected_console_errors.length) {
          result.passed = false;
          result.error = result.error || 'Unexpected console errors after video navigation';
        }
        save();
        console.log(`${result.passed ? 'PASS' : 'FAIL'} ${name}; playback=${result.playback_verified}${result.error ? '; ' + result.error.split('\n')[0] : ''}`);
      }
    }
    engineResult.php_diagnostics = fs.readFileSync(path.join(site.dir, 'server.log'), 'utf8').split(/\r?\n/).filter(l => /Warning:|Fatal error:|Notice:|BROWSER_SNAPSHOT_FAILED/.test(l));
    assert.equal(engineResult.php_diagnostics.length, 0);
  } finally {
    if (browser) await browser.close();
    if (server.exitCode === null) { const stopped = new Promise(resolve => server.once('exit', resolve)); server.kill(); await stopped; }
    fixture('cleanup', site.dir);
  }
}
(async () => {
  const results = await Promise.allSettled(engines.map(runEngine));
  report.harness_errors = results.flatMap((r, i) => r.status === 'rejected' ? [{ engine: engines[i], error: r.reason.stack }] : []);
  report.finished_at = new Date().toISOString();
  report.passed = !report.harness_errors.length && report.cases.length === engines.length * views.length && report.cases.every(c => c.passed);
  save(); console.log(`Video cases: ${report.cases.filter(c => c.passed).length}/${report.cases.length} passed; harness errors: ${report.harness_errors.length}`);
  for (const error of report.harness_errors) console.error(error);
  process.exitCode = report.passed ? 0 : 1;
})();
