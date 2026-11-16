import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import { once } from 'node:events';
import { chromium, webkit } from 'playwright';

const source = fs.readFileSync(process.env.REWARDS_ASSET || 'resources/shopify/retail-rewards/forestry-rewards.js', 'utf8');
const root = surface => `<div data-forestry-rewards-root data-surface="${surface}" data-shopify-customer-id="771" data-endpoint-candle-cash-status="/apps/forestry/candle-cash/status" data-endpoint-rewards-redeem="/apps/forestry/rewards/redeem" data-endpoint-rewards-event="/apps/forestry/rewards/event"></div>`;
let passed = 0;
for (const engine of [chromium, webkit]) {
  const browser = await engine.launch({ headless: true });
  try {
    for (const width of [1280, 390]) {
      for (const surface of ['page', 'cart']) {
        for (const scenario of ['wallet', 'empty', 'disabled', 'saved', 'pending', 'retry']) {
          const context = await browser.newContext({ viewport: { width, height: 900 }, reducedMotion: 'reduce' });
          const page = await context.newPage();
          let redeemed = 0, attempts = 0, applied = false;
          const errors = [];
          page.on('pageerror', e => errors.push(e.message));
          page.on('requestfailed', r => errors.push(`${new URL(r.url()).pathname}: ${r.failure()?.errorText}`));
          const code = { redemption_code: 'FICTIONAL-REWARD-ONLY', discount_title: 'Reward Credit Applied', is_usable: scenario !== 'pending', apply_path: '/discount/FICTIONAL-REWARD-ONLY?redirect=%2Fcart', reward: { name: 'Candle Cash', reward_value: 10 } };
          const server = http.createServer(async (req, res) => {
            const path = new URL(req.url, 'http://localhost').pathname;
            const reply = (status, type, body, headers={}) => { res.writeHead(status, { 'Content-Type': type, ...headers }); res.end(body); };
            const json = data => reply(200, 'application/json', JSON.stringify(data));
            if (path === '/pages/rewards' || path === '/cart') return reply(200, 'text/html', `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>${root(path === '/cart' ? 'cart' : surface)}<script>${source}</script></body></html>`);
            if (path.endsWith('/candle-cash/status')) return json({ ok: true, data: {
              profile_id: 1, state: 'linked_customer', balance: { amount: scenario === 'empty' ? 5 : redeemed ? 70.5 : 80.5, points: scenario === 'empty' ? 5 : redeemed ? 70.5 : 80.5 },
              redemption_access: { redeem_enabled: scenario !== 'disabled' }, redemption_rules: { redeem_increment_dollars: 10 },
              available_rewards: [{ id: 1, candle_cash_cost: 10, is_redeemable_now: scenario !== 'disabled' }],
              reward_codes: redeemed || ['saved', 'pending'].includes(scenario) ? [code] : [],
            } });
            if (path === '/cart.js') return json({ total_price: applied ? 4000 : 5000, items: [], cart_level_discount_applications: applied ? [{ title: code.discount_title, total_allocated_amount: 1000 }] : [] });
            if (path.endsWith('/rewards/redeem')) {
              attempts++;
              let body = ''; for await (const chunk of req) body += chunk;
              const request = JSON.parse(body);
              assert.equal(request.reward_id, 1);
              assert.equal(request.reuse_existing_code, true);
              if (scenario === 'retry' && attempts === 1) return json({ ok: false, error: { message: 'Temporary failure' } });
              redeemed++;
              return json({ ok: true, data: code });
            }
            if (path.startsWith('/discount/')) { applied = true; return reply(302, 'text/plain', '', { location: '/cart' }); }
            return json({ ok: true, data: {} });
          });
          server.listen(0, '127.0.0.1');
          await once(server, 'listening');
          await page.goto(`http://127.0.0.1:${server.address().port}/${surface === 'cart' ? 'cart' : 'pages/rewards'}`);
          await page.waitForFunction(() => document.querySelector('[data-forestry-rewards-root]').dataset.forestryRewardsState === 'loaded');
          const redeem = page.locator('[data-action="redeem-reward"]');
          if (['empty', 'disabled', 'pending', 'saved'].includes(scenario)) {
            assert.equal(await redeem.count(), 0, `${surface}/${scenario}: no replacement reward`);
            if (scenario === 'pending') assert.equal(await page.locator('[data-action="apply-reward"][data-reward-kind="candle_cash"]').count(), 0, 'unconfirmed code cannot be applied');
            if (scenario === 'saved') assert.ok(await page.locator('[data-action="apply-reward"][data-reward-kind="candle_cash"]').count() > 0);
          } else {
            assert.equal(await redeem.count(), 1, `${surface}: a funded wallet needs a redeem action`);
            if (surface === 'page') assert.equal(await page.locator('.ForestryRewardsStatValue').first().innerText(), '$80.50');
            await redeem.click();
            if (scenario === 'retry') {
              await page.waitForFunction(() => document.querySelector('[data-action="redeem-reward"]')?.disabled === false);
              await page.locator('[data-action="redeem-reward"]').click();
            }
            await page.waitForFunction(() => document.querySelector('[data-forestry-rewards-root]')?.textContent.includes('Applied to this order'), null, {timeout:5000}).catch(async error => {
              throw new Error(`${engine.name()}/${width}/${surface}/${scenario}: ${error.message}; url=${page.url()}; redeemed=${redeemed}; applied=${applied}; errors=${errors.join(',')}; ${await page.locator('body').innerText()}`);
            });
            assert.equal(redeemed, 1);
            assert.equal(applied, true);
            assert.match(await page.locator('.ForestryRewardsCartPanel__balance').innerText(), /70\.50/);
          }
          assert.deepEqual(errors, []);
          passed++;
          await context.close();
          await new Promise(resolve => server.close(resolve));
        }
      }
    }
  } finally { await browser.close(); }
}
console.log(`Retail rewards: ${passed} browser scenarios passed (Chromium/WebKit; desktop/phone; page/cart).`);
