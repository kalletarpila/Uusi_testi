'use strict';
// Actual browser actions only. Catalog/snapshots observe the real isolated PHP app.
const { chromium, firefox, webkit } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const cp = require('node:child_process');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const php = process.env.TEST_PHP || 'php';
const args = process.argv.slice(2);
const baseline = args.includes('--baseline');
const stress = args.includes('--stress-all-controls');
const arg = (key, fallback) => args.find(x => x.startsWith(key+'='))?.slice(key.length+1) || fallback;
const p2Navigation = args.includes('--phase2-navigation');
const navigationTracks = arg('--tracks','A2,B2,C2,D2').split(',');
assert(navigationTracks.every(t=>['A2','B2','C2','D2'].includes(t)),'Known navigation tracks');
const out = path.resolve(__dirname, arg('--output', baseline ? '.runtime/baseline' : '.runtime/results'));
const engineNames = arg('--engines','chrome,firefox,webkit').split(',');
const modes=p2Navigation?['navigation']:arg('--modes','normal,pairwise,legacy,two-way,three-way,decisive').split(',');
const workers=Number(arg('--workers','2'));
assert(Number.isInteger(workers)&&workers>=1&&workers<=3,'Use 1–3 isolated browser contexts per engine');
const views = [
  {name:'desktop',width:1440,height:900,touch:false},
  {name:'laptop',width:1366,height:768,touch:false},
  {name:'portrait',width:390,height:844,touch:true},
  {name:'landscape',width:844,height:390,touch:true},
  {name:'small',width:320,height:700,touch:true},
  {name:'tablet',width:768,height:1024,touch:true}
].map((v,index)=>({...v,index})).filter(v=>(!p2Navigation||['desktop','portrait','small'].includes(v.name))&&(!arg('--viewport','')||v.name===arg('--viewport','')));
const pairs = ['AB','AC','AD','BC','BD','CD'];
const types = {A:'4',B:'3',C:'1',D:'2'};
const tracks = {A:['4','7','9'],B:['3','7','8'],C:['1','5','6'],D:['2','6','9']};
const normalize = x => x.replace(/\s+/g,' ').trim();
const report = {initial_head:cp.execFileSync('git',['-c',`safe.directory=${root.replaceAll('\\','/')}`,'rev-parse','HEAD'],{cwd:root,encoding:'utf8'}).trim(),playwright:require('playwright/package.json').version,baseline,cases:[],engines:[],external_requests:[],started_at:new Date().toISOString()};
Object.assign(report,{node:process.version,platform:process.platform,modes,workers_per_engine:workers,inputs:Object.fromEntries(['index.php','InsideBlockQuestions.csv','SeparateBlocksQuestions.csv','SeparateBlocksQuestionsTiebreak.csv','TypeDescriptions.csv'].map(f=>[f,crypto.createHash('sha256').update(fs.readFileSync(path.join(root,f))).digest('hex')]))});
fs.mkdirSync(out,{recursive:true});
const fixture = (...a)=>JSON.parse(cp.execFileSync(php,[path.join(__dirname,'fixture.php'),...a],{encoding:'utf8'}));
const delay = ms=>new Promise(r=>setTimeout(r,ms));

class User {
  constructor(page,context,site,view,name,catalog,result){Object.assign(this,{page,context,site,view,name,catalog,result});this.posts=0;this.issues=[];this.states=new Set();this.screens=new Set();this.touchEvents=0;this.dialogs=[];
    page.on('pageerror',e=>result.javascript_errors.push(e.message));
    page.on('console',m=>{if(m.type()==='error')result.console_errors.push(m.text());});
    page.on('response',r=>{if(r.status()>=400&&r.url().startsWith(site.base))result.http_errors.push({url:r.url().replace(site.base,''),status:r.status()});});
    page.on('request',r=>{if(r.method()==='POST'&&r.url().startsWith(site.base))this.posts++;});
    page.on('dialog',async d=>{this.dialogs.push(d.message());try{await d.accept();}catch(e){result.dialog_errors.push(e.message);}});
  }
  async state(afterToken=null){
    const cookies=await this.context.cookies();this.sid=cookies.find(c=>c.name==='PHPSESSID')?.value;assert(this.sid,'Real PHP session cookie');
    const inputs=this.page.locator('input[name=workflow_token]');const displayed=await inputs.count()?await inputs.first().getAttribute('value'):null;
    for(let attempt=0;attempt<600;attempt++){
      let state;try{state=JSON.parse(fs.readFileSync(path.join(this.site.dir,'data',`state-${this.sid}.json`),'utf8'));}
      catch(e){if(!['ENOENT','EBUSY','EPERM','EACCES'].includes(e.code))throw e;}
      if(state&&(!displayed||state.workflow_token===displayed)&&(!afterToken||state.workflow_token!==afterToken)){this.s=state;this.states.add(state.state);return state;}
      await delay(10);
    }
    throw new Error('Observer did not publish the rendered request token');
  }
  issue(kind,detail){const item={state:this.s?.state||'intro',kind,detail};if(!this.issues.some(x=>x.state===item.state&&x.kind===kind))this.issues.push(item);}
  async screenshot(label){
    if(this.screens.has(label))return;this.screens.add(label);
    const representative=(this.result.engine==='chrome'&&['desktop','small'].includes(this.view.name))||(this.result.engine==='webkit'&&this.view.name==='portrait');
    if(this.result.mode==='navigation'&&this.result.track!=='A2'&&!/failure|exception/.test(label))return;
    if(!representative&&!/failure|exception/.test(label)&&!baseline)return;
    const labels={normal:['intro','phase2','result','feedback'],pairwise:['selected','tb-question'],legacy:['selected','tb2','result'],'two-way':['tb2','result'],'three-way':['result'],decisive:['tb2','result'],navigation:['phase2-selected-strong','phase2-selected-mild','phase2-selected-neutral','tb2-selected','restored','invalidated','result']};
    if(!baseline&&!/failure|exception/.test(label)&&!labels[this.result.mode].includes(label))return;
    const name=`${this.name}-${label}.png`;await this.page.screenshot({path:path.join(out,name),fullPage:true,animations:'disabled'});this.result.screenshots.push(name);
  }
  async audit(label,force=false){await this.state();if(!force&&this.result.checked_states.includes(label))return;if(!this.result.checked_states.includes(label))this.result.checked_states.push(label);this.result.geometry_audits=(this.result.geometry_audits||0)+1;
    const geometry=await this.page.evaluate(()=>{
      const visible=e=>!!e.getClientRects().length&&getComputedStyle(e).visibility!=='hidden';
      const controls=[...document.querySelectorAll('button,input:not([type=hidden]),select,textarea,a.linkbtn')].filter(visible).map(e=>{const r=e.getBoundingClientRect(),s=getComputedStyle(e),pseudo=getComputedStyle(e,'::before');let font=parseFloat(s.fontSize);if(font===0&&pseudo.content!=='none')font=parseFloat(pseudo.fontSize);return {text:(e.textContent||e.value||e.name).trim().slice(0,80),x:r.x,y:r.y,w:r.width,h:r.height,font,clipped:e.scrollWidth>e.clientWidth+2||e.scrollHeight>e.clientHeight+2,tag:e.tagName};});
      const texts=[...document.querySelectorAll('.block p,.case-desc,.opt-box-content,.question-text,.opt-box')].filter(visible).map(e=>({text:e.textContent.trim().slice(0,60),font:parseFloat(getComputedStyle(e).fontSize),w:e.getBoundingClientRect().width}));
      const overlaps=[];for(let i=0;i<controls.length;i++)for(let j=i+1;j<controls.length;j++){let a=controls[i],b=controls[j];if(Math.min(a.x+a.w,b.x+b.w)-Math.max(a.x,b.x)>2&&Math.min(a.y+a.h,b.y+b.h)-Math.max(a.y,b.y)>2)overlaps.push([a.text,b.text]);}
      return {viewport:innerWidth,document:document.documentElement.scrollWidth,controls,texts,overlaps};
    });
    if(geometry.document>geometry.viewport+1)this.issue('horizontal-overflow',geometry);
    // WebKit may report 43.99994 for a CSS min-height of 44px.
    const bad=geometry.controls.filter(c=>c.x< -1||c.x+c.w>geometry.viewport+1||c.clipped||(this.view.touch&&(c.w<43.9||c.h<43.9))||(c.text&&c.font<14));
    if(bad.length)this.issue('control-size-clipping-or-font',bad);
    if(geometry.overlaps.length)this.issue('overlapping-controls',geometry.overlaps);
    if(geometry.texts.some(t=>t.font<14||t.w<60))this.issue('unreadable-question-options',geometry.texts);
    if(this.issues.some(x=>x.state===this.s.state))await this.screenshot('failure-'+label);
  }
  async press(locator,{twice=false}={}){
    locator=locator.filter({visible:true}).first();await locator.scrollIntoViewIfNeeded();
    const point=await locator.evaluate(e=>{const r=e.getBoundingClientRect();const hit=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2);return e===hit||e.contains(hit);});assert(point,'Visible control center not intercepted');
    const before=this.posts;const expectedPosts=await locator.evaluate(e=>e.tagName==='A'?0:1);
    const previousToken=this.s?.workflow_token;
    const nav=this.page.waitForNavigation({waitUntil:'load',timeout:15000});
    await Promise.all([nav,(async()=>{
      if(this.view.touch){const handle=await locator.elementHandle();await handle.tap();if(twice)await handle.tap({timeout:1000}).catch(()=>{});await handle.dispose();}else if(twice)await locator.dblclick();else await locator.click();
    })()]);
    await this.state(expectedPosts?previousToken:null);await delay(40);
    if(this.posts-before!==expectedPosts)this.issue('unexpected-submit-count',{posts:this.posts-before,expected:expectedPosts});
  }
  form(field,value){return this.page.locator('form').filter({has:this.page.locator(`input[name="${field}"][value="${value}"]`)}).locator('button');}
  async begin(code,legacy=false){await this.page.goto(this.site.base+'/index.php'+(legacy?'?oldTB1=1':''));await this.audit('intro');
    if(this.view.name==='small'||this.view.name==='desktop')await this.screenshot('intro');
    const video=this.page.locator('iframe#video-iframe');assert.equal(await video.count(),1,'Embed exists immediately');assert(await video.isVisible(),'Embed visible without a click');assert.equal(await video.getAttribute('src'),'https://www.youtube.com/embed/X2fGUT1MzM0');assert.equal(await this.page.locator('#video-thumbnail').count(),0,'No video placeholder overlay');
    const before=this.posts;await this.page.locator('button[name=start]').click();assert.equal(this.posts,before,'Incomplete introduction rejected in browser');assert.equal(this.dialogs.length,1);assert.equal(await this.page.locator('#ennea').evaluate(e=>document.activeElement===e),true);
    await this.page.locator('#ennea').selectOption('4');assert.equal(await this.page.locator('#confidence').isVisible(),true);await this.audit('intro-confidence',true);
    await this.page.locator('button[name=start]').click();assert.equal(this.posts,before);assert.equal(this.dialogs.length,2);await this.page.locator('#confidence').selectOption('2');
    await this.page.locator('#nickname').fill('Selain Ää; '+this.name);await this.page.locator('#ennea').selectOption('en tiedä');assert.equal(await this.page.locator('#confidence').isVisible(),false);
    assert.equal(await this.page.locator('#startForm').evaluate(e=>new FormData(e).has('confidence')),false,'Hidden obsolete confidence must not be submitted');
    if(code)await this.page.locator('#invite_code').fill(code);
    await this.press(this.page.locator('button[name=start]'));assert.equal(this.s.state,'quiz1');
  }
  ordinaryQuestion(){return this.page.locator('.card > .block p').first();}
  async ordinary(values){let count=0;const seen=new Set();while(this.s.state==='quiz1'){
    const text=normalize(await this.ordinaryQuestion().innerText());const q=this.catalog.phase1.find(q=>normalize(q.text)===text);assert(q,'Rendered Phase1 text found in actual CSV catalog');
    assert(!seen.has(text),'No skipped/duplicate Phase1 presentation');seen.add(text);
    assert.equal(await this.page.locator('.progress').getAttribute('title'),Math.round((this.s.q1_index+1)/36*100)+'%');await this.audit('phase1',true);
    await this.press(this.form('choice',values[q.class]),{twice:count===0});count++;assert(count<=36,'Phase1 advances');
  }assert.equal(count,36);}
  async enterTB(legacy){await this.press(this.page.locator(`button[name=${legacy?'continue_tiebreak':'continue_tb1_new'}]`));assert.equal(this.s.state,legacy?'tiebreak':'tb1_new');}
  async tbAnswer(winner,legacy){if(!legacy){
    const text=normalize(await this.page.locator('.question-text').innerText());
    const groups=await this.page.locator('input[name=tb1_new_choice]').evaluateAll(es=>es.map(e=>e.value).sort().join(''));
    const q=this.catalog.tb1.find(q=>normalize(q.statement)===text&&[q.block_left,q.block_right].sort().join('')===groups);assert(q,'Rendered pairwise statement found in CSV');
    for(const side of ['left','right']){
      const box=this.page.locator('.opt-box.'+side);const group=await box.locator('input[name=tb1_new_choice]').inputValue();
      assert.equal(normalize(await box.locator(':scope > div').first().innerText()),normalize(q[group===q.block_left?'option_left':'option_right']),'Randomized text/group mapping');
    }
    return this.form('tb1_new_choice',winner);
  }
    const text=normalize(await this.ordinaryQuestion().innerText());const q=this.catalog.legacy.find(q=>normalize(q.text)===text);assert(q,'Rendered legacy question catalog mapping');return this.form('tb_choice',q.class===winner+'1'?6:1);}
  counters(legacy){
    const expected=Object.fromEntries((legacy?['A1','B1','C1','D1']:['A','B','C','D']).map(g=>[g,0]));
    const answers=legacy?this.s.tiebreak_answers:this.s.tb1_new_answers;
    answers.forEach((a,i)=>{expected[legacy?this.s.tiebreak_set[i].class:a.slice(-1)]+=legacy?a:1;});
    assert.deepEqual(legacy?this.s.tiebreak_points:this.s.tb1_new_counters,expected,'Counters equal currently stored answers exactly');
  }
  async selected(field,value){const visible=this.form(field,value).filter({visible:true});assert.equal(await visible.getAttribute('aria-pressed'),'true');assert.match(await visible.getAttribute('class'),/selected/);
    const style=await visible.evaluate(e=>({background:getComputedStyle(e).backgroundColor,color:getComputedStyle(e).color}));const other=await this.page.locator(`button[aria-pressed=false]`).filter({visible:true}).first().evaluate(e=>({background:getComputedStyle(e).backgroundColor,color:getComputedStyle(e).color}));assert.notDeepEqual(style,other,'Selected visual style differs');}
  async navigation(winner,legacy){const field=legacy?'tb_choice':'tb1_new_choice',nav=legacy?'tiebreak_navigate':'tb1_new_navigate';
    const ordinaryScores=structuredClone(this.s.points1);
    let answer=await this.tbAnswer(winner,legacy);const val=await answer.locator('xpath=ancestor::form').locator(`input[name=${field}]`).getAttribute('value');await this.press(answer,{twice:stress});await this.press(await this.tbAnswer(winner,legacy));
    await this.press(this.form(nav,'back'));await this.selected(field,legacy?Number(val):val);await this.audit('tb-selected');await this.screenshot('selected');
    const answerCount=legacy?this.s.tiebreak_answers.length:this.s.tb1_new_answers.length;
    const index=legacy?this.s.tiebreak_index:this.s.tb1_new_index;const old=legacy?this.s.tiebreak_answers[index]:this.s.tb1_new_answers[index];
    const replacement=legacy?(Number(val)===6?1:6):(await this.page.locator('input[name=tb1_new_choice]').evaluateAll(es=>es.map(e=>e.value))).find(g=>g!==winner);
    await this.press(this.form(field,replacement));this.counters(legacy);await this.press(this.form(nav,'back'));await this.selected(field,replacement);assert.equal(legacy?this.s.tiebreak_answers.length:this.s.tb1_new_answers.length,answerCount,'Replacement not append');
    assert.deepEqual(this.s.points1,ordinaryScores,'Tiebreak edits do not alter ordinary scores');
    await this.press(this.form(field,legacy?Number(val):val));await this.press(this.form(nav,'back'));await this.selected(field,legacy?Number(val):val);
    await this.press(this.form(nav,'forward'));const saved=structuredClone(this.s);
    await this.press(this.page.getByRole('button',{name:'← Takaisin vaiheeseen 1',exact:true}));assert.equal(this.s.state,'quiz1');
    const ordinaryId=this.s.phase1[this.s.q1_index].id;const value=this.s.answers1_by_id[ordinaryId];await this.selected('choice',value);
    await this.press(this.form('navigate','next'));assert.equal(this.s.state,legacy?'tiebreak':'tb1_new');
    for(const key of legacy?['tiebreak_index','tiebreak_set','tiebreak_answers']:['tb1_new_index','tb1_new_questions','tb1_new_answers','tb1_new_counters'])assert.deepEqual(this.s[key],saved[key],'Unchanged Phase1 restores '+key);
    await this.press(this.form(nav,'back'));await this.selected(field,legacy?this.s.tiebreak_answers[this.s.tiebreak_index]:this.s.tb1_new_answers[this.s.tb1_new_index].slice(-1));await this.press(this.form(nav,'forward'));
    await this.press(this.page.getByRole('button',{name:'← Takaisin vaiheeseen 1',exact:true}));await this.press(this.form('choice',value===6?5:value+1));
    assert.equal(this.s.state,legacy?'tiebreak_intro':'tb1_new_intro','Changed ordinary answer restarts tie');await this.enterTB(legacy);
    assert.equal((legacy?this.s.tiebreak_answers:this.s.tb1_new_answers).length,0,'Obsolete selected answers cleared');assert.equal(await this.page.locator('button[aria-pressed=true]').filter({visible:true}).count(),0);
    // A viewport rotation while the same question and session are active.
    if(this.view.touch){const sid=this.sid;await this.page.setViewportSize({width:this.view.height,height:this.view.width});await this.audit('rotated-tb',true);await this.page.setViewportSize({width:this.view.width,height:this.view.height});await this.state();assert.equal(this.sid,sid);}
    await this.audit('tb-question',true);await this.screenshot('tb-question');
    let guard=0;while(this.s.state===(legacy?'tiebreak':'tb1_new')){await this.audit('tb-question',true);await this.press(await this.tbAnswer(winner,legacy));this.counters(legacy);assert(++guard<=10);}
    assert.equal(guard,(legacy?this.s.tiebreak_set:this.s.tb1_new_questions).length,'Every selected Phase1 tiebreak question answered');
    this.result.tb1_questions=guard;
    assert.equal(this.s.state,'phase2_intro');assert.equal(this.s.track,winner+'2');
  }
  async phase2(winner,unresolved){await this.audit('phase2-intro');await this.press(this.page.locator('button[name=continue_phase2]'));
    const decisive=unresolved==='decisive',remainsUnresolved=!!unresolved&&!decisive;
    const tied=unresolved==='two'?tracks[winner].slice(0,2):tracks[winner];
    let guard=0;const seen={phase2:new Set(),tb2:new Set()};while(['phase2','phase2_tb'].includes(this.s.state)){
      const tb=this.s.state==='phase2_tb',prefix=tb?'tb2':'choice2';const label=tb?'tb2':'phase2';await this.audit(label,true);
      const i=tb?this.s.tb2_index:this.s.q2_index,total=(tb?this.s.tb2_set:this.s.blocks2).length;
      assert.equal(await this.page.locator('.progress').getAttribute('title'),(tb?Math.floor(i/total*100):Math.round((i+1)/total*100))+'%');
      if(guard===0||tb&&!this.screens.has('tb2'))await this.screenshot(label);
      const desc=normalize(await this.page.locator('.case-desc').innerText());const left=normalize(await this.page.locator('.opt-left .opt-box-content').innerText());
      assert(!seen[label].has(desc),'No duplicate Phase2 presentation');seen[label].add(desc);
      const candidates=(tb?this.catalog.tb2:this.catalog.phase2)[(tb?'T':'')+winner+'2'];const q=candidates.find(q=>normalize(q.desc)===desc);assert(q,'Rendered Phase2 question catalog mapping');
      const leftType=normalize(q.left_text)===left?q.left_var:q.right_var;
      const rightType=normalize(q.right_text)===left?q.left_var:q.right_var;
      const target=types[winner];let side=unresolved?'neutral':leftType===target?'left':rightType===target?'right':'neutral';
      if(decisive&&tb)side=leftType===target?'left':rightType===target?'right':'neutral';
      if(unresolved==='two'&&!tb&&!(tied.includes(leftType)&&tied.includes(rightType)))side=tied.includes(leftType)?'left':'right';
      const value=side==='neutral'?0:2;
      const controls=this.page.locator('form').filter({has:this.page.locator(`input[name=${prefix}_side][value=${side}]`)}).filter({has:this.page.locator(`input[name=${prefix}_value][value="${value}"]`)}).locator('button');
      await this.press(controls,{twice:guard===0||(stress&&tb)});assert(++guard<=30);
    }
    assert.equal(seen.phase2.size,this.catalog.phase2[winner+'2'].length,'Every normal Phase2 question answered');
    if(unresolved){
      const configured=this.catalog.tb2['T'+winner+'2'].filter(q=>q.pair.every(t=>tied.includes(String(t))));
      assert.equal(seen.tb2.size,configured.length,'Every configured question for the tied types answered');
    }
    this.result.phase2_questions=seen.phase2.size;this.result.tb2_questions=seen.tb2.size;
    assert.equal(this.s.state,'done2');assert.equal(this.s.result_saved,true);assert.equal(String(this.s.log_row.finalVar),remainsUnresolved?'RATKAISEMATON':types[winner]);await this.audit('result');await this.screenshot('result');
    if(remainsUnresolved){assert.match(await this.page.locator('body').innerText(),/Testi ei pystynyt erottamaan yhtä enneagrammityyliä/);assert.deepEqual(this.s.log_row.tied_types.map(String).sort(),[...tied].sort());assert.equal(await this.page.getByText(this.s.log_row.tied_types.join(', '),{exact:true}).count(),1);assert(!/TYYLI \d/.test(await this.page.locator('body').innerText()));}
    else assert.match(await this.page.locator('body').innerText(),new RegExp('TYYLI '+types[winner]));
    return this.sid;
  }
  p2form(side,value){const prefix=this.s.state==='phase2_tb'?'tb2':'choice2';return this.page.locator('form').filter({has:this.page.locator(`input[name=${prefix}_side][value=${side}]`)}).filter({has:this.page.locator(`input[name=${prefix}_value][value="${value}"]`)}).locator('button');}
  async p2selected(side,value){const button=this.p2form(side,value).filter({visible:true});assert.equal(await button.getAttribute('aria-pressed'),'true');assert.match(await button.getAttribute('class'),/selected/);assert.equal(await this.page.locator('button[aria-pressed=true]').filter({visible:true}).count(),1);const color=await button.evaluate(e=>getComputedStyle(e).backgroundColor);const other=await this.page.locator('button[aria-pressed=false]').filter({visible:true}).first().evaluate(e=>getComputedStyle(e).backgroundColor);assert.notEqual(color,other,'Selected answer visibly distinct');if(side==='neutral')assert.equal(await button.evaluate(e=>getComputedStyle(e).webkitTextFillColor),'rgb(34, 34, 34)','Neutral selected text uses readable intended dark color');}
  async p2next(){await this.press(this.form(this.s.state==='phase2_tb'?'tb2_navigate':'phase2_navigate','next'));}
  async p2prev(twice=false){await this.press(this.form(this.s.state==='phase2_tb'?'tb2_navigate':'phase2_navigate','prev'),{twice});}
  async p2go(target){const key=this.s.state==='phase2_tb'?'tb2_index':'q2_index';let guard=0;while(this.s[key]!==target){if(this.s[key]>target)await this.p2prev();else await this.p2next();assert(++guard<40);}}
  async phase2Navigation(winner){
    await this.press(this.page.locator('button[name=continue_phase2]'));
    const ordinary=structuredClone(this.s.blocks2),phase1=structuredClone(this.s.points1);
    const navBelowOptions=async()=>{const layout=await this.page.locator('.case-wrap').evaluate(e=>({nav:e.querySelector('.navigation-controls').getBoundingClientRect().top,options:Math.max(...[...e.querySelectorAll('.opt-left,.opt-right')].map(box=>box.getBoundingClientRect().bottom))}));assert(layout.nav>=layout.options-1,'Navigation appears after the answer options');};
    const integrity=async()=>{assert.deepEqual(this.s.points1,phase1);assert.deepEqual(this.s.blocks2,ordinary);assert(['phase2','phase2_tb','done2'].includes(this.s.state));assert.equal(await this.page.locator('[name=return_to_phase1]').count(),0);const scores=Object.fromEntries(this.s.varCodes.map(c=>[c,0]));for(const [set,answers] of [['blocks2','answers2'],['tb2_set','tb2_answers']])for(const [i,a] of (this.s[answers]||[]).entries())if(a.value)scores[this.s[set][i][a.side+'_var']]+=a.value;assert.deepEqual(this.s.varPoints,scores);if(this.s.state!=='done2'){const tb=this.s.state==='phase2_tb',i=this.s[tb?'tb2_index':'q2_index'],total=this.s[tb?'tb2_set':'blocks2'].length;assert.equal(await this.page.locator('.progress').getAttribute('title'),(tb?Math.floor(i/total*100):Math.round((i+1)/total*100))+'%');}await this.audit('p2-navigation',true);if(this.s.state!=='done2')await navBelowOptions();};
    assert.equal(await this.page.locator('input[name=phase2_navigate][value=prev]').count(),0,'First question cannot go back to Phase1');
    assert.equal(await this.page.locator('input[name=phase2_navigate][value=next]').count(),0,'Unanswered question has no Next');
    for(const [side,value,label] of [['left',2,'strong'],['left',1,'mild'],['neutral',0,'neutral'],['right',1,'mild'],['right',2,'strong'],['neutral',0,'neutral']]){
      await this.press(this.p2form(side,value),{twice:true});await this.p2prev(true);await this.p2selected(side,value);await integrity();await this.screenshot('phase2-selected-'+label);
      // Native keyboard focus/Enter on a navigation control, with the real form token.
      if(value===1&&side==='left'){const next=this.form('phase2_navigate','next');await next.focus();assert.equal(await next.evaluate(e=>document.activeElement===e),true);await Promise.all([this.page.waitForNavigation({waitUntil:'load'}),this.page.keyboard.press('Enter')]);await this.state();await this.p2prev();await this.p2selected(side,value);}
    }
    await this.p2next();while(this.s.state==='phase2'){await integrity();await this.press(this.p2form('neutral',0));}
    assert.equal(this.s.state,'phase2_tb');assert.equal(this.s.tb2_pair.length,3);
    for(const [side,value] of [['left',2],['right',1],['neutral',0],['left',1],['right',2],['neutral',0]]){await this.press(this.p2form(side,value),{twice:true});await this.p2prev(true);await this.p2selected(side,value);await integrity();}
    await this.screenshot('tb2-selected');await this.p2next();const saved=structuredClone(this.s);
    await this.press(this.page.locator('button[name=return_to_phase2]'));assert.equal(this.s.q2_index,17);await this.p2selected('neutral',0);await this.p2prev();await this.p2next();await this.p2next();
    for(const key of ['tb2_answers','tb2_pairs','tb2_set','tb2_index','varPoints'])assert.deepEqual(this.s[key],saved[key]);await integrity();await this.screenshot('restored');
    await this.p2go(0);await this.p2prev();assert.equal(this.s.state,'phase2','First TB previous returns to ordinary Phase2');await this.p2next();assert.equal(this.s.state,'phase2_tb');await this.p2next();
    await this.press(this.page.locator('button[name=return_to_phase2]'));await this.p2go(0);
    const firstText=normalize(await this.page.locator('.case-desc').innerText()),firstLeft=normalize(await this.page.locator('.opt-left .opt-box-content').innerText());const first=this.catalog.phase2[winner+'2'].find(q=>normalize(q.desc)===firstText);assert(first);const target=normalize(first.left_text)===firstLeft?first.left_var:first.right_var;
    await this.press(this.p2form('left',1));assert.equal(this.s.tb2_answers.length,0);assert.equal(this.s.tb2_pairs.length,0);assert(!this.s.tb2_set);await this.p2prev();await this.p2selected('left',1);await integrity();await this.screenshot('invalidated');await this.p2next();
    // A second type receives one point: a real three-way -> two-way change.
    let second=null;while(this.s.q2_index<17){const text=normalize(await this.page.locator('.case-desc').innerText()),left=normalize(await this.page.locator('.opt-left .opt-box-content').innerText());const q=this.catalog.phase2[winner+'2'].find(q=>normalize(q.desc)===text);const lv=normalize(q.left_text)===left?q.left_var:q.right_var,rv=lv===q.left_var?q.right_var:q.left_var;if(!second){second=lv!==target?lv:rv;await this.press(this.p2form(lv===second?'left':'right',1));}else await this.p2next();}
    await this.p2next();assert.equal(this.s.state,'phase2_tb');assert.deepEqual([...this.s.tb2_pair].sort(),[target,second].sort());assert.equal(this.s.tb2_answers.length,0);await integrity();
    await this.press(this.p2form('neutral',0));await this.press(this.page.locator('button[name=return_to_phase2]'));await this.p2go(0);await this.p2selected('left',1);await this.press(this.p2form('left',2));assert(!this.s.tb2_set);await this.p2go(17);await this.p2next();assert.equal(this.s.state,'done2');assert.equal(String(this.s.log_row.finalVar),target);assert.equal(this.s.tb2_pairs.length,0);await integrity();await this.screenshot('result');this.result.expected_final=target;this.result.navigation_choices=['left-2','left-1','neutral-0','right-1','right-2'];this.result.navigation_transitions=['three-way-review-restored','three-way-to-two-way','two-way-to-unique'];return this.sid;
  }
  async feedbackReset(){await this.press(this.page.locator('input[name=show_feedback]'));await this.page.locator('#feedback_text').fill('Eristetty selaintestin palaute Ää.');await this.audit('feedback');await this.screenshot('feedback');await this.press(this.page.locator('button[name=send_feedback]'));assert.equal(this.s.feedback_given,true);
    await this.press(this.page.getByRole('link',{name:'Aloita alusta',exact:true}));assert.equal(this.s.state,'intro');assert(!this.s.result_saved);await this.audit('reset');}
}

async function engineRun(engine){
  const site=fixture('create');const server=net.createServer();await new Promise(r=>server.listen(0,'127.0.0.1',r));const port=server.address().port;await new Promise(r=>server.close(r));site.base=`http://127.0.0.1:${port}`;
  const log=fs.openSync(path.join(site.dir,'server.log'),'a');const worker=cp.spawn(php,['-d',`session.save_path=${site.dir}/data`,'-S',`127.0.0.1:${port}`,'-t',site.dir],{stdio:['ignore',log,log],windowsHide:true});fs.closeSync(log);
  fs.mkdirSync(path.join(__dirname,'.runtime'),{recursive:true});
  const manifest=path.join(__dirname,'.runtime',`active-${process.pid}-${engine}.json`);
  fs.writeFileSync(manifest,JSON.stringify({dir:site.dir,pid:worker.pid,port,engine}));
  let browsers=[];
  try{
    let catalog;for(let i=0;i<60;i++){try{catalog=await (await fetch(site.base+'/catalog.php')).json();break;}catch{await delay(100);}}assert(catalog,'Local PHP starts');
    for(const k of ['phase1','legacy'])catalog[k]=Object.values(catalog[k]).flat();
    const options={headless:true};if(engine==='chrome')options.executablePath=process.env.TEST_CHROME||'C:/Program Files/Google/Chrome/Application/chrome.exe';
    browsers=await Promise.all(Array.from({length:workers},()=>({chrome:chromium,firefox,webkit}[engine]).launch(options)));
    report.engines.push({engine,version:browsers[0].version()});
    const jobs=views.flatMap(view=>p2Navigation?navigationTracks.map(track=>({view,mode:'navigation',track})):modes.map(mode=>({view,mode}))).map((job,id)=>({...job,id}));
    async function runCase({view,mode,id,track},browser){
      const name=`${engine}-${view.name}-${mode}${track?'-'+track:''}`,result={name,engine,viewport:view,mode,passed:false,checked_states:[],screenshots:[],javascript_errors:[],console_errors:[],http_errors:[],dialog_errors:[]};report.cases.push(result);
      const context=await browser.newContext({viewport:{width:view.width,height:view.height},hasTouch:view.touch,isMobile:engine!=='firefox'&&view.touch,deviceScaleFactor:view.touch?2:1});
      await context.route('**/*',route=>{const url=route.request().url();if(url.startsWith(site.base+'/'))return route.continue();report.external_requests.push({case:name,url});return route.fulfill({status:200,contentType:'text/html',body:'<!doctype html><title>External media isolated</title>'});});
      await context.addInitScript(()=>{document.addEventListener('touchstart',()=>sessionStorage.setItem('acceptanceTouches',String(Number(sessionStorage.getItem('acceptanceTouches')||0)+1)),{capture:true,passive:true});});
      const page=await context.newPage();const user=new User(page,context,site,view,name,catalog,result);
      try{
        const index=view.index,pair=pairs[index],direct=['normal','two-way','three-way','decisive','navigation'].includes(mode),winner=mode==='navigation'?track[0]:direct?'ABCD'[index%4]:pair[0];
        const code=mode==='pairwise'||(mode==='legacy'&&view.touch)||mode==='two-way'||mode==='navigation'?'UI'+id:'';const invitationId=code?'browser-'+id:null;
        const unresolved=mode==='two-way'?'two':mode==='decisive'?'decisive':mode==='legacy'||mode==='three-way';
        Object.assign(result,{phase1_pair:direct?null:pair,winner:winner+'1',track:winner+'2',invited:!!code,expected_final:unresolved&&unresolved!=='decisive'?'RATKAISEMATON':types[winner]});
        await user.begin(code,mode==='legacy');const values=Object.fromEntries(['A','B','C','D'].map(g=>[g+'1',direct?(g===winner?6:1):(pair.includes(g)?4:3)]));
        await user.ordinary(values);if(!direct){await user.enterTB(mode==='legacy');await user.navigation(winner,mode==='legacy');}else {assert.equal(user.s.state,'phase2_intro');assert.equal(user.s.track,winner+'2');}
        const sid=mode==='navigation'?await user.phase2Navigation(winner):await user.phase2(winner,unresolved);const data=fixture('inspect',site.dir),rows=data.rows.filter(r=>r.session_id===sid);assert.equal(data.columns,136);assert.equal(rows.length,1);assert.equal(rows[0].final_type,String(user.s.log_row.finalVar));assert.equal(rows[0].user_confidence,'','Unknown type has no obsolete confidence in research CSV');assert.equal(rows[0].invite_id,invitationId||'');if(invitationId)assert.equal(data.invites.find(i=>i.id===invitationId).status,'completed');
        if(view.touch){result.touch_events=Number(await page.evaluate(()=>sessionStorage.getItem('acceptanceTouches')));assert(result.touch_events>0,'Actual emulated touch events observed');}
        if(mode==='normal')await user.feedbackReset();
        result.states=[...user.states];result.ui_issues=user.issues;assert.equal(result.javascript_errors.length,0,'No JS exceptions');assert.equal(result.dialog_errors.length,0,'No dialog transport errors');if(!baseline){assert.equal(result.console_errors.length,0,'No console errors');assert.equal(result.http_errors.length,0,'No HTTP resource errors');assert.equal(user.issues.length,0,'No UI geometry/interaction defects: '+JSON.stringify(user.issues));}
        result.passed=user.issues.length===0&&result.console_errors.length===0&&result.http_errors.length===0;
      }catch(e){result.error=e.stack;result.ui_issues=user.issues;await user.screenshot('exception').catch(()=>{});}
      finally{await context.close();fs.writeFileSync(path.join(out,'matrix.json'),JSON.stringify(report,null,2)+'\n');console.log(`${result.passed?'PASS':'FAIL'} ${name}${result.error?' '+result.error.split('\n')[0]:''}`);}
    }
    let next=0;
    const pool=await Promise.allSettled(browsers.map(async browser=>{while(next<jobs.length)await runCase(jobs[next++],browser);}));
    const rejected=pool.find(r=>r.status==='rejected');if(rejected)throw rejected.reason;
    report.engines.find(e=>e.engine===engine).php_diagnostics=fs.readFileSync(path.join(site.dir,'server.log'),'utf8').split(/\r?\n/).filter(l=>/(?:PHP )?(Warning|Fatal error|Parse error|Notice):|BROWSER_SNAPSHOT_FAILED/.test(l));
    assert.equal(report.engines.find(e=>e.engine===engine).php_diagnostics.length,0,'No PHP diagnostics');
  }finally{await Promise.allSettled(browsers.map(browser=>browser.close()));if(worker.exitCode===null){const stopped=new Promise(r=>worker.once('exit',r));worker.kill();await stopped;}cp.execFileSync(php,[path.join(__dirname,'fixture.php'),'cleanup',site.dir]);fs.unlinkSync(manifest);}
}
(async()=>{const results=await Promise.allSettled(engineNames.map(engineRun));report.harness_errors=results.flatMap((r,i)=>r.status==='rejected'?[{engine:engineNames[i],error:r.reason.stack}]:[]);for(const r of report.harness_errors)console.error(r);report.finished_at=new Date().toISOString();report.passed=report.cases.length===engineNames.length*views.length*(p2Navigation?navigationTracks.length:modes.length)&&report.cases.every(c=>c.passed)&&report.harness_errors.length===0;fs.writeFileSync(path.join(out,'matrix.json'),JSON.stringify(report,null,2)+'\n');console.log(`Browser cases: ${report.cases.filter(c=>c.passed).length}/${report.cases.length} passed`);process.exitCode=report.passed?0:1;})();
