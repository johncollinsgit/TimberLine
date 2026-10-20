import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { readFile, mkdir } from 'node:fs/promises'
import { resolve } from 'node:path'
import { chromium } from 'playwright'

const root = process.cwd()
const entry = JSON.parse(await readFile(resolve(root, 'public/build/manifest.json'), 'utf8'))['resources/js/ai-assistant.jsx']
const data = { version: 1, fictional_pilot: true, provider_mode: 'openai', pilot_budget_cents: 500,
  workspace: { id: 1, name: 'Fictional Cedar Works' }, context: { page: { title: 'Workspace' } },
  conversation: null, conversations: [], memories: [], actions: [], usage: { responses: 0, provider_micros: 0 } }
let posts = 0, delay = 0, allowed = true
const server = createServer(async (request, response) => {
  const url = new URL(request.url, 'http://localhost')
  if (url.pathname.startsWith('/build/assets/')) {
    response.setHeader('Content-Type', url.pathname.endsWith('.css') ? 'text/css' : 'application/javascript')
    response.end(await readFile(resolve(root, 'public', '.' + url.pathname))); return
  }
  if (url.pathname.startsWith('/ai-branch')) {
    response.setHeader('Content-Type', 'application/json')
    if (!allowed) { response.writeHead(403); response.end(JSON.stringify({ message: 'Access revoked.' })); return }
    let input = ''; for await (const chunk of request) input += chunk
    const body = input ? JSON.parse(input) : {}
    if (url.pathname.endsWith('/messages')) {
      posts++; await new Promise(resolve => setTimeout(resolve, delay))
      data.conversation.messages.push({ role: 'user', text: body.question }, { role: 'assistant', text: 'Reply to ' + body.question })
      response.end('{}'); return
    }
    if (url.pathname.endsWith('/conversations')) {
      data.conversation = { id: 1, pending: false, messages: [] }; data.conversations = [{ id: 1 }]
      response.end(JSON.stringify({ conversation_id: 1 })); return
    }
    if (url.pathname.endsWith('/memories') && request.method === 'POST') data.memories = [{ id: 1, body: body.body, expires_at: '2027-01-01' }]
    if (url.pathname.endsWith('/memories/1') && request.method === 'DELETE') data.memories = []
    response.end(JSON.stringify({ ...data, conversation: url.searchParams.has('conversation') ? data.conversation : null })); return
  }
  response.end('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="test">' + (entry.css || []).map(file => '<link rel="stylesheet" href="/build/' + file + '">').join('') + '</head><body><div data-ai-assistant data-base-url="/ai-branch?tenant=fictional" data-workspace="Fictional Cedar Works"></div><script type="module">import { mountAiAssistantNow } from "/build/' + entry.file + '"; mountAiAssistantNow()</script></body></html>')
})
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
const browser = await chromium.launch({ headless: true })
const page = await browser.newPage({ viewport: { width: 390, height: 844 } })
const errors = []; page.on('pageerror', error => errors.push(error.message))
try {
  await page.addInitScript(() => {
    window.captures = []; window.speeches = []
    window.SpeechRecognition = class {
      constructor() { window.captures.push(this) }
      start() { this.onstart?.() }
      abort() { this.aborted = true; this.onend?.() }
      stop() { this.onend?.() }
      result(text) { const row = [{ transcript: text }]; row.isFinal = true; this.onresult?.({ results: [row] }); this.onend?.() }
    }
    window.SpeechSynthesisUtterance = class { constructor(text) { this.text = text } }
    Object.defineProperty(window, 'speechSynthesis', { value: { cancel() {}, speak(speech) { window.speeches.push(speech) } } })
  })
  await page.goto('http://127.0.0.1:' + server.address().port)
  await page.getByRole('button', { name: 'Ask Everbranch', exact: true }).click()
  const start = page.getByRole('button', { name: 'Start voice conversation', exact: true })
  await start.waitFor(); await page.getByText('Workspace context on', { exact: false }).waitFor()
  assert.equal(posts, 0)
  await start.click(); await page.evaluate(() => window.captures.at(-1).result('What needs attention?'))
  await page.getByRole('status').filter({ hasText: /^Speaking/ }).waitFor()
  assert.equal(posts, 1); assert.equal(await page.evaluate(() => window.captures.length), 1)
  await page.evaluate(() => window.speeches.at(-1).onend())
  await page.getByRole('status').filter({ hasText: /^Listening/ }).waitFor()
  await page.evaluate(() => window.captures.at(-1).result('Tell me more'))
  await page.getByRole('status').filter({ hasText: /^Speaking/ }).waitFor()
  assert.equal(posts, 2)
  await page.getByRole('button', { name: 'Stop voice conversation', exact: true }).click()
  await page.evaluate(() => window.speeches.at(-1).onend())
  assert.equal(await page.evaluate(() => window.captures.length), 2)
  // A stop while waiting retains the text result, without speaking or restarting.
  delay = 250
  await start.click(); await page.evaluate(() => window.captures.at(-1).result('One more question'))
  await page.getByRole('status').filter({ hasText: /^Thinking/ }).waitFor()
  await page.getByRole('button', { name: 'Stop voice conversation', exact: true }).click()
  await page.getByText('Reply to One more question', { exact: true }).waitFor()
  assert.equal(await page.evaluate(() => window.speeches.length), 2)
  delay = 0
  // Hiding and permission denial both release the microphone; stale text is ignored.
  await start.click()
  await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, value: true }); document.dispatchEvent(new Event('visibilitychange')); window.captures.at(-1).result('Never send this'); Object.defineProperty(document, 'hidden', { configurable: true, value: false }) })
  assert.equal(posts, 3)
  await start.click(); await page.evaluate(() => window.captures.at(-1).onerror({ error: 'not-allowed' }))
  await page.getByRole('status').filter({ hasText: 'permission was denied' }).waitFor()
  // Dictation still requires Ask, independently of conversation mode.
  await page.getByRole('button', { name: 'Voice to text', exact: true }).click()
  await page.evaluate(() => window.captures.at(-1).result('Editable draft'))
  assert.equal(posts, 3); await page.getByLabel('Question', { exact: true }).fill('')
  await page.getByRole('button', { name: 'Private memory', exact: true }).click()
  await page.getByLabel('Remember this', { exact: true }).fill('Use concise answers.')
  await page.getByRole('button', { name: 'Save private memory', exact: true }).click()
  await page.getByText('Use concise answers.', { exact: true }).waitFor()
  await page.reload(); await page.getByRole('button', { name: 'Ask Everbranch', exact: true }).click()
  await page.getByRole('button', { name: 'Private memory', exact: true }).click()
  await page.getByText('Use concise answers.', { exact: true }).waitFor()
  await page.getByRole('button', { name: 'Forget', exact: true }).click()
  await page.getByRole('button', { name: 'Conversation', exact: true }).click()
  await start.waitFor()
  const out = resolve(root, 'storage/app/voice-acceptance')
  await mkdir(out, { recursive: true }); await page.screenshot({ path: resolve(out, 'web-voice-conversation.png') })
  allowed = false; await page.getByRole('button', { name: 'Refresh conversation', exact: true }).click()
  await page.getByRole('status').filter({ hasText: 'Access revoked' }).waitFor(); assert.equal(await start.isDisabled(), true)
  assert.deepEqual(errors, [])
  console.log(JSON.stringify({ automaticTurns: 2, stopDuringWait: true, hiddenStops: true, denialStops: true, draftOnlyDictation: true, memorySurvivesReload: true, forget: true, revocation: true, errors }))
} finally { await browser.close(); await new Promise(resolve => server.close(resolve)) }
