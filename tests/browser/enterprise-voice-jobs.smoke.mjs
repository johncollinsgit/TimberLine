// Component fixture, fake speech and synthetic server replies. Not acoustic or live AI acceptance.
import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { readFile, mkdir, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { chromium, webkit } from 'playwright'

const out = resolve(process.env.VOICE_EVIDENCE_DIR || 'storage/app/voice-jobs-acceptance')
await mkdir(out, { recursive: true })
let calls = 0, allowed = true, delay = 0
const job = { id: 'pipe', title: 'Fictional dorm pipe burst', status: 'active', source_url: '/?section=projects&task=pipe', location: { label: 'Fictional dorm entrance', latitude: 36.162, longitude: -81.872, provenance: 'Synthetic placement, not GPS.' }, due_on: null, blockers: ['Fictional access check'], handler: 'Fictional maintenance crew' }
const server = createServer(async (request, response) => {
  const url = new URL(request.url, 'http://localhost')
  if (url.pathname.startsWith('/resources/js/')) {
    const files = new Set(['/resources/js/enterprise/voice-jobs.js', '/resources/js/ai-voice-conversation.js'])
    if (!files.has(url.pathname)) { response.writeHead(404); response.end(); return }
    response.setHeader('Content-Type', 'application/javascript'); response.end(await readFile(resolve('.' + url.pathname))); return
  }
  if (['/generate', '/preview', '/cancel'].includes(url.pathname)) {
    let text = ''; for await (const chunk of request) text += chunk
    const input = JSON.parse(text); response.setHeader('Content-Type', 'application/json')
    if (url.pathname === '/cancel') { response.end(JSON.stringify({ status: 'cancelled' })); return }
    if (url.pathname === '/preview') {
      response.end(JSON.stringify({ session_id: input.session_id, channel: 'email', to: input.to, subject: 'Synthetic repair update', body: 'Fictional dorm repair draft.', expires_at: 'synthetic expiry', status: 'preview_only', delivery_ready: false, can_confirm: false, recipient_verified: false, notice: 'SIMULATED exact preview. Delivery disconnected. Nothing was sent.' })); return
    }
    const draft = /draft/i.test(input.question)
    response.end(JSON.stringify({ mode: 'generated', provider_dispatch: true, provider_simulated: true, session_id: input.session_id, request_id: input.request_id,
      scope: { tenant_id: 'fictional', user_id: 'presenter', area_id: 'operations' }, outcome: draft ? 'draft_proposed' : 'matched',
      reply: draft ? 'A simulated draft is ready. Nothing was sent.' : 'Simulated generated response grounded in the fictional pipe job.', coverage: 'Synthetic component test only.',
      job: draft ? null : job, candidates: [], draft_proposal: draft ? { channel: 'email', subject: 'Synthetic repair update', body: 'Fictional dorm repair draft.', to: null, delivery_ready: false } : null })); return
  }
  if (url.pathname === '/lookup') {
    calls++; let text = ''; for await (const chunk of request) text += chunk
    const input = JSON.parse(text); await new Promise(resolve => setTimeout(resolve, delay))
    response.setHeader('Content-Type', 'application/json')
    if (!allowed) { response.writeHead(403); response.end(JSON.stringify({ message: 'Current job access was revoked.' })); return }
    const outcome = /send|email|text/i.test(input.question) ? 'review_required' : input.question === 'Show dorm job' ? 'choose' : 'matched'
    response.end(JSON.stringify({ mode: 'record_backed', provider_dispatch: false, outcome, scope: { tenant_id: 'fictional', user_id: 'presenter', area_id: 'operations' },
      reply: outcome === 'matched' ? 'Fictional dorm pipe burst. Location: fictional dorm entrance. Due date not recorded.' : outcome === 'choose' ? 'Choose the exact permitted job.' : 'Review a separate exact message preview. Nothing was sent.',
      job: outcome === 'matched' ? job : null, candidates: outcome === 'choose' ? [{ id: 'pipe', title: job.title }, { id: 'window', title: 'Fictional dorm window' }] : [] })); return
  }
  response.setHeader('Content-Type', 'text/html')
  response.end(`<!doctype html><html lang="en"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ask Everbranch component fixture</title><style>body{font:16px system-ui;margin:20px;max-width:700px}button,input{font:inherit;padding:12px;margin:4px}input{width:85%}section{border:1px solid #ccc;padding:12px;margin:12px 0}pre{white-space:pre-wrap}button{min-height:44px}</style><h1>Ask Everbranch</h1><p>FICTIONAL COMPONENT TEST · fake speech · no provider sends</p><section id="voice"></section><form id="ask"><label>Question<input id="question" maxlength="1500"></label><button>Ask</button></form><section id="answers" aria-label="Checked replies"></section><section id="detail" aria-label="Existing job detail">No job selected</section><section id="map" aria-label="Recorded location">No location selected</section><script type="module">
import { mountVoiceJobs } from '/resources/js/enterprise/voice-jobs.js';
const transport = path => async (body,{signal}={}) => {const response=await fetch(path,{method:'POST',body:JSON.stringify(body),signal});const result=await response.json();if(!response.ok)throw new Error(result.message);return result};
window.session = mountVoiceJobs(document.getElementById('voice'), {
 context:()=>({runtime:'private',authenticated:true,tenantId:'fictional',userId:'presenter',areaId:'operations'}),
 Recognition:window.SpeechRecognition,Utterance:window.SpeechSynthesisUtterance,synthesis:window.speechSynthesis,
 ...(location.search.includes('generated=1') ? {generate:transport('/generate'),cancelSession:transport('/cancel'),preparePreview:transport('/preview')} : {}),
 lookup:async(body,{signal})=>{const response=await fetch('/lookup',{method:'POST',body:JSON.stringify(body),signal});const result=await response.json();if(!response.ok)throw new Error(result.message);return result},
 openJob:async(job,{signal})=>{if(signal.aborted)return false;document.getElementById('detail').textContent=job.title+' · '+job.status+' · '+job.handler;document.getElementById('map').textContent=job.location.label+' · '+job.location.provenance;return true},
 onReply:result=>{const line=document.createElement('p');line.textContent=result.reply+' Response: '+result.latency_ms+' ms';document.getElementById('answers').append(line);for(const candidate of result.candidates){const button=document.createElement('button');button.textContent=candidate.title;button.onclick=()=>window.session.choose(candidate.id);document.getElementById('answers').append(button)}},
 onTranscript:text=>{document.getElementById('question').value=text},onError:()=>{}
});document.getElementById('ask').onsubmit=async event=>{event.preventDefault();await window.session.ask(document.getElementById('question').value)};
</script></html>`)
})
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
const origin = 'http://127.0.0.1:' + server.address().port
const results = []
try {
  for (const engine of (process.env.VOICE_WEBKIT === '1' ? [chromium, webkit] : [chromium])) {
    const browser = await engine.launch({ headless: true, ...(engine === chromium && process.env.VOICE_BROWSER_CHANNEL ? { channel: process.env.VOICE_BROWSER_CHANNEL } : {}) })
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } }), errors = [], external = []
    page.on('pageerror', error => errors.push(error.message))
    await page.route('**/*', route => { if (!route.request().url().startsWith(origin + '/')) { external.push(route.request().url()); return route.abort() }; return route.continue() })
    await page.addInitScript(() => {
      window.captures = []; window.speeches = []
      window.SpeechRecognition = class { constructor() { window.captures.push(this) } start() { this.onstart?.() } abort() { this.onend?.() } result(text) { const row = [{ transcript: text }]; row.isFinal = true; this.onresult?.({ results: [row] }); this.onend?.() } }
      window.SpeechSynthesisUtterance = class { constructor(text) { this.text = text } }
      Object.defineProperty(window, 'speechSynthesis', { value: { cancel() {}, speak(value) { window.speeches.push(value) } } })
    })
    try {
      calls = 0; allowed = true; delay = 0
      await page.goto(origin); await page.getByRole('button', { name: 'Talk', exact: true }).waitFor(); assert.equal(calls, 0)
      await page.getByRole('button', { name: 'Talk', exact: true }).click()
      await page.evaluate(() => window.captures.at(-1).result('Show dorm pipe job'))
      await page.getByRole('status').filter({ hasText: /^Speaking/ }).waitFor()
      await page.getByRole('region', { name: 'Existing job detail' }).filter({ hasText: 'Fictional dorm pipe burst' }).waitFor()
      assert.match(await page.getByRole('region', { name: 'Recorded location' }).textContent(), /not GPS/)
      await page.getByRole('button', { name: 'Interrupt reply' }).click(); assert.equal(await page.evaluate(() => window.captures.length), 2)
      await page.evaluate(() => window.captures.at(-1).result('Where is it?')); await page.getByRole('status').filter({ hasText: /^Speaking/ }).waitFor()
      await page.getByRole('button', { name: 'Stop', exact: true }).click()
      await page.getByLabel('Question', { exact: true }).fill('Show dorm job'); await page.getByRole('button', { name: 'Ask', exact: true }).click()
      await page.getByRole('button', { name: 'Fictional dorm window', exact: true }).waitFor(); await page.getByRole('button', { name: job.title, exact: true }).click()
      await page.getByLabel('Question', { exact: true }).fill('Send an email about the pipe'); await page.getByRole('button', { name: 'Ask', exact: true }).click()
      await page.getByText(/Nothing was sent/).waitFor()
      // A stopped pending lookup cannot touch the detail pane or speak later.
      delay = 200; const before = await page.evaluate(() => window.speeches.length)
      await page.getByRole('button', { name: 'Talk', exact: true }).click(); await page.evaluate(() => window.captures.at(-1).result('Show dorm pipe job'))
      await page.getByRole('status').filter({ hasText: /^Checking/ }).waitFor(); await page.getByRole('button', { name: 'Stop', exact: true }).click()
      await page.waitForTimeout(300); assert.equal(await page.evaluate(() => window.speeches.length), before)
      delay = 0; allowed = false
      await page.getByRole('button', { name: 'Talk', exact: true }).click(); await page.evaluate(() => window.captures.at(-1).result('Show pipe job'))
      await page.getByRole('status').filter({ hasText: 'access was revoked' }).waitFor()
      allowed = true; await page.getByRole('button', { name: 'Talk', exact: true }).click(); await page.evaluate(() => window.captures.at(-1).onerror({ error: 'not-allowed' }))
      await page.getByRole('status').filter({ hasText: 'permission was denied' }).waitFor()
      const beforeHidden = calls
      await page.getByRole('button', { name: 'Talk', exact: true }).click()
      await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, value: true }); document.dispatchEvent(new Event('visibilitychange')); window.captures.at(-1).result('Never send this'); Object.defineProperty(document, 'hidden', { configurable: true, value: false }) })
      assert.equal(calls, beforeHidden)
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth); assert.equal(overflow, false)
      await page.screenshot({ path: resolve(out, engine.name() + '-voice-jobs.png'), fullPage: true })
      await page.goto(origin + '/?generated=1')
      await page.getByRole('button', { name: 'Talk', exact: true }).click()
      await page.evaluate(() => window.captures.at(-1).result('Show pipe job'))
      await page.getByRole('status').filter({ hasText: /^Speaking/ }).waitFor()
      await page.getByText(/Simulated provider response for testing/).waitFor()
      await page.getByRole('button', { name: 'Stop', exact: true }).click()
      await page.getByRole('button', { name: 'Talk', exact: true }).click()
      await page.evaluate(() => window.captures.at(-1).result('Draft an email about the pipe'))
      await page.getByRole('status').filter({ hasText: /^Speaking/ }).waitFor()
      const capturesBeforeReview = await page.evaluate(() => { const count = window.captures.length; window.speeches.at(-1).onend(); return count })
      await page.getByRole('status').filter({ hasText: /Microphone off/ }).waitFor()
      assert.equal(await page.evaluate(() => window.captures.length), capturesBeforeReview)
      await page.getByLabel('Exact draft recipient').fill('fictional-preview@example.invalid')
      await page.getByRole('button', { name: 'Preview draft', exact: true }).click()
      await page.getByText(/To: fictional-preview@example.invalid/).waitFor()
      assert.equal(await page.getByRole('button', { name: /^(Send|Confirm)$/ }).count(), 0)
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth), false)
      await page.screenshot({ path: resolve(out, engine.name() + '-voice-draft-preview.png'), fullPage: true })
      await page.evaluate(() => window.session.stop())
      assert.equal(await page.getByLabel('Exact draft recipient').isVisible(), false)
      assert.deepEqual(errors, []); assert.deepEqual(external, [])
      results.push({ engine: engine.name(), width: 390, calls, explicitStart: true, jobAndLocationOpen: true, followup: true, ambiguity: true, interrupt: true, stopPending: true, hiddenStops: true, revocation: true, micDenial: true, simulatedGeneration: true, exactManualDraftPreview: true, noSendControl: true, externalRequests: external, pageErrors: errors, physicalAcousticAcceptance: false, liveAI: false })
    } finally { await browser.close() }
  }
} finally { await new Promise(resolve => server.close(resolve)) }
await writeFile(resolve(out, 'results.json'), JSON.stringify(results, null, 2))
console.log(JSON.stringify(results))
