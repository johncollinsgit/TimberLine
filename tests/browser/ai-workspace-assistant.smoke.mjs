import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { readFile, mkdir } from 'node:fs/promises'
import { resolve } from 'node:path'
import { chromium, webkit } from 'playwright'

const root = process.cwd(), manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'))
const entry = manifest['resources/js/ai-assistant.jsx']
const agent = { id: 'a52d1af8-4f8a-411f-951a-d79cbfb92e5c', name: 'Customer care', active: true, instructions: 'Prepare friendly follow-ups.', cadence: 'daily', time: '09:00', timezone: 'America/New_York', runs: [] }
const data = { version: 1, workspace: { id: 1, name: 'Fictional Cedar Works' }, context: { page: { title: 'Workspace' } },
  conversation: { id: 1, pending: false, messages: [] }, conversations: [{ id: 1 }], memories: [], actions: [], usage: { responses: 0, provider_micros: 0 },
  workflows_available: true, history_days: 365, workspace_memories: [], agents_available: true, workspace_agents: [agent], workflow_actions: [],
  api_audio: { available: true, enabled: true, can_approve: true, buyer_multiplier: 2, rates_digest: 'a'.repeat(64), rates: { transcription_per_minute_micros: 4500, speech_text_input_rate: 600000, speech_text_output_rate: 2400000, speech_audio_output_rate: 20000000 } } }
const counts = { transcriptions: 0, questions: 0, speech: 0, decisions: 0 }
const request = (path, body) => {
  if (path.endsWith('/transcribe')) { counts.transcriptions++; return { text: 'What does Sarah need next?' } }
  if (path.endsWith('/messages')) {
    counts.questions++
    const message = { role: 'assistant', uuid: body.request_id, conversation_id: 1, text: 'Sarah has a service visit to review.' }
    data.conversation.messages.push({ role: 'user', uuid: body.request_id, text: body.question }, message)
    data.workflow_actions = [{ id: 1, kind: 'workspace_create_job', status: 'pending', digest: 'a'.repeat(64), expires_at: '2099-01-01', payload: { customer_name: 'Sarah Cedar', title: 'Service visit', description: 'Inspect the equipment.', address: '123 Fictional Lane', schedule: null } }]
    return message
  }
  if (path.endsWith('/speech')) { counts.speech++; return { audio: Buffer.from('fake audio').toString('base64') } }
  if (path.endsWith('/decision')) { counts.decisions++; data.workflow_actions[0].status = 'executed'; data.workflow_actions[0].result_id = 27; return { action: data.workflow_actions[0] } }
  return data
}
const server = createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost')
  if (url.pathname.startsWith('/build/assets/')) { res.setHeader('Content-Type', url.pathname.endsWith('.css') ? 'text/css' : 'application/javascript'); res.end(await readFile(resolve(root, 'public', '.' + url.pathname))); return }
  if (url.pathname.startsWith('/ai-branch')) { let text = ''; for await (const chunk of req) text += chunk; res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(request(url.pathname, text ? JSON.parse(text) : {}))); return }
  res.end('<html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fixture">' + entry.css.map(file => '<link rel="stylesheet" href="/build/' + file + '">').join('') + '</head><body><div data-ai-assistant data-base-url="/ai-branch?tenant=fictional" data-workspace="Fictional Cedar Works"></div><script type="module">import {mountAiAssistantNow} from "/build/' + entry.file + '";mountAiAssistantNow()</script></body></html>')
})
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
export function mockAudio() {
  window.tracks = []; window.processors = []; window.speakers = []
  const node = () => ({ connect() {}, disconnect() {} })
  Object.defineProperty(navigator, 'mediaDevices', { value: { getUserMedia: async () => { const track = { stopped: false, stop() { this.stopped = true } }; window.tracks.push(track); return { getTracks: () => [track] } } } })
  window.AudioContext = class { sampleRate = 16000; destination = {}; async resume() {} async close() {} async decodeAudioData() { return {} }
    createMediaStreamSource() { return node() } createGain() { return { ...node(), gain: {} } }
    createScriptProcessor() { const p = node(); window.processors.push(p); return p }
    createBufferSource() { const s = { ...node(), start() {}, stop() {} }; window.speakers.push(s); return s }
  }
}
try {
  for (const engine of [chromium, webkit]) {
    data.conversation.messages = []; data.workflow_actions = []
    const browser = await engine.launch({ headless: true }), page = await browser.newPage({ viewport: { width: 390, height: 844 } })
    const errors = []; page.on('pageerror', e => errors.push(e.message))
    try {
      await page.addInitScript(mockAudio)
      await page.goto('http://127.0.0.1:' + server.address().port)
      await page.getByRole('button', { name: 'Ask Everbranch', exact: true }).click()
      await page.getByRole('button', { name: 'Start API voice conversation', exact: true }).click()
      await page.getByRole('button', { name: 'Finish speaking', exact: true }).waitFor()
      await page.evaluate(() => { for (let n = 0; n < 4; ++n) window.processors.at(-1).onaudioprocess({ inputBuffer: { getChannelData: () => new Float32Array(4096).fill(.2) } }) })
      await page.getByRole('button', { name: 'Finish speaking', exact: true }).click()
      await page.getByRole('status').filter({ hasText: /^Speaking/ }).waitFor()
      assert.equal(await page.evaluate(() => window.tracks.every(track => track.stopped)), true)
      await page.getByRole('button', { name: 'Stop voice conversation', exact: true }).click()
      const card = page.locator('.workspace-action').filter({ hasText: 'Review new job' }), create = card.getByRole('button', { name: 'Create job', exact: true })
      assert.equal(await create.isDisabled(), true)
      await card.getByRole('checkbox').check(); await create.click(); await card.getByText('Receipt: job #27').waitFor()
      await page.reload(); await page.getByRole('button', { name: 'Ask Everbranch', exact: true }).click()
      await page.getByText('Sarah has a service visit to review.', { exact: true }).waitFor()
      await page.getByRole('button', { name: 'Agents', exact: true }).click()
      await page.getByText('On the roadmap', { exact: true }).waitFor()
      assert.equal(await page.getByRole('button', { name: /job coordinator|daily briefing/i }).count(), 0)
      const out = resolve(root, 'storage/app/voice-acceptance'); await mkdir(out, { recursive: true })
      await page.screenshot({ path: resolve(out, 'workspace-agents-' + engine.name() + '.png') })
      assert.deepEqual(errors, [])
    } finally { await browser.close() }
  }
  assert.deepEqual(counts, { transcriptions: 2, questions: 2, speech: 2, decisions: 2 })
  console.log(JSON.stringify({ chromium: true, webkit: true, apiVoice: true, microphoneReleased: true, reviewedJob: true, resumedHistory: true, roadmapOnly: true, counts }))
} finally { await new Promise(resolve => server.close(resolve)) }
