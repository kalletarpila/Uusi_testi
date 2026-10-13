'use strict';
// Real YouTube, isolated local PHP/data/mail. No questionnaire submissions.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');
const net = require('node:net');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const php = process.env.TEST_PHP || 'php';
const root = path.resolve(__dirname, '../..');
const baseline = process.argv.includes('--baseline');
const outputArg = process.argv.find(arg => arg.startsWith('--output='))?.slice(9);
const output = path.resolve(__dirname, outputArg || path.join('video', baseline ? 'before' : 'after'));
fs.mkdirSync(output, { recursive: true });
const fixture = (...args) => JSON.parse(cp.execFileSync(php, [path.join(__dirname, 'fixture.php'), ...args], { encoding: 'utf8' }));
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const report = {
  initial_head: cp.execFileSync('git', ['-c', `safe.directory=${root.replaceAll('\\', '/')}`, 'rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim(),
  application_sha256: crypto.createHash('sha256').update(fs.readFileSync(path.join(root, 'index.php'))).digest('hex'),
  baseline, real_youtube: true, started_at: new Date().toISOString(), cases: []
};
(async () => {
  const site = fixture('create');
  const socket = net.createServer();
  await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
  const port = socket.address().port;
  await new Promise(resolve => socket.close(resolve));
  const base = `http://127.0.0.1:${port}`;
  const logfile = fs.openSync(path.join(site.dir, 'server.log'), 'a');
  const server = cp.spawn(php, ['-d', `session.save_path=${site.dir}/data`, '-S', `127.0.0.1:${port}`, '-t', site.dir], { stdio: ['ignore', logfile, logfile], windowsHide: true });
  fs.closeSync(logfile);
  let browser;
  try {
    let ready = false;
    for (let i = 0; i < 60; i++) {
      ready = await new Promise(resolve => {
        const probe = net.createConnection({ host: '127.0.0.1', port });
        probe.once('connect', () => { probe.destroy(); resolve(true); });
        probe.once('error', () => { probe.destroy(); resolve(false); });
      });
      if (ready) break;
      await delay(100);
    }
    assert(ready, 'Local isolated PHP starts');
    browser = await chromium.launch({ headless: true, executablePath: process.env.TEST_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
    report.browser = browser.version();
    const views = baseline ? [{ name: 'desktop', width: 1440, height: 900 }] : [
      { name: 'desktop', width: 1440, height: 900 },
      { name: 'portrait', width: 390, height: 844, touch: true },
      { name: 'small', width: 320, height: 700, touch: true }
    ];
    for (const view of views) {
      const result = { name: view.name, viewport: view, passed: false, javascript_errors: [], console_errors: [], failed_requests: [], embed_responses: [] };
      report.cases.push(result);
      const context = await browser.newContext({ viewport: { width: view.width, height: view.height }, hasTouch: !!view.touch, isMobile: !!view.touch, javaScriptEnabled: view.javascript !== false });
      const page = await context.newPage();
      page.on('pageerror', e => result.javascript_errors.push(e.message));
      page.on('console', m => { if (m.type() === 'error') result.console_errors.push({ message: m.text(), location: m.location() }); });
      page.on('requestfailed', r => result.failed_requests.push({ url: r.url(), error: r.failure()?.errorText }));
      page.on('response', r => { if (r.url().startsWith('https://www.youtube.com/embed/')) result.embed_responses.push({ url: r.url(), status: r.status() }); });
      try {
        // No route mocks: the actual external YouTube player must render.
        await page.goto(base + '/index.php', { waitUntil: 'domcontentloaded' });
        const video = page.locator('iframe[src^="https://www.youtube.com/embed/"]');
        result.iframes_at_load = await video.count();
        if (!result.iframes_at_load) {
          await page.screenshot({ path: path.join(output, view.name + '.png'), fullPage: true });
          assert.fail('YouTube iframe missing on initial page load');
        }
        assert.equal(result.iframes_at_load, 1);
        assert.equal(await video.getAttribute('src'), 'https://www.youtube.com/embed/X2fGUT1MzM0');
        assert(await video.isVisible(), 'Iframe visible without any click');
        assert.equal(await page.locator('#video-thumbnail').count(), 0, 'No placeholder overlay');
        await video.scrollIntoViewIfNeeded();
        result.geometry = await video.evaluate(e => {
          const box = e.getBoundingClientRect();
          return { x: box.x, y: box.y, width: box.width, height: box.height, overflow: document.documentElement.scrollWidth > innerWidth + 1, center_uncovered: document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2) === e };
        });
        assert(!result.geometry.overflow, 'No horizontal overflow');
        assert(result.geometry.center_uncovered, 'No layer intercepts iframe');
        assert(result.geometry.width > 200 && result.geometry.height > 100);
        assert(Math.abs(result.geometry.width / result.geometry.height - 16 / 9) < 0.02, 'Responsive 16:9 layout');
        // With JS enabled, inspect YouTube's actual rendered player, not just an iframe rectangle.
        if (view.javascript !== false) {
          const frame = await video.contentFrame();
          await frame.locator('.html5-video-player').waitFor({ state: 'visible', timeout: 30000 });
          await frame.getByRole('button', { name: /^(Toista video|Play(?: video)?)$/ }).first().waitFor({ state: 'visible', timeout: 30000 });
          result.player_controls = await frame.locator('button').evaluateAll(es => es.filter(e => e.getClientRects().length).map(e => ({ label: e.getAttribute('aria-label'), class: e.className })));
          result.poster_images = await frame.locator('body').evaluate(body => [...body.querySelectorAll('*')].filter(e => e.getClientRects().length).flatMap(e => [getComputedStyle(e).backgroundImage, e.tagName === 'IMG' && e.naturalWidth > 0 ? e.currentSrc : '']).filter(image => /ytimg\.com/.test(image)));
          assert(result.poster_images.length > 0, 'Real YouTube poster image is rendered');
          result.player_visible = true;
          result.player_error = await frame.locator('.ytp-error').filter({ visible: true }).allTextContents();
          assert.equal(result.player_error.length, 0, 'YouTube player has no error panel');
        }
        await delay(1000);
        await page.screenshot({ path: path.join(output, view.name + '.png'), fullPage: true });
        assert(result.embed_responses.some(r => r.status === 200), 'Actual YouTube embed returned HTTP 200');
        assert.equal(result.javascript_errors.length, 0, 'No JavaScript exceptions');
        assert.equal(result.console_errors.length, 0, 'No console errors');
        result.passed = true;
      } catch (e) {
        result.error = e.stack;
        await page.screenshot({ path: path.join(output, view.name + '.png'), fullPage: true }).catch(() => {});
      } finally {
        await context.close();
        fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify(report, null, 2) + '\n');
        console.log(`${result.passed ? 'PASS' : 'FAIL'} ${view.name}${result.error ? ': ' + result.error.split('\n')[0] : ''}`);
      }
    }
    report.php_diagnostics = fs.readFileSync(path.join(site.dir, 'server.log'), 'utf8').split(/\r?\n/).filter(l => /Warning:|Fatal error:|Notice:|BROWSER_SNAPSHOT_FAILED/.test(l));
    assert.equal(report.php_diagnostics.length, 0);
  } catch (e) { report.harness_error = e.stack; }
  finally {
    if (browser) await browser.close();
    if (server.exitCode === null) { const stopped = new Promise(resolve => server.once('exit', resolve)); server.kill(); await stopped; }
    cp.execFileSync(php, [path.join(__dirname, 'fixture.php'), 'cleanup', site.dir]);
    report.finished_at = new Date().toISOString();
    report.passed = !report.harness_error && report.cases.length === (baseline ? 1 : 3) && report.cases.every(c => c.passed);
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify(report, null, 2) + '\n');
    process.exitCode = report.passed ? 0 : 1;
  }
})();
