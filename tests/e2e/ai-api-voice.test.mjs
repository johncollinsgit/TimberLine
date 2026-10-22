import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createApiVoice, pcmWave } from '../../resources/js/ai-api-voice.js'

const settle = () => new Promise(resolve => setImmediate(resolve))
function fixture(overrides = {}) {
  const streams = [], processors = [], speakers = [], states = [], errors = [], calls = []
  const node = () => ({ connect() {}, disconnect() {} })
  class Context {
    sampleRate = 16000; destination = {}
    async resume() {} async close() {} async decodeAudioData() { return {} }
    createMediaStreamSource() { return node() }
    createScriptProcessor() { const p = node(); processors.push(p); return p }
    createGain() { return { ...node(), gain: {} } }
    createBufferSource() { const s = { ...node(), start() {}, stop() {} }; speakers.push(s); return s }
  }
  const getUserMedia = async () => { const track = { stopped: false, stop() { this.stopped = true } }; const s = { track, getTracks: () => [track] }; streams.push(s); return s }
  const env = { navigator: { mediaDevices: { getUserMedia } }, AudioContext: Context, btoa, atob, crypto, ...overrides.env }
  const voice = createApiVoice({ onState: s => states.push(s), onError: e => errors.push(e),
    transcribe: async () => { calls.push('transcribe'); return 'Question' },
    answer: async () => { calls.push('answer'); return { uuid: crypto.randomUUID(), conversation_id: 1 } },
    speech: async () => { calls.push('speech'); return { audio: btoa('audio') } }, ...overrides, env })
  const speak = () => { for (let i = 0; i < 4; ++i) processors.at(-1).onaudioprocess({ inputBuffer: { getChannelData: () => new Float32Array(4096).fill(.2) } }) }
  return { voice, streams, processors, speakers, states, errors, calls, speak }
}

test('wave encoding fixes duration, bounds and PCM format independently of microphone sample rate', () => {
  const wav = pcmWave([new Float32Array(48000).fill(2)], 48000), v = new DataView(wav.buffer)
  assert.equal(wav.length, 32044); assert.equal(v.getUint32(4, true), 32036); assert.equal(v.getUint32(24, true), 16000)
  assert.equal(v.getInt16(44, true), 32767)
  assert.equal(pcmWave([new Float32Array(16000 * 31)], 16000).length, 960044)
})

test('API voice releases microphone before inference and playback, resuming only after playback ends', async () => {
  const f = fixture(); await f.voice.start(); f.speak(); await f.voice.finish(); await settle()
  assert.equal(f.streams[0].track.stopped, true); assert.deepEqual(f.calls, ['transcribe', 'answer', 'speech'])
  assert.equal(f.states.at(-1), 'speaking'); assert.equal(f.streams.length, 1)
  f.speakers[0].onended(); await settle(); assert.equal(f.streams.length, 2)
  f.voice.stop(); assert.equal(f.streams[1].track.stopped, true)
  f.speakers[0].onended(); await settle(); assert.equal(f.streams.length, 2)
})

test('closing during permission prompt releases a late microphone without sending anything', async () => {
  let grant; const track = { stopped: false, stop() { this.stopped = true } }
  const f = fixture({ env: { navigator: { mediaDevices: { getUserMedia: () => new Promise(resolve => { grant = resolve }) } } } })
  const pending = f.voice.start(); await settle(); f.voice.stop(); grant({ getTracks: () => [track] }); await pending
  assert.equal(track.stopped, true); assert.equal(f.processors.length, 0); assert.deepEqual(f.calls, [])
})

test('uncertain transcription and stop during an answer never trigger replay, speech or a fresh recording', async () => {
  const failed = fixture({ transcribe: async () => { throw new Error('Usage reserved for review.') } })
  await failed.voice.start(); failed.speak(); await failed.voice.finish(); await settle()
  assert.equal(failed.voice.isActive(), false); assert.equal(failed.errors.length, 1); assert.equal(failed.streams.length, 1)
  let finish
  const f = fixture({ answer: () => new Promise(resolve => { finish = resolve }) })
  await f.voice.start(); f.speak(); const pending = f.voice.finish(); await settle(); f.voice.stop(); finish({ uuid: 'late' }); await pending
  assert.equal(f.speakers.length, 0); assert.equal(f.streams.length, 1)
})
