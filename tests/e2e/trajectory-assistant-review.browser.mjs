import {devices} from 'playwright';
import {expect} from '@playwright/test';

// Exercise saved responses from older releases and the current turn protocol in the shipped dialog.
export async function verifyCategoryReview(browser, engineName, script, css) {
  const device = engineName === 'webkit' ? devices['iPhone 13'] : {viewport:{width:1440,height:900}};
  for (const scenario of ['legacy', 'saved-undo', 'saved-proposal', 'stale-proposal', 'fresh']) {
    const page = await browser.newPage(device);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    try {
      await page.route('https://trajectory.test/**', route => route.fulfill({
        contentType:'text/html',
        body:'<meta name="viewport" content="width=device-width,initial-scale=1"><main id="trajectory" data-surface="personal"></main>',
      }));
      await page.goto('https://trajectory.test/trajectory/personal/');
      await page.addStyleTag({content:css});
      await page.addScriptTag({content:script});
      await page.evaluate(scenario => {
        const categories = Array.from({length:60}, (_, i) => ({
          type:'category', key:'fixture_category_'+i, label:'Fixture category '+i,
        }));
        const review = {transaction_id:82, version:4, merchant:'Fictional Market', date:'2026-11-18', amount_cents:1743, suggestion:null, reason:'No consistent purpose yet.'};
        const context = {space_id:7, scope:'personal', from:'2026-11-01', through:'2026-11-20', level:'overview', basis:'spending'};
        const focused = {...context, focused_transaction:true, transaction_ids:[82], transaction_versions:{82:4}};
        const ambiguousChoices = [{type:'category', key:'garden', label:'Garden'}, {type:'program', id:77, key:'garden', label:'Garden'}];
        const legacy = {id:5, question:'Move this to the fall project', context:focused,
          response:{reply:'Which exact category or program should I use? Choose one from this workspace; no transaction has changed.', clarification:true, choices:categories}};
        const savedUndo = {id:6, question:'Use Groceries', context:focused,
          response:{reply:'Saved to Groceries.', applied:[{transaction_id:82, version:5}], undo:{available:true}}};
        const proposal = {id:7, question:'Split this purchase', context:focused,
          response:{reply:'Review this split before saving.', proposal:{requires_review:true,
            actions:[{transaction_id:82, version:4, destination:{type:'split', label:'Groceries and household'}}]}}};
        const history = {fresh:[], legacy:[legacy], 'saved-undo':[legacy, savedUndo],
          'saved-proposal':[legacy, proposal], 'stale-proposal':[legacy, proposal]}[scenario];
        const current = scenario === 'stale-proposal' ? {...focused, transaction_versions:{82:5}}
          : scenario === 'saved-proposal' ? focused : context;
        const unknown = {reply:'What was this purchase for?', mode:'Category review', needs_destination:true, review_transaction:review};
        window.askRequests = [];
        window.transferOpens = 0;
        let turn = 10;
        const api = async (path, method = 'GET', payload) => {
          window.askRequests.push({path, method, payload});
          if (method === 'GET' && path.endsWith('/assistant')) return {rows:history};
          if (method === 'GET' && path.endsWith('/attribution/definitions')) return {
            space_id:7, categories:[...categories.map(c => c.key), 'groceries', 'garden'],
            category_definitions:categories.map((c, i) => ({id:i+1, key:c.key, name:c.label, direction:'expense', active:true, parent_record_id:null})),
            programs:[{id:77, key:'garden', name:'Garden', active:true}],
          };
          if (method === 'POST' && path.endsWith('/assistant')) {
            const question = payload.question.toLowerCase();
            const id = ++turn;
            if (question.includes('uncategorized') || question.includes('review next')) return {id, ...unknown};
            if (question === 'garden') return {id, ...unknown, reply:'Which category or program should I use?', choices:ambiguousChoices, clarification:true};
            if (question === 'supplies for tomorrow') return {id, ...unknown, reply:'Which category or program should I use?', clarification:true};
            if (question.includes('groceries')) return {id, reply:'Saved to Groceries.', applied:[{transaction_id:82,version:5}], undo:{available:true}};
            if (question.includes('transfer')) return {id, reply:'Review matching transfers.', open_transfer_review:true};
          }
          if (method === 'POST' && path.endsWith('/undo')) return {
            id:Number(path.split('/').at(-2)), reply:'Change undone.', undo:{available:false},
          };
          if (method === 'POST' && path.endsWith('/apply')) return {
            id:Number(path.split('/').at(-2)), reply:'Split applied.', applied:[{transaction_id:82,version:5}], undo:{available:true},
          };
          throw Error('Unexpected fixture request '+method+' '+path);
        };
        window.initTrajectoryAssistant({
          root:document.querySelector('#trajectory'), getContext:() => current, api,
          endpoint:(path, space) => '/trajectory/spaces/'+space+path, onChanged:() => {},
          onMatchTransfers:() => {window.transferOpens++;},
        });
      }, scenario);

      const dialog = page.getByRole('dialog');
      const choices = dialog.locator('[data-assistant-choice]');
      const search = dialog.locator('[data-assistant-category-search]');
      const sends = () => page.evaluate(() => window.askRequests.filter(r => r.method === 'POST'));
      const catalogRequests = () => page.evaluate(() => window.askRequests.filter(r => r.path.endsWith('/attribution/definitions')));
      const send = async text => {
        await dialog.locator('#tr-assistant-question').fill(text);
        await dialog.getByRole('button', {name:'Send', exact:true}).click();
      };
      await page.locator('.tr-assistant-launch').click();
      await expect(dialog).toBeVisible();
      await expect(choices).toHaveCount(0);
      await expect(search).toHaveCount(0);
      expect(await catalogRequests()).toHaveLength(0);

      if (scenario !== 'fresh') {
        const archive = dialog.locator('details', {hasText:'Previous conversation'});
        await expect(archive).toBeVisible();
        expect(await archive.getAttribute('open')).toBeNull();
        await archive.locator('summary').click();
        await expect(archive.getByText('Move this to the fall project')).toBeVisible();
        await expect(dialog).not.toContainText('Fixture category 59');
        expect(await sends()).toHaveLength(0);
        if (scenario === 'saved-undo' || scenario === 'saved-proposal') {
          const undo = scenario === 'saved-undo';
          const control = archive.locator(undo ? '[data-assistant-undo]' : '[data-assistant-apply]');
          await expect(control).toBeVisible();
          await control.click();
          await expect.poll(async () => (await sends()).map(r => r.path)).toEqual([
            '/trajectory/spaces/7/assistant/'+(undo ? '6/undo' : '7/apply'),
          ]);
          await expect(dialog).toContainText(undo ? 'Change undone.' : 'Split applied.');
        } else {
          await expect(archive.locator('button')).toHaveCount(0);
        }
        continue;
      }

      await dialog.locator('[data-assistant-review-next]').click();
      await expect(dialog).toContainText('Fictional Market');
      await expect(dialog).toContainText('$17.43');
      await expect(dialog).toContainText('What was this purchase for?');
      await expect(choices).toHaveCount(0);
      await send('supplies for tomorrow');
      await expect(dialog).toContainText('Which category or program should I use?');
      const purpose = (await sends()).at(-1);
      expect(purpose.payload.context.transaction_ids).toEqual([82]);
      expect(purpose.payload.context.transaction_versions).toEqual({82:4});
      await expect(choices).toHaveCount(0);
      await expect(dialog).not.toContainText('Fixture category 59');
      await expect(search).toHaveCount(0);
      expect(await catalogRequests()).toHaveLength(0);

      await dialog.locator('[data-assistant-pick]').last().click();
      await expect(search).toBeVisible();
      await expect.poll(async () => (await catalogRequests()).map(r => r.path)).toEqual(['/trajectory/spaces/7/attribution/definitions']);
      await expect(choices).toHaveCount(0);
      await search.fill('Fixture category');
      await expect.poll(() => choices.count()).toBeGreaterThan(0);
      expect(await choices.count()).toBeLessThanOrEqual(5);
      await search.fill('no such category');
      await expect(choices).toHaveCount(0);
      await search.fill('  groc  ');
      await expect(choices).toHaveCount(1);
      await expect(choices).toContainText('Groceries');
      expect(await sends()).toHaveLength(2);
      await choices.click();
      await expect(dialog.getByRole('button', {name:'Undo', exact:true})).toBeVisible();
      const selection = (await sends()).at(-1);
      expect(selection.path).toBe('/trajectory/spaces/7/assistant');
      expect(selection.payload.question).toBe('Move this to category Groceries');
      expect(selection.payload.context.focused_transaction).toBe(true);
      expect(selection.payload.context.transaction_versions).toEqual({82:4});
      expect(selection.payload.context.transaction_ids).toEqual([82]);
      await dialog.getByRole('button', {name:'Undo', exact:true}).click();
      await expect(dialog).toContainText('Change undone.');

      await dialog.locator('[data-assistant-review-next]').click();
      await expect(dialog.locator('.tr-assistant-review')).toBeVisible();
      await send('garden');
      await expect(choices).toHaveCount(2);
      await expect(choices.filter({hasText:/Garden.*category/i})).toHaveCount(1);
      await expect(choices.filter({hasText:/Garden.*program/i})).toHaveCount(1);
      await expect(dialog).not.toContainText('Fixture category 59');
      await expect(search).toHaveCount(0);

      await send('Can you match these transfers?');
      const noWriteOnHandoff = (await sends()).length;
      await dialog.locator('[data-assistant-match-transfers]').click();
      await expect(dialog).toBeHidden();
      expect(await page.evaluate(() => window.transferOpens)).toBe(1);
      expect(await sends()).toHaveLength(noWriteOnHandoff);
    } finally {
      await page.close();
      expect(errors).toEqual([]);
    }
  }
}
