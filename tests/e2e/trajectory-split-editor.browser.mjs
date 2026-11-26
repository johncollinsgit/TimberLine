import {readFile} from 'node:fs/promises';
import assert from 'node:assert/strict';
import {chromium, webkit} from '@playwright/test';

const money = (await readFile('resources/js/trajectory/money.js','utf8')).replaceAll('export function ', 'function ');
const editor = (await readFile('resources/js/trajectory/split-editor.js','utf8')).replace(/^import .*?;$/m,'').replaceAll('export function ','function ').replaceAll('export async function ','async function ');
const css = await readFile('resources/js/trajectory/visual-finance.css','utf8');
for (const engine of [chromium,webkit]) {
  const browser = await engine.launch();
  try {
    for (const width of [390,1100]) {
      const page = await browser.newPage({viewport:{width,height:850}});
      page.setDefaultTimeout(6000);
      page.on('pageerror', error => {throw error;});
      await page.setContent('<meta name="viewport" content="width=device-width, initial-scale=1"><main></main>');
      await page.addStyleTag({content:css});
      await page.addScriptTag({content:money+'\n'+editor+'\nwindow.openSplitEditor=openSplitEditor;'});
      await page.evaluate(async () => {
        const spaces = [{id:1,name:'Household'},{id:2,name:'Studio'}];
        const catalogs = {
          1:{categories:['uncategorized','groceries','christmas'], category_definitions:[{id:11,key:'christmas',name:'Christmas',active:true}], programs:[],budget_accounts:[{id:8,name:'Vacation and Christmas'}]},
          2:{categories:['uncategorized','materials'],category_definitions:[],programs:[{id:9,name:'Workshop',active:true}],budget_accounts:[{id:22,name:'Operations'}]},
        };
        const source = {version:3,source_amount_cents:-10001,source_account:{id:4,name:'Groceries card'},target_spaces:spaces,
          lines:[{space_id:1,amount_cents:-10001,category:'groceries',settlement_status:'none'}]};
        const api=async (path,method,data) => {
          if (method==='PUT') {window.saved=data;return {};}
          return path.endsWith('definitions') ? catalogs[path.split('/')[0]] : source;
        };
        window.openPurchase = async (cents) => {
          source.source_amount_cents = -cents;
          source.lines[0].amount_cents = -cents;
          await window.openSplitEditor({row:{merchant:'Example',date:'2026-11-25',id:1},root:document.querySelector('main'),
          sourceSpace:1,api,endpoint:(path,id)=>id+path,esc:v=>String(v??''),precise:c=>(c/100).toFixed(2),
          categoryLabel:v=>v,notice:()=>{},onChanged:()=>{}});
        };
        await window.openPurchase(10001);
      });
      const dialog=page.getByRole('dialog');
      assert.match(await dialog.innerText(),/Groceries card/);
      await dialog.getByRole('button',{name:'Add part'}).click();
      const second=dialog.locator('fieldset').nth(1);
      await second.getByLabel('Amount ($)',{exact:true}).fill('65');
      assert.equal(await dialog.getByLabel('Remaining amount ($)').inputValue(),'35.01');
      await second.getByLabel('Budget account').selectOption('8');
      await second.getByLabel('Category',{exact:true}).selectOption('christmas');
      await second.getByLabel('Amount ($)',{exact:true}).fill('101');
      assert.equal(await dialog.getByRole('button',{name:'Save allocation'}).isDisabled(),true);
      assert.match(await dialog.getByRole('status').innerText(),/over/);
      await second.getByLabel('Amount ($)',{exact:true}).fill('65');
      await dialog.getByRole('button',{name:'Add part'}).click();
      const third=dialog.locator('fieldset').nth(2);
      await third.getByLabel('Workspace').selectOption('2');
      await third.getByLabel('Enter as').selectOption('percent');
      await third.getByLabel('Percentage (%)').fill('10');
      await third.getByLabel('Budget account').selectOption('22');
      await third.getByLabel('Category',{exact:true}).selectOption('materials');
      await third.getByLabel('Program').selectOption('9');
      await third.getByLabel('Reimbursement',{exact:true}).selectOption('reimbursed');
      await third.getByLabel('Settlement note').fill('Prior documented settlement');
      assert.equal(await dialog.getByLabel('Remaining amount ($)').inputValue(),'25.01');
      assert.equal(await dialog.evaluate(el=>el.scrollWidth<=el.clientWidth+1),true);
      await dialog.getByRole('button',{name:'Save allocation'}).click();
      const data=await page.evaluate(()=>window.saved);
      assert.equal(data.version,3);
      assert.deepEqual(data.lines.map(l=>l.amount_cents),[-2501,-6500,-1000]);
      assert.equal(data.lines[1].budget_account_id,8);
      assert.equal(data.lines[1].category,'christmas');
      assert.equal(data.lines[2].budget_account_id,22);
      assert.equal(data.lines[2].program_record_id,9);
      assert.equal(data.lines[2].settlement_note,'Prior documented settlement');
      await page.evaluate(()=>window.openPurchase(20773));
      const first=dialog.getByRole('group',{name:'Part 1',exact:true});
      const firstAmount=first.getByLabel('Amount ($)',{exact:true});
      assert.equal(await firstAmount.isEditable(),true);
      await firstAmount.fill('65');
      await firstAmount.press('Tab');
      assert.match(await dialog.getByRole('status').innerText(),/142.73 left to assign/);
      assert.equal(await dialog.getByRole('button',{name:'Save allocation'}).isDisabled(),true);
      await first.getByLabel('Allocation note').fill('gifts for christmas');
      await first.getByLabel('Budget account').selectOption('8');
      await first.getByLabel('Category',{exact:true}).selectOption('christmas');
      await dialog.getByRole('button',{name:'Add part'}).press('Enter');
      const remaining=dialog.getByRole('group',{name:'Part 2',exact:true});
      assert.equal(await remaining.getByLabel('Remaining amount ($)').inputValue(),'142.73');
      assert.equal(await remaining.getByLabel('Category',{exact:true}).evaluate(el=>el===document.activeElement),true);
      await remaining.getByLabel('Category',{exact:true}).selectOption('groceries');
      assert.equal(await first.getByLabel('Allocation note').inputValue(),'gifts for christmas');
      await dialog.getByRole('button',{name:'Save allocation'}).press('Enter');
      const gift=await page.evaluate(()=>window.saved.lines);
      assert.deepEqual(gift.map(l=>l.amount_cents),[-6500,-14273]);
      assert.deepEqual(gift.map(l=>l.category),['christmas','groceries']);
      assert.equal(gift[0].settlement_note,'gifts for christmas');
      assert.equal(gift[0].budget_account_id,8);
      await page.close();
    }
  } finally {await browser.close();}
}
console.log('Transaction split editor verified on mobile and desktop in Chromium and WebKit.');
