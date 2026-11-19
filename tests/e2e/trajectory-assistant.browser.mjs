import {readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {chromium,webkit} from 'playwright';
import {expect} from '@playwright/test';

const script=(await readFile(new URL('../../resources/js/trajectory/assistant-ui.js',import.meta.url),'utf8')).replace("import './assistant-ui.css';",'').replace('export function initTrajectoryAssistant','window.initTrajectoryAssistant = function');
const css=await readFile(new URL('../../resources/js/trajectory/assistant-ui.css',import.meta.url),'utf8');
for(const engine of [chromium,webkit]){
 const browser=await engine.launch({headless:true});
 for(const width of [390,1440]){
  const page=await browser.newPage({viewport:{width,height:900}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',route=>route.fulfill({status:200,contentType:'text/html',body:'<html><body><main id="trajectory"></main></body></html>'}));
  await page.goto('https://trajectory.test/trajectory/');await page.addStyleTag({content:css});await page.addScriptTag({content:script});
  await page.evaluate(()=>{
   window.requests=[];window.changed=0;window.ctx={space_id:7,from:'2027-02-01',through:'2027-02-12',level:'transactions',basis:'spending',transaction_ids:[31],transaction_versions:{31:2},focused_transaction:true};
   window.api=async(path,method='GET',payload)=>{window.requests.push({path,method,payload});if(path.endsWith('/notifications?summary=1'))return{unread_count:1};if(path.endsWith('/notifications'))return{rows:[{id:8,payload:{transaction_id:31,version:2,date:'2027-02-12',merchant:'Example purchase',amount_cents:1234,category:'shopping',category_label:'Shopping',pending:true,remaining_cents:null,needs_purpose:true}}],unread_count:1,preferences:{enabled:true,include_pending:true,show_details:true},push:{ready:false}};if(path.endsWith('/assistant')&&method==='GET')return{rows:[]};if(path.endsWith('/assistant')&&method==='POST'&&payload.question.includes('uncategorized'))return{id:12,reply:'I think this goes in Groceries. Is that right?',review_transaction:{transaction_id:31,version:2,date:'2027-02-12',merchant:'Example grocery purchase',amount_cents:1234,suggestion:{type:'category',key:'groceries',label:'Groceries'},reason:'Four reviewed purchases from this merchant were groceries.'}};if(path.endsWith('/assistant')&&method==='POST')return{id:11,reply:'Saved this transaction to Groceries.',applied:[{transaction_id:31,version:3}],undo:{available:true}};if(path.endsWith('/assistant/11/undo'))return{id:11,reply:'Undid the categorization.',undo:{available:false}};return{ok:true};};
   window.assistant=window.initTrajectoryAssistant({root:document.querySelector('#trajectory'),getContext:()=>window.ctx,api:window.api,endpoint:(path,space)=>`/trajectory/spaces/${space}${path}`,onChanged:()=>{window.changed++;}});
  });
  await page.evaluate(()=>window.assistant.contextChanged());
  expect(await page.evaluate(()=>window.requests.map(row=>row.path))).toEqual(['/trajectory/spaces/7/notifications?summary=1']);
  await expect(page.locator('[data-assistant-badge]')).toHaveText('1');
  await page.getByRole('button',{name:/Ask Everbranch/}).click();await expect(page.getByRole('dialog')).toBeVisible();
  await page.locator('#tr-assistant-question').fill('Move this to groceries');await page.getByRole('button',{name:'Send',exact:true}).click();
  await expect(page.getByText('Saved this transaction to Groceries.')).toBeVisible();await expect(page.getByRole('button',{name:'Undo',exact:true})).toBeVisible();
  await page.getByRole('button',{name:'Undo',exact:true}).click();await expect(page.getByText('Undid the categorization.')).toBeVisible();
  await page.getByRole('button',{name:'Expenses',exact:true}).click();await expect(page.getByRole('button',{name:/Example purchase/})).toBeVisible();
  await page.getByRole('button',{name:/Example purchase/}).click();await expect(page.getByText(/What was this purchase for/)).toBeVisible();
  await page.locator('#tr-assistant-question').fill('groceries');await page.getByRole('button',{name:'Send',exact:true}).click();
  const captured=await page.evaluate(()=>({changed:window.changed,requests:window.requests.filter(r=>r.method==='POST'&&r.path.endsWith('/assistant')),width:document.querySelector('dialog').getBoundingClientRect().width,viewport:window.innerWidth}));
  expect(captured.changed).toBe(3);expect(captured.requests[0].payload.context.focused_transaction).toBe(true);expect(captured.requests[1].payload.context.transaction_versions['31']).toBe(2);expect(captured.width).toBeLessThanOrEqual(captured.viewport);
  await page.locator('#tr-assistant-question').fill('Review uncategorized transactions');await page.getByRole('button',{name:'Send',exact:true}).click();
  await expect(page.locator('.tr-assistant-review')).toContainText('Example grocery purchase');
  const beforeReview=await page.evaluate(()=>window.requests.filter(request=>request.method==='POST').length);
  await page.getByRole('button',{name:'Explain',exact:true}).click();await expect(page.getByText('Four reviewed purchases from this merchant were groceries.',{exact:true})).toBeVisible();
  await page.getByRole('button',{name:'No',exact:true}).click();await expect(page.getByText('What was Example grocery purchase for? Tell me the category or describe its purpose.',{exact:true})).toBeVisible();
  expect(await page.evaluate(()=>window.requests.filter(request=>request.method==='POST').length)).toBe(beforeReview);
  await page.getByRole('button',{name:'Yes · Groceries',exact:true}).click();
  const yes=await page.evaluate(()=>window.requests.filter(request=>request.method==='POST'&&request.path.endsWith('/assistant')).at(-1));
  expect(yes.payload.question).toBe('Move this to category Groceries');expect(yes.payload.context.transaction_ids).toEqual([31]);expect(yes.payload.context.transaction_versions['31']).toBe(2);
  await page.evaluate(async()=>{window.ctx={...window.ctx,space_id:9,scope:'business'};await window.assistant.contextChanged();await window.assistant.openInbox();});
  await expect(page.getByText('Personal expense alerts live in the Personal app',{exact:true})).toBeVisible();
  await expect(page.getByRole('link',{name:'Open Trajectory Personal',exact:true})).toHaveAttribute('href','/trajectory/personal/');
  await expect(page.locator('[data-alert-enabled]')).toHaveCount(0);
  await expect(page.getByRole('button',{name:'Enable phone notifications',exact:true})).toHaveCount(0);
  await page.getByRole('button',{name:'Ask about this business',exact:true}).click();
  await expect(page.locator('#tr-assistant-question')).toBeVisible();
  await page.locator('#tr-assistant-question').fill('Explain business costs');await page.getByRole('button',{name:'Send',exact:true}).click();
  const businessQuestion=await page.evaluate(()=>window.requests.filter(r=>r.method==='POST'&&r.path.endsWith('/assistant')).at(-1));
  expect(businessQuestion.path).toBe('/trajectory/spaces/9/assistant');
  expect(errors).toEqual([]);await page.close();
 }
 const page=await browser.newPage({viewport:{width:390,height:900}}),errors=[];
 page.on('pageerror',error=>errors.push(error.message));
 await page.route('**/*',route=>route.fulfill({status:200,contentType:'text/html',body:'<html><body><main id="trajectory" data-surface="personal"></main></body></html>'}));
 await page.goto('https://trajectory.test/trajectory/personal/');await page.addStyleTag({content:css});await page.addScriptTag({content:script});
 await page.evaluate(()=>{
  window.requests=[];window.notices=[];window.pushState={permission:'default',local:false,registered:false,failCheck:false,failPost:false,enabled:true};
  const subscription={endpoint:'https://fcm.googleapis.com/fcm/send/fictional-status-phone',toJSON(){return{endpoint:this.endpoint,keys:{p256dh:'fictional',auth:'fictional'}};}};
  const registration={scope:'https://trajectory.test/trajectory/personal/',pushManager:{getSubscription:async()=>window.pushState.local?subscription:null,subscribe:async()=>{window.pushState.local=true;return subscription;}}};
  Object.defineProperty(window,'Notification',{configurable:true,value:class{static get permission(){return window.pushState.permission;}static async requestPermission(){if(window.pushState.permission!=='denied')window.pushState.permission='granted';return window.pushState.permission;}}});
  Object.defineProperty(window,'PushManager',{configurable:true,value:class{}});
  Object.defineProperty(navigator,'serviceWorker',{configurable:true,value:{register:async()=>registration,ready:Promise.resolve(registration),getRegistration:async()=>registration}});
  window.ctx={space_id:7,scope:'personal'};
  window.api=async(path,method='GET',payload)=>{
   window.requests.push({path,method,payload});
   if(path.endsWith('/notifications'))return{rows:[],unread_count:0,preferences:{enabled:window.pushState.enabled,include_pending:true,show_details:true},push:{ready:true,public_key:btoa(String.fromCharCode(...new Uint8Array(65).fill(1)))}};
   if(path.endsWith('/notifications/preferences')&&method==='PATCH'){
    if(window.pushState.deferPreferences)return new Promise((resolve,reject)=>{window.finishPreferences=()=>resolve(payload);window.failPreferences=()=>reject(new Error('Previous workspace save failed'));});
    window.pushState.enabled=payload.enabled;return payload;
   }
   if(path.includes('/push-subscriptions?')){if(window.pushState.failCheck)throw new Error('Check failed');return{registered:window.pushState.registered};}
   if(path.endsWith('/push-subscriptions')&&method==='POST'){if(window.pushState.failPost)throw new Error('Unable to save this device. Try again.');window.pushState.registered=true;return{id:1,ready:true};}
   return{ok:true};
  };
  window.assistant=window.initTrajectoryAssistant({root:document.querySelector('#trajectory'),getContext:()=>window.ctx,api:window.api,endpoint:(path,space)=>`/trajectory/spaces/${space}${path}`,notice:message=>window.notices.push(message)});
 });
 const status=page.locator('[data-push-status]');
 const reopen=()=>page.evaluate(()=>window.assistant.openInbox());
 await reopen();await expect(status).toHaveText('Phone notifications are not enabled on this device.');
 await page.getByRole('button',{name:'Enable phone notifications',exact:true}).click();
 await expect(page.getByRole('button',{name:'Phone notifications enabled',exact:true})).toBeDisabled();
 await expect(status).toContainText('Phone notifications are enabled on this device.');
 await reopen();await expect(status).toContainText('Phone notifications are enabled on this device.');
 let requests=await page.evaluate(()=>window.requests);
 expect(requests.filter(row=>row.method==='POST')).toHaveLength(1);
 expect(requests.find(row=>row.path.includes('/push-subscriptions?')).path).toBe(`/trajectory/spaces/7/push-subscriptions?endpoint_hash=${createHash('sha256').update('https://fcm.googleapis.com/fcm/send/fictional-status-phone').digest('hex')}`);

 await page.evaluate(()=>{window.pushState.registered=false;});await reopen();
 await expect(status).toContainText('This device is not registered for your personal workspace.');
 await expect(page.getByRole('button',{name:'Phone notifications enabled',exact:true})).toHaveCount(0);
 await page.evaluate(()=>{window.pushState.failCheck=true;});await reopen();
 await expect(page.getByRole('button',{name:'Retry notification check',exact:true})).toBeEnabled();
 await expect(status).toContainText('Couldn’t confirm this device’s notification setup.');
 await page.evaluate(()=>{window.pushState.failCheck=false;window.pushState.registered=true;});
 await page.getByRole('button',{name:'Retry notification check',exact:true}).click();
 await expect(status).toContainText('Phone notifications are enabled on this device.');
 expect(await page.evaluate(()=>window.requests.filter(row=>row.method==='POST').length)).toBe(1);

 await page.evaluate(()=>{window.pushState.permission='denied';});await reopen();
 await expect(status).toContainText('Notifications are blocked on this device.');
 await expect(page.getByRole('button',{name:'Check notification permission',exact:true})).toBeEnabled();
 await page.evaluate(()=>{window.pushState.permission='granted';});
 await page.getByRole('button',{name:'Check notification permission',exact:true}).click();
 await expect(status).toContainText('Phone notifications are enabled on this device.');
 await page.evaluate(()=>{delete window.PushManager;});await reopen();
 await expect(page.getByRole('button',{name:'Phone notifications unavailable',exact:true})).toBeDisabled();
 await expect(status).toContainText('supported phone/browser');

 await page.evaluate(()=>{Object.defineProperty(window,'PushManager',{configurable:true,value:class{}});window.pushState.local=false;window.pushState.registered=true;});await reopen();
 await expect(status).toHaveText('Phone notifications are not enabled on this device.');
 expect(await page.evaluate(()=>window.requests.filter(row=>row.method==='POST').length)).toBe(1);
 await page.evaluate(()=>{window.pushState.permission='default';window.pushState.registered=false;window.pushState.failPost=true;});await reopen();
 await page.getByRole('button',{name:'Enable phone notifications',exact:true}).click();
 await expect(status).toHaveText('Unable to save this device. Try again.');
 await expect(page.getByRole('button',{name:'Retry enabling notifications',exact:true})).toBeEnabled();
 await expect(page.getByRole('button',{name:'Phone notifications enabled',exact:true})).toHaveCount(0);
 await reopen();await expect(status).toContainText('This device is not registered');
 await page.evaluate(()=>{window.pushState.failPost=false;});
 await page.getByRole('button',{name:'Enable phone notifications',exact:true}).click();
 await expect(status).toContainText('Phone notifications are enabled on this device.');
 await page.evaluate(()=>{window.pushState.enabled=false;});await reopen();
 await expect(status).toContainText('Turn on “Notify me about new purchases” and save to receive alerts.');
 await page.locator('[data-alert-enabled]').check();await page.getByRole('button',{name:'Save preferences',exact:true}).click();
 await expect(status).not.toContainText('Turn on “Notify me');
 await page.evaluate(()=>{window.pushState.deferPreferences=true;window.notices=[];});
 await page.locator('[data-alert-enabled]').uncheck();await page.getByRole('button',{name:'Save preferences',exact:true}).click();
 await expect.poll(()=>page.evaluate(()=>typeof window.finishPreferences)).toBe('function');
 await page.evaluate(async()=>{window.ctx={space_id:8,scope:'personal'};window.pushState.enabled=true;await window.assistant.contextChanged();await window.assistant.openInbox();});
 await expect(status).toContainText('Phone notifications are enabled on this device.');
 await page.evaluate(async()=>{window.finishPreferences();await new Promise(resolve=>setTimeout(resolve,0));});
 await expect(page.locator('[data-alert-enabled]')).toBeChecked();
 await expect(status).not.toContainText('Turn on “Notify me');
 expect(await page.evaluate(()=>window.notices)).toEqual([]);
 const savedRequests=await page.evaluate(()=>window.requests.filter(row=>row.method==='PATCH'));
 expect(savedRequests.at(-1).path).toBe('/trajectory/spaces/7/notifications/preferences');
 await page.locator('[data-alert-enabled]').uncheck();await page.getByRole('button',{name:'Save preferences',exact:true}).click();
 await page.evaluate(async()=>{window.ctx={space_id:9,scope:'business'};await window.assistant.contextChanged();await window.assistant.openInbox();window.failPreferences();await new Promise(resolve=>setTimeout(resolve,0));});
 await expect(page.getByText('Personal expense alerts live in the Personal app',{exact:true})).toBeVisible();
 await expect(page.getByText('Previous workspace save failed',{exact:true})).toHaveCount(0);
 expect(errors).toEqual([]);await page.close();await browser.close();
}
console.log('Ask Everbranch: categorization, Undo, inbox/business handoff, responsive dialog and device-specific push status/permission/error/reopen checks passed Chromium/WebKit.');
