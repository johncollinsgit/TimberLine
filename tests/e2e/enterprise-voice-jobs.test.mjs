import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createVoiceJobs } from '../../resources/js/enterprise/voice-jobs.js'
import { createVoiceConversation } from '../../resources/js/ai-voice-conversation.js'

function fakeClock() {
  let time = 0, next = 0
  const timers = new Map()
  return { now: () => time, setTimeout(fn, ms) { const id = ++next; timers.set(id, { fn, at: time + ms }); return id }, clearTimeout: id => timers.delete(id),
    tick(ms) { const target = time + ms; for (;;) { const first = [...timers].sort((a, b) => a[1].at - b[1].at)[0]; if (!first || first[1].at > target) break; time = first[1].at; timers.delete(first[0]); first[1].fn() }; time = target } }
}

function audio() {
  const captures = [], speeches = []
  class Recognition {
    constructor() { captures.push(this) }
    start() { this.onstart?.() }
    abort() { this.aborted = true; this.onend?.() }
    async result(text) { const row = [{ transcript: text }]; row.isFinal = true; this.onresult?.({ results: [row] }); await this.onend?.() }
  }
  class Utterance { constructor(text) { this.text = text } }
  return { captures, speeches, Recognition, Utterance, synthesis: { cancel() {}, speak(value) { speeches.push(value) } } }
}
const result = (extra = {}) => ({ mode: 'record_backed', provider_dispatch: false, outcome: 'matched',
  scope: { tenant_id: 'tenant', user_id: 'viewer', area_id: 'operations' }, reply: 'Fictional dorm pipe burst. Status: active.',
  candidates: [], job: { id: 'pipe', title: 'Fictional dorm pipe burst', source_url: '/enterprise?selected=repair&task=pipe', location: { label: 'Fictional dorm entrance' } }, ...extra })

function fixture(lookup = async () => result(), voiceOptions = {}) {
  const a = audio(), clock = fakeClock(), replies = [], opened = [], states = [], errors = [], queries = [], transcripts = []
  let current = { runtime: 'private', authenticated: true, tenantId: 'tenant', userId: 'viewer', areaId: 'operations' }
  const session = createVoiceJobs({ ...a, context: () => current, lookup: (input, options) => { queries.push(input); return lookup(input, options) },
    openJob: async job => { opened.push(job); return true }, onReply: value => replies.push(value), onState: value => states.push(value), onTranscript: value => transcripts.push(value), onError: value => errors.push(value), voiceOptions: { clock, ...voiceOptions } })
  return { ...a, session, replies, opened, states, errors, queries, transcripts, clock, change: value => { current = { ...current, ...value } } }
}

test('Talk looks up and opens an actual returned job; a spoken followup retains only its exact focus', async () => {
  const f = fixture(); f.session.start(); await f.captures[0].result('Show the dorm pipe job')
  assert.equal(f.opened[0].id, 'pipe'); assert.equal(f.replies[0].opened, true); assert.equal(f.speeches[0].text, result().reply)
  assert.deepEqual(f.queries[0], { area_id: 'operations', question: 'Show the dorm pipe job' })
  f.speeches[0].onend(); f.clock.tick(350); await f.captures[1].result('Where is it?')
  assert.equal(f.queries[1].focus, 'pipe'); assert.equal(f.transcripts.length, 2); f.session.dispose()
})

test('the tour assignment followup retains only the previously rechecked exact job', async () => {
  const f = fixture(); await f.session.ask('Show the dorm pipe job');
  await f.session.ask('Who is assigned?'); assert.equal(f.queries[1].focus, 'pipe');
  f.session.contextChanged(); await f.session.ask('Who is assigned?'); assert.equal(f.queries[2].focus, undefined); f.session.dispose()
})

test('ambiguity requires an exact current choice and re-reads it; unrelated IDs cannot be opened', async () => {
  let calls = 0
  const f = fixture(async () => ++calls === 1 ? result({ outcome: 'choose', job: null, candidates: [{ id: 'pipe', title: 'Pipe' }, { id: 'window', title: 'Window' }] }) : result())
  await f.session.ask('Show dorm job'); assert.equal(f.opened.length, 0)
  await assert.rejects(f.session.choose('forged'), /current permitted/)
  await f.session.choose('pipe'); assert.equal(f.queries[1].focus, 'pipe'); assert.equal(f.opened.length, 1)
  f.session.dispose()
})

test('stop or workspace change suppresses late detail, speech and navigation', async () => {
  for (const change of ['stop', 'scope']) {
    let finish, signal
    const f = fixture((input, options) => { signal = options.signal; return new Promise(resolve => { finish = resolve }) })
    f.session.start(); const pending = f.captures[0].result('Show dorm pipe job')
    if (change === 'stop') f.session.stop(); else { f.change({ areaId: 'restricted' }); f.session.contextChanged() }
    assert.equal(signal.aborted, true); finish(result()); await pending
    assert.equal(f.opened.length, 0); assert.equal(f.replies.length, 0); assert.equal(f.speeches.length, 0); f.session.dispose()
  }
})

test('mismatched response scope, revocation and unverified identity fail closed', async () => {
  const wrong = fixture(async () => result({ scope: { tenant_id: 'other', user_id: 'viewer', area_id: 'operations' } }))
  await assert.rejects(wrong.session.ask('Show dorm job'), /scope could not be verified/); assert.equal(wrong.opened.length, 0); wrong.session.dispose()
  const revoked = fixture(async () => { throw new Error('Access revoked.') })
  revoked.session.start(); await revoked.captures[0].result('Show pipe job'); assert.equal(revoked.errors[0], 'Access revoked.'); assert.equal(revoked.session.isActive(), false); revoked.session.dispose()
  const identity = fixture(); identity.change({ authenticated: false }); identity.session.start(); assert.equal(identity.captures.length, 0); identity.session.dispose()
})

test('action requests cannot open a record or call a message executor; spoken cancel sends no lookup', async () => {
  const f = fixture(async () => result({ outcome: 'review_required', job: null, reply: 'Review an exact message preview and confirm separately. Nothing was sent.' }))
  await f.session.ask('Send an email about the pipe'); assert.equal(f.opened.length, 0)
  f.session.start(); await f.captures[0].result('Cancel'); assert.equal(f.queries.length, 1); assert.equal(f.session.isActive(), false); f.session.dispose()
})

test('interrupt stops playback and starts a fresh mic turn, preserving the session deadline', async () => {
  const f = fixture(undefined, { maxSessionMs: 1000 }); f.session.start(); await f.captures[0].result('Show pipe job'); const previous = f.speeches[0]
  f.clock.tick(900); assert.equal(f.session.interrupt(), true); previous.onend()
  assert.equal(f.captures.length, 2); f.clock.tick(100); assert.equal(f.session.isActive(), false); assert.match(f.errors.at(-1), /time limit/); f.session.dispose()
})

test('total turns, wait timeout and unsupported speech are bounded without automatic retries', async () => {
  const a = audio(), clock = fakeClock(), errors = []
  const voice = createVoiceConversation({ ...a, clock, maxTurns: 1, send: async () => 'Checked answer', onState() {}, onTranscript() {}, onError: error => errors.push(error) })
  voice.start(); await a.captures[0].result('Hello'); a.speeches[0].onend(); clock.tick(350)
  assert.equal(a.captures.length, 1); assert.match(errors[0], /turn limit/)
  let finish, signal
  const f = fixture((input, options) => { signal = options.signal; return new Promise(resolve => { finish = resolve }) })
  f.session.start(); const pending = f.captures[0].result('Show pipe job'); f.clock.tick(45_000)
  assert.match(f.errors.at(-1), /server may still finish/); assert.equal(signal.aborted, true)
  finish(result()); await pending; assert.equal(f.opened.length, 0); assert.equal(f.queries.length, 1); f.session.dispose()
  const unavailable = createVoiceConversation({ onState() {}, onError: error => errors.push(error) }); unavailable.start(); assert.match(errors.at(-1), /unavailable in this browser/)
})

function generativeFixture(handler, extra = {}) {
  const a = audio(), clock = fakeClock(), replies = [], previews = [], canceled = [], opened = [], errors = []
  let next = 0
  const current = { runtime: 'private', authenticated: true, tenantId: 'tenant', userId: 'viewer', areaId: 'operations' }
  const generated = (input, patch = {}) => result({ mode: 'generated', provider_dispatch: true, provider_simulated: true, session_id: input.session_id, request_id: input.request_id, ...patch })
  const session = createVoiceJobs({ ...a, context: () => current, uuid: () => `fixture-${++next}`,
    lookup: async () => result(), generate: (input, options) => handler(input, options, generated),
    cancelSession: async input => { canceled.push(input) },
    openJob: async job => { opened.push(job); return true }, onReply: value => replies.push(value), onPreview: value => previews.push(value),
    onState() {}, onTranscript() {}, onError: value => errors.push(value), voiceOptions: { clock }, ...extra })
  return { ...a, session, replies, previews, canceled, opened, errors, clock, current }
}

test('explicit configured fake adapter supports job navigation and bounded spoken followup, then cancels its exact session', async () => {
  const inputs = []
  const f = generativeFixture(async (input, options, generated) => { inputs.push(input); return generated(input) })
  f.session.start(); await f.captures[0].result('Show the pipe job')
  assert.equal(f.replies[0].provider_simulated, true); assert.equal(f.opened[0].id, 'pipe')
  f.speeches[0].onend(); f.clock.tick(350); await f.captures[1].result('Where is it?')
  assert.equal(inputs[0].session_id, inputs[1].session_id); assert.notEqual(inputs[0].request_id, inputs[1].request_id)
  assert.equal(inputs[1].focus, 'pipe'); assert.equal('history' in inputs[1], false)
  f.session.stop(); await Promise.resolve(); await Promise.resolve()
  assert.deepEqual(f.canceled, [{ area_id: 'operations', session_id: inputs[0].session_id }])
})

test('completed typed turns retain one owned server conversation with distinct turn keys and exact followup focus', async () => {
  const inputs = []
  const f = generativeFixture(async (input, options, generated) => { inputs.push(input); return generated(input) })
  await f.session.ask('Show the pipe job')
  await f.session.ask('Who is assigned?')
  await f.session.ask('Where is it?')
  assert.equal(new Set(inputs.map(input => input.session_id)).size, 1)
  assert.equal(new Set(inputs.map(input => input.request_id)).size, 3)
  assert.deepEqual(inputs.slice(1).map(input => input.focus), ['pipe', 'pipe'])
  assert.equal(inputs.some(input => 'history' in input), false)
  assert.equal(f.replies.length, 3); assert.equal(f.captures.length, 0); assert.equal(f.speeches.length, 0)
  await Promise.resolve(); assert.equal(f.canceled.length, 0)
  f.session.stop(); await Promise.resolve()
  assert.deepEqual(f.canceled, [{ area_id: 'operations', session_id: inputs[0].session_id }])
})

test('typed and spoken turns share settled history while stale playback cannot restart capture', async () => {
  const inputs = []
  const f = generativeFixture(async (input, options, generated) => { inputs.push(input); return generated(input) })
  await f.session.ask('Show the pipe job')
  f.session.start(); await f.captures[0].result('Where is it?')
  const stalePlayback = f.speeches[0]
  await f.session.ask('Who is assigned?')
  stalePlayback.onend(); f.clock.tick(350)
  assert.equal(f.captures.length, 1); assert.equal(f.session.isActive(), false)
  f.session.start(); await f.captures[1].result('What is its status?')
  assert.equal(new Set(inputs.map(input => input.session_id)).size, 1)
  assert.equal(new Set(inputs.map(input => input.request_id)).size, 4)
  assert.deepEqual(inputs.slice(1).map(input => input.focus), ['pipe', 'pipe', 'pipe'])
  assert.equal(f.replies.length, 4); assert.equal(f.canceled.length, 0)
  f.session.dispose(); await Promise.resolve(); assert.equal(f.canceled.length, 1)
})

test('explicit ambiguity choice rechecks the record without discarding its settled generated conversation', async () => {
  const inputs = [], lookups = []
  const f = generativeFixture(async (input, options, generated) => {
    inputs.push(input)
    return inputs.length === 1 ? generated(input, { outcome: 'choose', job: null, candidates: [{ id: 'pipe', title: 'Pipe' }] }) : generated(input)
  }, { lookup: async input => { lookups.push(input); return result() } })
  await f.session.ask('Show repair job')
  await f.session.choose('pipe')
  await f.session.ask('Who is assigned?')
  assert.equal(inputs[0].session_id, inputs[1].session_id)
  assert.notEqual(inputs[0].request_id, inputs[1].request_id)
  assert.equal(inputs[1].focus, 'pipe')
  assert.deepEqual(lookups, [{ area_id: 'operations', question: 'Show the selected job', focus: 'pipe' }])
  assert.equal(f.canceled.length, 0)
  f.session.dispose()
})

test('replacing an unresolved typed turn with Talk aborts and cancels only the old session', async () => {
  const inputs = []; let finish, firstSignal
  const f = generativeFixture((input, options, generated) => {
    inputs.push(input)
    if (inputs.length === 1) { firstSignal = options.signal; return new Promise(resolve => { finish = () => resolve(generated(input)) }) }
    return generated(input)
  })
  const first = f.session.ask('Question still waiting')
  f.session.start(); assert.equal(firstSignal.aborted, true)
  await f.captures[0].result('Show the pipe job')
  finish(); await first
  assert.notEqual(inputs[0].session_id, inputs[1].session_id)
  assert.equal(f.replies.length, 1); assert.equal(f.replies[0].session_id, inputs[1].session_id)
  assert.deepEqual(f.canceled, [{ area_id: 'operations', session_id: inputs[0].session_id }])
  f.session.dispose()
})

test('Stop, context changes and disposal still cancel completed remote conversations exactly once', async () => {
  for (const action of ['stop', 'contextChanged', 'dispose']) {
    const inputs = []
    const f = generativeFixture(async (input, options, generated) => { inputs.push(input); return generated(input) })
    await f.session.ask('Show the pipe job')
    if (action === 'contextChanged') f.current.areaId = 'different-area'
    f.session[action](); await Promise.resolve()
    assert.deepEqual(f.canceled, [{ area_id: 'operations', session_id: inputs[0].session_id }])
    f.session.dispose(); await Promise.resolve(); assert.equal(f.canceled.length, 1)
  }
})

test('draft proposal never dispatches a preview until exact manual recipient and cannot return after stop', async () => {
  let finish, requests = []
  const f = generativeFixture(async (input, options, generated) => generated(input, { outcome: 'draft_proposed', job: null, draft_proposal: { channel: 'email', subject: 'Fictional repair', body: 'Fictional body', to: null, delivery_ready: false } }), {
    preparePreview: (input, options) => { requests.push(input); return new Promise(resolve => { finish = resolve }) },
  })
  await f.session.ask('Draft an email about the pipe')
  assert.equal(requests.length, 0); assert.equal(f.opened.length, 0)
  const pending = f.session.previewDraft('fixture@example.invalid')
  assert.equal(requests[0].to, 'fixture@example.invalid'); assert.equal('body' in requests[0], false); assert.equal('confirmed' in requests[0], false)
  f.session.stop()
  finish({ session_id: requests[0].session_id, to: requests[0].to, subject: 'Fictional repair', body: 'Fictional body', status: 'preview_only', delivery_ready: false, can_confirm: false, recipient_verified: false })
  assert.equal(await pending, null); assert.equal(f.previews.length, 0)
  await assert.rejects(f.session.previewDraft('fixture@example.invalid'), /current generated draft/)
})

test('stale rejected request cannot cancel its successor session', async () => {
  const calls = []; let rejectA, finishB
  const f = generativeFixture((input, options, generated) => {
    calls.push(input)
    return calls.length === 1 ? new Promise((resolve, reject) => { rejectA = reject }) : new Promise(resolve => { finishB = () => resolve(generated(input)) })
  })
  const first = f.session.ask('Question A')
  const second = f.session.ask('Question B')
  rejectA(new Error('Aborted old request')); await assert.rejects(first, /Aborted/)
  await Promise.resolve(); assert.deepEqual(f.canceled.map(row => row.session_id), [calls[0].session_id])
  finishB(); await second
  assert.equal(f.replies.length, 1); assert.equal(f.replies[0].session_id, calls[1].session_id)
  f.session.dispose()
})

test('missing generation configuration retains zero-dispatch fallback and sample runtime never invokes an adapter', async () => {
  const f = generativeFixture(async () => result())
  await f.session.ask('Show pipe'); f.session.stop(); await Promise.resolve()
  assert.equal(f.replies[0].mode, 'record_backed'); assert.equal(f.canceled.length, 0)
  const g = generativeFixture(async () => { throw new Error('Must not call sample adapter') })
  g.current.runtime = 'sample'; g.current.authenticated = false
  await g.session.ask('Show pipe'); assert.equal(g.replies[0].provider_dispatch, false); g.session.dispose()
})

test('unverified generated session identity and a delivery-enabled preview fail closed', async () => {
  const f = generativeFixture(async (input, options, generated) => generated(input, { session_id: 'foreign' }))
  await assert.rejects(f.session.ask('Show pipe'), /scope could not be verified/); assert.equal(f.opened.length, 0)
  const g = generativeFixture(async (input, options, generated) => generated(input, { outcome: 'draft_proposed', job: null }), {
    preparePreview: async input => ({ session_id: input.session_id, to: input.to, subject: '', body: 'Synthetic', status: 'preview_only', delivery_ready: true, can_confirm: true, recipient_verified: false }),
  })
  await g.session.ask('Draft message'); await assert.rejects(g.session.previewDraft('fixture@example.invalid'), /preview could not be verified/)
  assert.equal(g.previews.length, 0); g.session.dispose()
})

test('spoken draft pauses microphone after playback while retaining explicit manual preview', async () => {
  const f = generativeFixture(async (input, options, generated) => generated(input, { outcome: 'draft_proposed', job: null }), {
    preparePreview: async input => ({ session_id: input.session_id, to: input.to, subject: 'Fixture', body: 'Synthetic draft', status: 'preview_only', delivery_ready: false, can_confirm: false, recipient_verified: false }),
  })
  f.session.start(); await f.captures[0].result('Draft an email about the pipe')
  f.speeches[0].onend(); f.clock.tick(350)
  assert.equal(f.captures.length, 1); assert.equal(f.session.isActive(), false); assert.equal(f.canceled.length, 0)
  const preview = await f.session.previewDraft('fixture@example.invalid')
  assert.equal(preview.to, 'fixture@example.invalid'); assert.equal(f.previews.length, 1)
  f.session.stop(); await Promise.resolve(); assert.equal(f.canceled.length, 1)
})
