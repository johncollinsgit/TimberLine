import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {mkdir,writeFile} from 'node:fs/promises';
import {resolve} from 'node:path';
import {chromium,webkit} from 'playwright';
import {createHash,randomUUID} from 'node:crypto';
const fixture=JSON.parse(execFileSync(process.env.ENTERPRISE_TEST_PHP||'php',['tests/Support/proposal-reviewer-ai-render.php'],{encoding:'utf8',maxBuffer:5e6}));
const evidence=resolve(process.env.ENTERPRISE_EVIDENCE||'test-results/enterprise/reviewer-ai');await mkdir(evidence,{recursive:true});
const origin='https://proposal.example.invalid',pageUrl=origin+'/enterprise-owner/service-proposals/FICTIONAL/campus/ask';
const results=[];
for(const [engine,type]of Object.entries({chromium,webkit})){
 const browser=await type.launch({headless:true});
 try{
  const clients=[];
  for(const [index,viewport]of [{width:1440,height:1000},{width:390,height:844}].entries()){
   const context=await browser.newContext({viewport}),page=await context.newPage(),errors=[],calls=[],previews=new Map(),accepted=new Map();
   page.on('pageerror',error=>errors.push(error.message));
   await context.addInitScript(()=>{
    window.syntheticSpeech={captures:[],spoken:[],cancelled:0};
    window.SpeechRecognition=class{constructor(){syntheticSpeech.captures.push(this);}start(){this.onstart?.();}abort(){this.aborted=true;this.onend?.();}emit(text){const item=[{transcript:text}];item.isFinal=true;this.onresult({results:[item]});void this.onend();}};
    window.SpeechSynthesisUtterance=class{constructor(text){this.text=text;}};
    Object.defineProperty(window,'speechSynthesis',{configurable:true,value:{cancel(){syntheticSpeech.cancelled++;},speak(value){syntheticSpeech.spoken.push(value);}}});
   });
   await context.route('**/*',async route=>{
    const req=route.request(),url=new URL(req.url());
    if(req.url()===pageUrl)return route.fulfill({status:200,headers:{'Content-Type':'text/html','Content-Security-Policy':fixture.csp},body:fixture.html});
    if(url.origin===origin && url.pathname.endsWith('/activity'))return route.fulfill({status:204,body:''});
    if(url.origin===origin && /\/campus\/(home|ask)\/data$/.test(url.pathname)){
     const selected=url.pathname.split('/').at(-2),review={...fixture.context,page:selected,urls:{...fixture.context.urls}};
     for(const [key,group]of Object.entries({current:'pages',data:'data_pages',name:'name_pages',comment:'comment_pages'}))review.urls[key]=review.urls[group][selected];
     return route.fulfill({json:{review}});
    }
    if(url.origin!==origin||!url.pathname.startsWith(new URL(pageUrl).pathname+'/ai/'))throw Error('Unexpected network authority: '+url.origin+url.pathname);
    const action=url.pathname.split('/').at(-1),body=req.postDataJSON();calls.push({action,body});
    assert.equal(req.headers()['x-csrf-token'],'synthetic-session-csrf');
    if(action==='preview'){
     const history=(body.history||[]).map(receipt=>{const previous=accepted.get(receipt.preview_id);assert.ok(previous,'Client must own prior accepted receipt');return previous;});
     const value={id:randomUUID(),request_id:body.request_id,digest:createHash('sha256').update(JSON.stringify(body)).digest('hex'),snapshot_digest:createHash('sha256').update(JSON.stringify(body.snapshot)).digest('hex'),question:body.question,snapshot:body.snapshot,maximum_microusd:350000,expires_at:Math.floor(Date.now()/1000)+120,content_expires_at:Math.floor(Date.now()/1000)+600,...(history.length?{history}: {})};
     previews.set(value.id,value);return route.fulfill({json:value});
    }
    const preview=previews.get(body.preview_id);
    if(!preview)return route.fulfill({status:404,json:{error:'unavailable',provider_redispatch:false}});
    assert.equal(body.digest,preview.digest);assert.equal(body.snapshot_digest,preview.snapshot_digest);
    const result={preview_id:preview.id,request_id:preview.request_id,digest:preview.digest,snapshot_digest:preview.snapshot_digest,status:'accepted',content_expired:false,preview,provider_mode:'openai_generated',provider_dispatch:true,provider_simulated:true,answer:'The selected fictional project is active.',refs:[preview.snapshot.sources[0].ref]};
    accepted.set(preview.id,{question:preview.question,answer:result.answer,refs:result.refs});return route.fulfill({json:result});
   });
   await page.goto(pageUrl);await page.getByRole('button',{name:'Start voice',exact:true}).waitFor();
   await page.waitForFunction(()=>!Array.from(document.querySelectorAll('button')).find(node=>node.textContent==='Start voice').disabled);
   if(index===0){
   await page.locator('#home-nav').click();
   await page.locator('[data-tour-open]').first().click();
   await page.locator('[data-tour-scene="6"]').click();
   await page.getByRole('heading',{name:'Ask about fictional work',exact:true}).waitFor();
   await page.locator('[data-tour-close]').click();
   await page.locator('#ask-nav').click();
   }
   await page.getByRole('button',{name:'Start voice',exact:true}).click();
   await page.evaluate(()=>syntheticSpeech.captures.at(-1).emit('Which projects need attention?'));
   await page.getByRole('dialog').filter({hasText:'Review fields before sending'}).waitFor();
   assert.deepEqual(calls.map(row=>row.action),['preview']);assert.equal(await page.evaluate(()=>syntheticSpeech.spoken.length),0);
   assert.equal(await page.locator('#proposal-ai-question').inputValue(),'Which projects need attention?');
   await page.getByRole('button',{name:'Send once to OpenAI',exact:true}).click();
   await page.waitForFunction(()=>syntheticSpeech.spoken.length===1);
   assert.equal(await page.evaluate(()=>syntheticSpeech.captures.length),1,'Microphone must stay off during playback');
   assert.equal(await page.evaluate(()=>syntheticSpeech.spoken[0].text),'The selected fictional project is active.');
   await page.getByRole('button',{name:'Interrupt reply',exact:true}).click();
   await page.evaluate(()=>syntheticSpeech.captures.at(-1).emit('Who owns that project?'));
   await page.getByRole('dialog').filter({hasText:'Review fields before sending'}).waitFor();
   await page.getByText('Previous turns included with this question',{exact:true}).waitFor();
   assert.equal(calls.at(-1).body.history.length,1);assert.equal(calls.filter(call=>call.action==='confirm').length,1);
   await page.getByRole('button',{name:'Keep editing',exact:true}).click();
   await page.locator('#proposal-ai-question').fill('Where is the work?');await page.locator('#proposal-ai-submit').click();
   await page.getByRole('dialog').filter({hasText:'Review fields before sending'}).waitFor();
   const box=await page.locator('.proposal-ai-preview').boundingBox();assert.ok(box.width<=viewport.width&&box.height<=viewport.height);
   await page.screenshot({path:resolve(evidence,engine+'-'+index+'-preview.png')});
   await page.getByRole('button',{name:'Send once to OpenAI',exact:true}).click();await page.locator('.proposal-ai-answer').waitFor();
   assert.deepEqual(errors,[]);assert.equal(calls.filter(call=>call.action==='confirm').length,2);
   assert.equal(await page.evaluate(()=>!!sessionStorage.getItem('everbranch-proposal-ai-receipt:'+'a'.repeat(64))),false);
   clients.push({context,page,calls,receipt:[...previews.values()][0]});
   results.push({engine,client:index,viewport,previews:calls.filter(call=>call.action==='preview').length,confirmed:2,pageErrors:errors,provider:'intercepted synthetic only',speech:'synthetic recognition/TTS; no physical microphone'});
  }
  assert.notEqual(clients[0].receipt.id,clients[1].receipt.id);
  const crossed=await clients[1].page.evaluate(async({url,receipt})=>(await fetch(url+'/ai/status',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'synthetic-session-csrf'},body:JSON.stringify({preview_id:receipt.id,digest:receipt.digest,snapshot_digest:receipt.snapshot_digest})})).status,{url:pageUrl,receipt:clients[0].receipt});
  assert.equal(crossed,404,'Synthetic endpoint must refuse another independent client receipt');
  for(const client of clients)await client.context.close();
 }finally{await browser.close();}
}
await writeFile(resolve(evidence,'browser-results.json'),JSON.stringify({scope:'Actual server-rendered private UI/CSP and compiled entry; intercepted endpoints, synthetic speech, independent contexts + phone viewport. No real unlock/provider/native workspace/physical device acceptance.',results},null,2));
console.log(JSON.stringify({engines:2,independentContexts:4,checks:'sharing preview, same text client, Stop/Interrupt, typed fallback, follow-up preview, viewport, isolated receipts',providerCallsLive:0}));
