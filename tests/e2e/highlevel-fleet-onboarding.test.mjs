import test from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFileSync, mkdirSync} from 'node:fs';
import {chromium, webkit} from '@playwright/test';

const manifest=JSON.parse(readFileSync('public/build/manifest.json','utf8'));
const entry=manifest['resources/js/highlevel/fleet.js'];
const styleFiles=node=>[...(node.css||[]),...(node.imports||[]).flatMap(key=>styleFiles(manifest[key]))];
const styles=[...new Set(styleFiles(entry))];
async function fixture(browserType, options, check) {
    let origin, parentOrigin;
    const state = {connected:false, selected:[], approved:false, devices:2, deviceError:false, crmError:false, contexts:true, syncs:0, writes:[], scope:'a'.repeat(64), collection:false, historyRequests:0, ...options};
    const server = createServer(async(req,res)=>{
        res.setHeader('Content-Type','application/json');
        if(req.url === '/parent') {
            res.setHeader('Content-Type','text/html');
            return res.end(`<meta name="viewport" content="width=device-width,initial-scale=1"><iframe title="Fleet" src="${origin}/launch" style="width:100%;height:100vh;border:0"></iframe><script>window.respond=${state.contexts};addEventListener('message',e=>{if(window.respond && e.origin==='${origin}' && e.data.message==='REQUEST_USER_DATA')e.source.postMessage({message:'REQUEST_USER_DATA_RESPONSE',payload:'encrypted-'+Math.random()},e.origin)})</script>`);
        }
        if(req.url === '/launch') {
            res.setHeader('Content-Type','text/html');
            return res.end(`<meta name="viewport" content="width=device-width,initial-scale=1"><div id="fleet-app"></div><script id="fleet-config" type="application/json">${JSON.stringify({parentOrigins:[parentOrigin]})}</script>${styles.map(css=>`<link rel="stylesheet" href="/build/${css}">`).join('')}<script type="module" src="/build/${entry.file}"></script>`);
        }
        if(req.url.startsWith('/build/')) {
            res.setHeader('Content-Type',req.url.endsWith('.css')?'text/css':'application/javascript');
            return res.end(readFileSync(`public${req.url}`));
        }
        if(req.url === '/oauth/approve') {
            res.setHeader('Content-Type','text/html');
            return res.end('<a href="/oauth/complete">Authorize fictional Bouncie</a><a href="/oauth/cancel">Cancel</a>');
        }
        if(['/oauth/complete','/oauth/cancel'].includes(req.url)) {
            state.connected = req.url === '/oauth/complete';
            res.setHeader('Content-Type','text/html');
            return res.end(`<script>opener.postMessage({message:'${state.connected?'EVERBRANCH_BOUNCIE_CONNECTED':'EVERBRANCH_BOUNCIE_CANCELLED'}'},location.origin)</script>`);
        }
        if(req.url === '/crm/fleet/session/challenge') return res.end(JSON.stringify({challenge:'c'.repeat(64)}));
        if(req.url === '/crm/fleet/session/exchange') return res.end(JSON.stringify({token:'t'.repeat(80),expires_at:new Date(Date.now()+900000).toISOString(),session_scope:state.scope}));
        if(req.url.startsWith('/crm/fleet/api/')) {
            assert.equal(req.headers.authorization, `Bearer ${'t'.repeat(80)}`);
            assert.equal(req.headers.cookie, undefined);
            assert.equal(req.headers['x-everbranch-parent-origin'], parentOrigin);
            let body='';for await(const chunk of req)body+=chunk;
            const payload=body?JSON.parse(body):{};
            const route=req.url.split('/api/')[1];
            if(route==='bootstrap') return res.end(JSON.stringify({
                workspace:{name:'Fictional service fleet'},
                connection:{status:state.connected?'connected':'disconnected',label:state.connected?'Fictional Bouncie account':null,health:{status:state.connected?'connected':'disconnected'}},
                vehicles:state.selected.map((id,i)=>({id:i+1,device_id:id,name:`Van ${i+1}`,location:null})),
                settings:{vehicle_limit:100,retention_days:14,policy_version:'v1',policy_sha256:state.approved?'a'.repeat(64):null,policy_approved:state.approved,tracking_enabled:state.approved},
                subscription:{has_access:true,required:false,setup_allowed:true,collection_active:state.collection},support_email:'support@example.test',map_key:null,
                operations:{jobs:state.syncs && !state.crmError?[{id:10,details:{title:'Panel inspection',source:'calendar',crm_status:'confirmed',reference:'event-1'}}]:[],trips:state.samplePath?[{id:301,device_id:1,details:{distance_miles:3,started_at:new Date(Date.now()-7200000).toISOString(),ended_at:new Date(Date.now()-3600000).toISOString()},candidates:[],route_review:'baseline_needed'}]:[],job_source:state.syncs?{type:'automatic',sources:[{name:'Service calendar'}],synced_at:new Date().toISOString()}:null},
            }));
            if(route==='operations/sync') {
                state.syncs++;
                assert.equal(payload.type,'automatic');assert.equal(payload.source_id,undefined);
                if(state.crmError){res.statusCode=503;return res.end(JSON.stringify({message:'CRM is temporarily unavailable.'}));}
                return res.end(JSON.stringify({saved:true,count:1}));
            }
            if(route==='bouncie/connect') return res.end(JSON.stringify({url:`${origin}/oauth/approve`}));
            if(route==='operations/trips/301/path')return res.end(JSON.stringify({points:[{lat:34.8,lng:-82.3},{lat:34.9,lng:-82.4}],trips:[],message:'Full recorded trip geometry is unavailable. Retained GPS samples are shown as isolated dots, not a driving route.'}));
            if(route.startsWith('operations/vehicles/') && route.includes('/history?')) {
                state.historyRequests++;
                const id=Number(route.split('/')[2]);
                return res.end(JSON.stringify({status:'ready',trips:[{id:'trip-'+id,segments:[[[34.8,-82.3],[34.801,-82.301],[34.802,-82.302]]],trace_segments:[true]}],samples:[]}));
            }
            if(route==='devices' && req.method==='GET') {
                if(state.deviceError){res.statusCode=503;return res.end(JSON.stringify({message:'Bouncie is temporarily unavailable. Your selections are preserved.'}));}
                return res.end(JSON.stringify({devices:Array.from({length:state.devices},(_,i)=>({id:`imei-${i+1}`,name:`Van ${i+1}`,selected:state.selected.includes(`imei-${i+1}`)}))}));
            }
            if(route==='devices' && req.method==='PUT') state.selected=payload.devices;
            if(route==='settings') state.approved=Boolean(payload.approval_confirmed && payload.tracking_enabled);
            state.writes.push({route,payload});
            return res.end(JSON.stringify({saved:true}));
        }
        res.statusCode=404;res.end('{}');
    });
    await new Promise(r=>server.listen(0,'0.0.0.0',r));
    origin=`http://127.0.0.1:${server.address().port}`;parentOrigin=`http://localhost:${server.address().port}`;
    const browser=await browserType.launch().catch(async(error)=>{await new Promise(r=>server.close(r));throw error;});
    try {
        const page=await browser.newPage({viewport:{width:1280,height:1000}});
        const errors=[];page.on('pageerror',e=>errors.push(e.message));
        await page.goto(`${parentOrigin}/parent`);
        await check({page,frame:page.frameLocator('iframe'),state});
        assert.deepEqual(errors,[]);
        await page.close();
    } finally {await browser.close();await new Promise(r=>server.close(r));}
}
async function shot(page,name,browserType) {
    if(process.env.HIGHLEVEL_SCREENSHOT_DIR && browserType===chromium){mkdirSync(process.env.HIGHLEVEL_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:`${process.env.HIGHLEVEL_SCREENSHOT_DIR}/${name}.png`,fullPage:true});}
}
for(const browserType of [chromium,webkit]) {
    test(`${browserType.name()} guides Bouncie sign-in through vehicles, settings, activation and automatic CRM work`, {timeout:60000}, async()=>{
        await fixture(browserType,{},async({page,frame,state})=>{
            await frame.getByRole('heading',{name:'Connect Bouncie to get started'}).waitFor();
            const pop=page.waitForEvent('popup');await frame.getByRole('button',{name:'Connect Bouncie',exact:true}).click();
            const popup=await pop;
            await frame.getByText('Finish signing in to Bouncie',{exact:true}).waitFor();
            await popup.getByText('Authorize fictional Bouncie').click();
            await frame.getByRole('heading',{name:'Choose up to 100 vehicles'}).waitFor();
            await shot(page,'onboarding-vehicles',browserType);
            assert.equal(await frame.getByRole('button',{name:'Save vehicle selection'}).isDisabled(),true);
            await frame.getByLabel('Van 1').check();
            await frame.getByRole('button',{name:'Save vehicle selection'}).click();
            await frame.getByRole('heading',{name:'Next: review your tracking settings'}).waitFor();
            await frame.locator('textarea[name="policy_text"]').fill('Fictional approved company vehicle policy');
            await frame.locator('[name="approval_reference"]').fill('Fictional owner approval');
            await frame.locator('[name="approval_confirmed"]').check();
            await frame.locator('[name="tracking_enabled"]').check();
            await shot(page,'onboarding-settings',browserType);
            await frame.getByRole('button',{name:'Save settings',exact:true}).click();
            await frame.getByRole('heading',{name:'Setup saved · activation pending'}).waitFor();
            assert.match(await frame.locator('#setup-panel').innerText(),/do not need to reconnect/);
            assert.equal(await frame.getByRole('link',{name:'Contact support for activation'}).count(),1);
            await shot(page,'onboarding-activation',browserType);
            await frame.getByRole('button',{name:'Jobs',exact:true}).click();
            await frame.locator('.ops-item summary').filter({hasText:'Panel inspection'}).waitFor();
            assert.equal(await frame.locator('#source-form').count(),0);
            assert.ok(state.syncs>=1);
            assert.equal(state.writes.filter(w=>w.route==='devices').length,1);
            await shot(page,'onboarding-crm',browserType);
            await page.setViewportSize({width:390,height:844});
            assert.equal(await frame.locator('.shell').evaluate(el=>el.scrollWidth<=el.clientWidth),true);
            await frame.getByRole('button',{name:'Fleet',exact:true}).click();
            await shot(page,'onboarding-mobile',browserType);
        });
    });
    test(`${browserType.name()} handles empty vehicles, provider failure and cancelled sign-in without completing setup`,{timeout:60000},async()=>{
        await fixture(browserType,{connected:true,devices:0},async({page,frame,state})=>{
            await frame.getByRole('heading',{name:'No vehicles returned by Bouncie'}).waitFor();
            assert.equal(await frame.locator('#device-form').count(),0);
            state.deviceError=true;
            await frame.getByRole('button',{name:'Refresh vehicle list'}).click();
            await frame.getByText('Bouncie is temporarily unavailable. Your selections are preserved.',{exact:true}).waitFor();
            state.devices=2;state.deviceError=false;
            await frame.getByRole('button',{name:'Refresh vehicle list'}).click();
            await frame.getByLabel('Van 1').waitFor();
            await frame.getByText('Manage Bouncie connection',{exact:true}).click();
            const pop=page.waitForEvent('popup');await frame.getByRole('button',{name:'Reconnect Bouncie',exact:true}).click();
            const popup=await pop;await popup.getByText('Cancel',{exact:true}).click();
            await frame.getByText(/Bouncie sign-in was not completed/).waitFor();
            assert.equal(state.writes.length,0);
            assert.equal(await frame.getByRole('heading',{name:'Next: choose your vehicles'}).count(),1);
            await shot(page,'onboarding-cancel',browserType);
        });
    });
    test(`${browserType.name()} recovers missing CRM context and shows failed CRM work reads`,{timeout:60000},async()=>{
        await fixture(browserType,{contexts:false,connected:true,crmError:true},async({page,frame,state})=>{
            await frame.getByRole('heading',{name:'Open Fleet from your CRM'}).waitFor({timeout:20000});
            assert.match(await frame.locator('.launch-card').innerText(),/No separate Fleet password/);
            await shot(page,'onboarding-signin-retry',browserType);
            await page.evaluate(()=>{window.respond=true;});
            await frame.getByRole('button',{name:'Try CRM sign-in again'}).click();
            await frame.getByRole('heading',{name:'Choose up to 100 vehicles'}).waitFor();
            await frame.getByRole('button',{name:'Jobs',exact:true}).click();
            await frame.getByText(/Jobs could not refresh/).waitFor();
            state.crmError=false;
            await frame.getByRole('button',{name:'Refresh jobs'}).click();
            await frame.locator('.ops-item summary').filter({hasText:'Panel inspection'}).waitFor();
            assert.equal(state.writes.length,0);
        });
    });
    test(`${browserType.name()} never joins sparse trip samples into an invented road route`,async()=>{
        await fixture(browserType,{connected:true,selected:['imei-1'],approved:true,collection:true,samplePath:true},async({frame})=>{
            await frame.getByRole('button',{name:'Trips & routes',exact:true}).click();
            await frame.locator('.ops-item summary').first().click();
            await frame.getByRole('button',{name:'View route'}).click();
            await frame.getByText('Full recorded trip geometry is unavailable. Retained GPS samples are shown as isolated dots, not a driving route.',{exact:true}).waitFor();
            assert.equal(await frame.locator('.trip-map .leaflet-overlay-pane path').count(),2);
        });
    });
    test(`${browserType.name()} loads several selected live routes and never saves selection after CRM account changes`,{timeout:60000},async()=>{
        await fixture(browserType,{connected:true,selected:['imei-1','imei-2'],approved:true,collection:true},async({page,frame,state})=>{
            await frame.getByText('1 recorded trip',{exact:true}).first().waitFor();
            assert.equal(state.historyRequests,2);
            assert.equal(await frame.locator('.leaflet-overlay-pane path').count(),2);
            await frame.getByRole('checkbox',{name:'Show Van 1 on map',exact:true}).uncheck();
            assert.equal(await frame.locator('.leaflet-overlay-pane path').count(),1);
            await frame.getByRole('checkbox',{name:'Show Van 1 on map',exact:true}).check();
            assert.equal(state.historyRequests,2);
            await frame.getByRole('button',{name:'Connection',exact:true}).click();
            await frame.getByRole('button',{name:'Choose vehicles',exact:true}).click();
            state.scope='b'.repeat(64);
            await page.frames()[1].evaluate(()=>{const original=Date.now;Date.now=()=>original()+20*60000;});
            await frame.getByRole('button',{name:'Save vehicle selection'}).click();
            await frame.getByRole('heading',{name:'Open Fleet from your CRM'}).waitFor();
            assert.match(await frame.locator('.launch-card').innerText(),/account changed/);
            assert.equal(state.writes.filter(w=>w.route==='devices').length,0);
        });
    });

}
