import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createVoiceConversation } from '../../resources/js/ai-voice-conversation.js'

function fixture(send = async () => 'A scoped answer') {
  const captures = [], speeches = [], states = [], errors = [], questions = [], transcripts = []
  class Recognition {
    constructor() { captures.push(this) }
    start() { this.onstart() }
    abort() { this.aborted = true; this.onend?.() }
    result(text) { const result = [{ transcript: text }]; result.isFinal = true; this.onresult({ results: [result], resultIndex: 0 }) }
  }
  class Utterance { constructor(text) { this.text = text } }
  const voice = createVoiceConversation({ Recognition, Utterance, synthesis: { cancel() {}, speak(speech) { speeches.push(speech) } },
    send: async text => { questions.push(text); return send(text) }, onState: state => states.push(state),
    onTranscript: text => transcripts.push(text), onError: error => errors.push(error) })
  return { voice, captures, speeches, states, errors, questions, transcripts }
}
const settle = () => new Promise(resolve => setImmediate(resolve))

test('sends one final turn, keeps microphone off during response/playback, then listens again', async () => {
  const f = fixture(); f.voice.start()
  f.captures[0].result('What is next?')
  await f.captures[0].onend(); await f.captures[0].onend()
  assert.deepEqual(f.questions, ['What is next?'])
  assert.equal(f.captures.length, 1); assert.equal(f.speeches.length, 1)
  assert.equal(f.states.at(-1), 'speaking')
  f.speeches[0].onend()
  await new Promise(resolve => setTimeout(resolve, 400))
  assert.equal(f.captures.length, 2); assert.equal(f.states.at(-1), 'listening')
  f.voice.stop(); assert.equal(f.captures[1].aborted, true)
})

test('stop during provider wait prevents late speech or another microphone turn', async () => {
  let finish
  const f = fixture(() => new Promise(resolve => { finish = resolve }))
  f.voice.start(); f.captures[0].result('Hello'); const pending = f.captures[0].onend()
  f.voice.stop(); finish('Late answer'); await pending
  assert.equal(f.speeches.length, 0); assert.equal(f.captures.length, 1); assert.equal(f.voice.isActive(), false)
})

test('stale capture and playback callbacks cannot restart after stop or replacement', async () => {
  const f = fixture(); f.voice.start(); const stale = f.captures[0]
  f.voice.start(); stale.result('Do not send'); await stale.onend(); assert.equal(f.questions.length, 0)
  f.captures[1].result('Send this'); await f.captures[1].onend()
  f.voice.stop(); f.speeches[0].onend(); await settle()
  assert.equal(f.captures.length, 2); assert.equal(f.voice.isActive(), false)
})

test('silence, denied permission, oversized input and provider uncertainty stop without automatic retry', async () => {
  for (const mode of ['silence', 'denied', 'long', 'provider']) {
    const f = fixture(async () => { throw new Error('Usage reserved; refresh first.') })
    f.voice.start()
    if (mode === 'denied') f.captures[0].onerror({ error: 'not-allowed' })
    else { if (mode !== 'silence') f.captures[0].result(mode === 'long' ? 'x'.repeat(3001) : 'Hello'); await f.captures[0].onend() }
    assert.equal(f.voice.isActive(), false); assert.equal(f.errors.length, 1)
    assert.equal(f.questions.length, mode === 'provider' ? 1 : 0)
  }
})
