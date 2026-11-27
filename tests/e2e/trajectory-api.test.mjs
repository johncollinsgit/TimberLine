import assert from 'node:assert/strict';
import test from 'node:test';
import {createTrajectoryApi} from '../../resources/js/trajectory/api.js';

const mismatch = () => Response.json({message:'CSRF token mismatch.'}, {status:419});
const token = () => Response.json({csrf_token:'new-token'});
const path = '/trajectory/spaces/7/transactions/19';
const payload = {version:3, category:'groceries', flow:'expense', learn:false};

function fixture(respond) {
  const requests = [], meta = {content:'old-token'};
  let scope = 'household-7';
  const api = createTrajectoryApi({tokenMeta:meta, viewerId:'11', currentScope:() => scope,
    request:async (path, options) => {
      requests.push({path, ...options, headers:{...options.headers}});
      return respond(path, options, requests.length);
    }});
  return {api, requests, meta, switchSpace:() => {scope = 'business-9';}};
}

test('an aged tab refreshes its own session once and repeats the identical category command', async () => {
  const f = fixture((url, options) => {
    if (url === '/trajectory/session-token') return token();
    return options.headers['X-CSRF-TOKEN'] === 'old-token' ? mismatch() : Response.json({version:4});
  });
  assert.deepEqual(await f.api(path, 'PATCH', payload), {version:4});
  assert.deepEqual(f.requests.map(r => [r.method,r.path]), [['PATCH',path],['GET','/trajectory/session-token'],['PATCH',path]]);
  assert.equal(f.requests[1].headers['X-Trajectory-Viewer'], '11');
  assert.equal(f.requests[1].cache, 'no-store');
  assert.ok(f.requests.every(r => r.credentials === 'same-origin' && r.mode === 'same-origin'));
  assert.equal(f.requests[0].body, f.requests[2].body);
  assert.deepEqual(JSON.parse(f.requests[2].body), payload);
  assert.equal(f.requests[2].headers['X-CSRF-TOKEN'], 'new-token');
  assert.equal(f.meta.content, 'new-token');
  await f.api(path, 'PATCH', {...payload, version:4});
  assert.equal(f.requests.length, 4);
});

test('concurrent stale writes share one recovery while retaining separate request IDs and bodies', async () => {
  const f = fixture((url, options) => {
    if (url === '/trajectory/session-token') return token();
    return options.headers['X-CSRF-TOKEN'] === 'old-token' ? mismatch() : Response.json({ok:true});
  });
  const ask = '/trajectory/spaces/7/assistant';
  const commands = ['a','b'].map(request_id => ({request_id, question:'Groceries', context:{transaction_versions:{19:3}}}));
  await Promise.all(commands.map(command => f.api(ask, 'POST', command)));
  assert.equal(f.requests.filter(r => r.method === 'GET').length, 1);
  assert.deepEqual(f.requests.filter(r => r.headers['X-CSRF-TOKEN'] === 'new-token').map(r => JSON.parse(r.body)), commands);
});

test('authentication and workspace changes stop recovery before a second write', async () => {
  for (const status of [401,403]) {
    const f = fixture(url => url === path ? mismatch() : Response.json({message:'Access denied.'}, {status}));
    await assert.rejects(f.api(path, 'PATCH', payload), {status});
    assert.equal(f.requests.filter(r => r.method === 'PATCH').length, 1);
    assert.equal(f.meta.content, 'old-token');
  }
  const f = fixture(url => {
    if (url === path) return mismatch();
    f.switchSpace();
    return token();
  });
  await assert.rejects(f.api(path, 'PATCH', payload), /view changed/);
  assert.equal(f.requests.filter(r => r.method === 'PATCH').length, 1);
});

test('ambiguous results, conflicts, unrelated 419s and repeated CSRF failures never loop', async () => {
  for (const [first, expected] of [
    [() => {throw new TypeError('Network interrupted');}, /Network interrupted/],
    [() => Response.json({errors:{category:['Invalid category']}},{status:422}), {status:422,message:'Invalid category'}],
    [() => Response.json({message:'Version changed'},{status:409}), {status:409}],
    [() => Response.json({message:'Other expiration'},{status:419}), {status:419}],
    [() => new Response('<html>Bad gateway</html>',{status:502}), {status:502}],
  ]) {
    const f = fixture(first);
    await assert.rejects(f.api(path, 'PATCH', payload), expected);
    assert.equal(f.requests.length, 1);
  }
  for (const afterRefresh of [mismatch, () => Response.json({message:'Version changed'},{status:409})]) {
    const f = fixture((url, options, n) => url === '/trajectory/session-token' ? token() : n === 1 ? mismatch() : afterRefresh());
    await assert.rejects(f.api(path, 'PATCH', payload));
    assert.equal(f.requests.length, 3);
  }
});

test('aborting a write during recovery only refreshes the token', async () => {
  const controller = new AbortController();
  const f = fixture(url => {
    if (url === path) return mismatch();
    controller.abort();
    return token();
  });
  await assert.rejects(f.api(path, 'PATCH', payload, controller.signal), {name:'AbortError'});
  assert.equal(f.requests.length, 2);
});

test('multipart repeats its original values, including duplicate fields, after confirmed CSRF rejection', async () => {
  const form = new FormData();
  form.append('rows[]', 'first');
  form.append('rows[]', 'second');
  const f = fixture((url, options, n) => {
    if (n === 1) {
      form.set('rows[]', 'later edit');
      return mismatch();
    }
    if (url === '/trajectory/session-token') return token();
    assert.deepEqual(options.body.getAll('rows[]'), ['first','second']);
    assert.equal(options.headers['Content-Type'], undefined);
    return Response.json({ok:true});
  });
  await f.api('/trajectory/spaces/7/imports', 'POST', form);
  assert.equal(f.requests.length, 3);
});
