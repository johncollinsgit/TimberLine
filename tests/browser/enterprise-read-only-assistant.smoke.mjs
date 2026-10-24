/** Local fictional UI contract: actual markup/script/styles, stubbed read API, no production data. */
import assert from 'node:assert/strict';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
const {chromium, webkit} = await import(pathToFileURL(resolve(process.env.EVERBRANCH_PLAYWRIGHT_MODULE || 'node_modules/playwright/index.mjs')).href);
const [markup, script, nextStyles, campusStyles, tourSource] = await Promise.all([
    'resources/views/enterprise/read-only-assistant.blade.php', 'resources/views/enterprise/read-only-assistant-script.blade.php',
    'resources/views/enterprise/next-styles.blade.php', 'resources/views/enterprise/campus-styles.blade.php', 'resources/js/enterprise/reviewer/workspace-tour.js',
].map(path => readFile(path, 'utf8')));
const evidence = resolve(process.env.ENTERPRISE_EVIDENCE || 'test-results/enterprise/read-only-assistant');
await mkdir(evidence, {recursive:true});
const origin = 'https://coo-fixture.invalid';
const fixtureResult = {
    mode:'deterministic_records',provider_dispatch:false,scope:{user_id:'u1',tenant_id:'t1',area_id:'a1'},reply:'Checked recorded costs and estimates', sample_data:true, comparisons:[{project_title:'Synthetic roof repairs',project_url:origin+'/enterprise-campus/?tenant=synthetic&section=projects&selected=p1',currency:'USD',estimate_minor:1000,recorded_cost_minor:1500,difference_minor:500,estimate_refs:['cost:e1'],recorded_cost_refs:['cost:c1'],comparison_label:'Checked subtotals; not an approved-budget variance.'}],
    jobs:[], sources:[{ref:'cost:e1',title:'Synthetic roof estimate',url:origin+'/enterprise-campus/?tenant=synthetic&section=contractors&selected=e1',facts:{amount_minor:1000,currency:'USD',basis:'estimate'}},{ref:'cost:c1',title:'Synthetic roof recorded cost',url:origin+'/enterprise-campus/?tenant=synthetic&section=contractors&selected=c1',facts:{amount_minor:1500,currency:'USD',basis:'recorded_cost'}}],
    as_of:'2026-10-23T00:00:00Z', coverage:'Checked sample only, not a total report.',notice:'No approved budget or staffing capacity is connected. Nothing was sent or changed.'
};
const boot = `
 const $=id=>document.getElementById(id);let area='a1',section='ask';
 const presentation={sample_data:true},viewer='u1',workspace='t1';let data={sample_data:true,features:{read_only_assistant:true,read_only_costs:true,briefing:true,resources:true},buildings:{items:[{id:'b1',title:'Synthetic North Hall'}],coverage:'Current saved buildings only.'}};
 const transport={mode:'private',href:href=>href},nextBase='/enterprise',nextQuery=()=>'?tenant=synthetic';
 const calls=[];let release;const api=(url,body)=>{calls.push({url,body});return new Promise(resolve=>release=resolve)};
 window.fixture={calls,complete:()=>release(${JSON.stringify(fixtureResult)}),reset:()=>resetReadOnlyAssistant(),roleLoss:()=>{resetReadOnlyAssistant();data.features.read_only_costs=false;renderReadOnlyAssistant()},tour:()=>{const tour=EverbranchWorkspaceTour.create({runtime:'private',enabled:true,sample_data:true,identity:{viewer:'u1',workspace:'t1'}});tour.update({transport,area,data:{...data,areas:[{id:'a1',name:'Synthetic Operations'}],projects:[],requests:[]}});return tour.describe(6,{})}};
 `;
const html = `<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fictional assistant QA</title>${campusStyles.replace(/\{\{--[\s\S]*?--\}\}/g,'')}<style>${nextStyles}.stack{display:grid;gap:12px}.stack label{display:grid;gap:6px}.panel{padding:22px}[hidden]{display:none!important}.fixture{max-width:1200px;margin:auto;padding:20px}</style><body><main class="fixture">${markup}</main><script>${tourSource}</script><script>${boot}\n${script}\nrenderReadOnlyAssistant();</script></body></html>`;
const records = [];
for (const [engine, launcher] of Object.entries({chromium,webkit})) {
    const browser = await launcher.launch({headless:true});
    try {
        for (const [name,viewport,touch] of [['desktop',{width:1440,height:1000},false],['ipad',{width:820,height:1180},true],['mobile',{width:390,height:844},true],['reflow',{width:720,height:450},false]]) {
            const context = await browser.newContext({viewport,hasTouch:touch});
            const page = await context.newPage(), errors=[];page.on('pageerror',error=>errors.push(error.message));
            await page.route(origin+'/**',route=>route.fulfill({contentType:'text/html',body:html}));
            await page.goto(origin+'/enterprise-campus/');
            await page.locator('#read-only-assistant').waitFor({state:'visible'});
            assert.equal(await page.evaluate(()=>fixture.calls.length),0,'No automatic retrieval');
            await page.locator('#read-only-intent').selectOption('building');
            await page.locator('#read-only-building').selectOption('b1');
            await page.locator('#read-only-submit').focus();await page.keyboard.press('Enter');
            assert.deepEqual(await page.evaluate(()=>fixture.calls[0].body),{area_id:'a1',intent:'building',building_id:'b1'});
            await page.evaluate(()=>fixture.reset());await page.evaluate(()=>fixture.complete());
            assert.equal(await page.locator('#read-only-answer').textContent(),'','Reset suppresses late answer');
            await page.evaluate(()=>{data.features.read_only_costs=true;renderReadOnlyAssistant()});
            await page.locator('#read-only-intent').selectOption('costs');
            await page.locator('#read-only-submit').click();await page.evaluate(()=>fixture.complete());
            await page.getByText('Difference: 500 USD minor units.',{exact:false}).waitFor();
            await page.locator('#read-only-answer summary').click();
            assert.equal(await page.getByRole('link',{name:'View source: Synthetic roof recorded cost',exact:true}).getAttribute('href'),origin+'/enterprise-campus/?tenant=synthetic&section=contractors&selected=c1');
            const geometry=await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,width:innerWidth,controls:[...document.querySelectorAll('#read-only-form button,#read-only-form select')].filter(node=>node.checkVisibility()).map(node=>({height:node.getBoundingClientRect().height,width:node.getBoundingClientRect().width}))}));
            assert.ok(geometry.scroll<=geometry.width+1,'No horizontal overflow');
            assert.ok(geometry.controls.every(control=>control.height>=44),engine+' '+name+' controls remain touch reachable '+JSON.stringify(geometry));
            const tour=await page.evaluate(()=>fixture.tour());assert.equal(tour.target,'read-only-assistant');assert.match(tour.text,/deterministic record checks use no AI model/);
            await page.screenshot({path:resolve(evidence,engine+'-'+name+'.png'),fullPage:true});
            await page.evaluate(()=>fixture.roleLoss());
            assert.equal(await page.locator('#read-only-answer').textContent(),'');
            assert.equal(await page.locator('#read-only-cost-option').evaluate(node=>node.disabled),true,engine+' '+name+' financial option property');
            assert.deepEqual(errors,[]);
            records.push({engine,name,geometry,checks:'keyboard submit, ID-only request, reset/late response, cost math label, source URL, tour, financial-role loss'});
            await context.close();
        }
        const page = await browser.newPage({viewport:{width:1440,height:1000}});
        await page.setContent(html);
        await page.evaluate(()=>{document.getElementById('read-only-assistant').hidden=false;document.getElementById('read-only-building-field').hidden=false;});
        for (const [name,forcedColors,colorScheme] of [['normal-light','none','light'],['normal-dark','none','dark'],['forced-light','active','light'],['forced-dark','active','dark']]) {
            await page.emulateMedia({forcedColors,colorScheme});
            const controls=[];
            for (const id of ['read-only-intent','read-only-building']) {
                const control=page.locator('#'+id);
                const paint=await control.evaluate(async node=>{
                    const s=getComputedStyle(node),source=s.backgroundImage;
                    const forced=matchMedia('(forced-colors:active)').matches;let arrow=null;
                    if(!forced){
                        if (!source.startsWith('url(')) throw new Error('A painted SVG disclosure is required outside forced colors');
                        const image=new Image();image.src=source.slice(5,-2);await image.decode();
                        const canvas=document.createElement('canvas');canvas.width=12;canvas.height=8;const ctx=canvas.getContext('2d');ctx.drawImage(image,0,0);
                        const pixels=ctx.getImageData(0,0,12,8).data;arrow=[0,0,0,0];
                        for(let i=0;i<pixels.length;i+=4) if(pixels[i+3]>arrow[3]) arrow=[...pixels.slice(i,i+4)];
                    }
                    const caret=()=>{const p=getComputedStyle(node.parentElement,'::after');return{content:p.content,color:p.borderBottomColor,width:p.width,height:p.height,pointerEvents:p.pointerEvents}};
                    const before={color:s.color,border:s.borderColor,caret:caret()};node.disabled=true;
                    const disabled={color:getComputedStyle(node).color,border:getComputedStyle(node).borderColor,caret:caret()};node.disabled=false;
                    return{height:node.getBoundingClientRect().height,scheme:s.colorScheme,background:s.backgroundColor,image:source,arrow,before,disabled,forced};
                });
                assert.ok(paint.height>=44);if(!paint.forced)assert.ok(paint.arrow[3]>200);
                if(forcedColors==='none') assert.equal(paint.scheme,'light','Normal dark preference retains the approved light control palette');
                if(paint.forced){assert.equal(paint.image,'none');assert.equal(paint.before.caret.content,'""');assert.equal(paint.before.caret.pointerEvents,'none');assert.notEqual(paint.before.caret.color,paint.disabled.caret.color);assert.notEqual(paint.before.color,paint.disabled.color);assert.notEqual(paint.before.border,paint.disabled.border);}
                const path=resolve(evidence,engine+'-'+name+'-'+id+'.png');const png=await control.screenshot({path});
                const raster=await page.evaluate(async imageSource=>{
                    const image=new Image();image.src=imageSource;await image.decode();
                    const canvas=document.createElement('canvas');canvas.width=image.width;canvas.height=image.height;const ctx=canvas.getContext('2d');ctx.drawImage(image,0,0);
                    const pixel=(x,y)=>[...ctx.getImageData(x,y,1,1).data].slice(0,3);
                    const bg=pixel(image.width-8,Math.floor(image.height/2));
                    const lum=rgb=>rgb.map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4}).reduce((sum,v,i)=>sum+v*[.2126,.7152,.0722][i],0);
                    const ratio=rgb=>(Math.max(lum(bg),lum(rgb))+.05)/(Math.min(lum(bg),lum(rgb))+.05);
                    let caret=bg;
                    for(let x=image.width-24;x<image.width-12;x++)for(let y=Math.floor(image.height/2)-4;y<Math.floor(image.height/2)+4;y++){const p=pixel(x,y);if(ratio(p)>ratio(caret))caret=p;}
                    return{backgroundPixel:bg,caretPixel:caret,contrast:ratio(caret),measurement:'Actual CSS-painted selector screenshot; not standalone decoded SVG'};
                },'data:image/png;base64,'+png.toString('base64'));
                assert.ok(raster.contrast>=3,JSON.stringify({engine,name,id,paint,raster}));
                controls.push({id,path,...paint,...raster});
            }
            records.push({engine,name,controls,checks:'Actual native selector paint, SVG in normal palette and CSS caret in forced colors, light default, normal/forced light/dark contrast, disabled GrayText, 44px target'});
        }
        await page.close();
    } finally {await browser.close();}
}
await writeFile(resolve(evidence,'verification.json'),JSON.stringify({fixture:'Fictional local UI with actual product partials; read API stubbed; no live provider or production acceptance.',records},null,2));
console.log(`PASS ${records.length} actual-markup Chromium/WebKit viewport/palette cases. Fictional local API only.`);
