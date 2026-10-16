import assert from 'node:assert/strict'
import { readFile, mkdir } from 'node:fs/promises'
import { createServer } from 'node:http'
import { spawn } from 'node:child_process'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const mobile = process.env.VOICE_MOBILE_DIR || resolve(root, '../mobile')
const output = process.env.VOICE_EVIDENCE_DIR || resolve(root, 'storage/app/voice-acceptance')
await mkdir(output, { recursive: true })
const blade = await readFile(resolve(root, 'resources/views/components/app-command-palette.blade.php'), 'utf8')
const markup = blade.slice(blade.indexOf('<div'), blade.indexOf('@once')).replaceAll('{{ $searchEndpoint }}', '/search').replace(/{{[^}]+}}/g, 'Ask Everbranch')
const script = blade.match(/<script>([\s\S]*?)<\/script>/)[1]
const manifest = JSON.parse(await readFile(resolve(root, 'public/build/manifest.json'), 'utf8'))
const assistantEntry = manifest['resources/js/ai-assistant.jsx']
const assistantData = { version: 1, workspace: { id: 1, name: 'Fictional Cedar Works' }, context: null, conversation: null, conversations: [], memories: [], actions: [], usage: { responses: 0, provider_micros: 0 } }
let assistantAllowed = true
let assistantRequests = 0
let attentionReceipt = 20
let attentionItem = { id: 41, ref: 'followup:41', title: 'Fictional customer follow-up', status: 'suggested', version: '1'.repeat(64), claim: null }
const assistantResponse = (path, method, body = {}, conversation = false, params = new URLSearchParams()) => {
  if (path.endsWith('/phone/practice')) {
    return { version: 1, simulated: true, simulation_id: body.request_id, scenario: body.scenario,
      outcome: body.scenario === 'provider_outage' ? 'unconfirmed' : 'callback_offer',
      disclosure: 'Fictional practice only. No call, message, purchase, recording, provider request, task assignment or billable usage occurred.',
      coverage: 'Scripted routing and call-state practice only.', steps: ['A fictional human declined the transfer. Offer voicemail or an agreed callback.'],
      routing: { route: 'ring_group', policy_version: 1 }, call: { status: body.scenario === 'provider_outage' ? 'failed' : 'unanswered', requires_review: false, needs_follow_up: true },
      knowledge: { name: 'Fictional Cedar Works public playbook', version: 1, fictional: true }, provider_capabilities: { live_dispatch: false } }
  } else if (path.includes('/attention/') && method === 'POST') {
    attentionReceipt++
    attentionItem = { ...attentionItem, version: attentionReceipt.toString(16).padStart(64, '0') }
    if (body.operation === 'claim') attentionItem.claim = { user_id: 1, name: 'Fictional Alex', until: new Date(Date.now() + 300_000).toISOString() }
    else {
      attentionItem.claim = null
      if (body.operation === 'resolve') attentionItem.status = 'completed'
      else if (body.operation === 'reopen') { attentionItem.status = 'suggested'; attentionItem.snoozed_until = null }
      else if (body.operation === 'snooze') { attentionItem.status = 'snoozed'; attentionItem.snoozed_until = body.until }
    }
    return { receipt_id: attentionReceipt, replayed: false, item: attentionItem }
  } else if (path.endsWith('/attention')) {
    const view = params.get('view') || 'open'
    const visible = view === 'resolved' ? attentionItem.status === 'completed' : view === 'snoozed' ? attentionItem.status === 'snoozed' : attentionItem.status === 'suggested'
    return { version: 1, viewer_id: 1, items: visible ? [attentionItem] : [], next_cursor: null, assignees: [{ id: 1, name: 'Fictional Alex' }], coverage: 'Fictional Customer Loop follow-ups only. No messages are sent.', generated_at: new Date().toISOString() }
  } else if (path.endsWith('/reports') && method === 'POST') {
    return { request_id: body.request_id, audience: body.audience, starts_at: '2026-10-12T00:00:00Z', ends_before: '2026-10-19T00:00:00Z', timezone: 'UTC', generated_at: '2026-10-16T12:00:00Z', count: 60, coverage_complete: false, coverage_notice: 'Fictional registered task events only. Mail, calls and finance are not included.', entries: Array.from({ length: 60 }, (_, index) => ({ ref: 'task_event:' + index, event: 'Task completed', title: 'Fictional task ' + index, occurred_at: '2026-10-16T12:00:00Z' })) }
  } else if (path.endsWith('/actions') && method === 'POST') {
    assistantData.actions = [{ id: 3, kind: 'internal_job_note', status: 'pending', payload: { job_title: 'Fictional panel work', body: body.body }, digest: 'a'.repeat(64), expires_at: new Date(Date.now() + 900_000).toISOString() }]
    return { action_id: 3, status: 'pending' }
  } else if (path.endsWith('/decision')) {
    assistantData.actions[0] = { ...assistantData.actions[0], status: body.decision === 'approve' ? 'executed' : 'rejected', result_id: body.decision === 'approve' ? 3 : undefined }
    return { status: assistantData.actions[0].status }
  } else if (path.endsWith('/undo')) {
    assistantData.actions[0] = { ...assistantData.actions[0], status: 'reversed', can_undo: false }
    return { action_id: 2, status: 'executed', reversed_action_id: 1 }
  } else if (path.endsWith('/messages')) {
    assistantRequests++
    assistantData.conversation = { id: 1, pending: false, messages: [{ role: 'user', text: body.question }, { role: 'assistant', text: 'Fictional answer: <img src=x onerror=alert(1)>', sources: [], generated_at: new Date().toISOString() }] }
  } else if (path.endsWith('/conversations')) {
    assistantData.conversation = { id: 1, pending: false, messages: [] }
    assistantData.conversations = [{ id: 1, created_at: new Date().toISOString(), expires_at: new Date(Date.now() + 86_400_000).toISOString() }]
    return { conversation_id: 1 }
  } else if (path.endsWith('/memories') && method === 'POST') assistantData.memories = [{ id: 1, body: body.body, expires_at: new Date(Date.now() + 86_400_000).toISOString() }]
  else if (path.endsWith('/memories/1') && method === 'DELETE') assistantData.memories = []
  return { ...assistantData, conversation: conversation || path.endsWith('/messages') ? assistantData.conversation : null }
}
let revoked = false
const pageRow = (title, url, exact = false) => ({ type: 'navigation', title, url, meta: { exact_match: exact } })
const fixture = query => {
  let results = query === 'home' ? [pageRow('Home', '/opened-home', true)] :
    query === 'mail' ? [pageRow('Mail inbox', '/opened-mail'), pageRow('Mail settings', '/opened-settings'),
      { type: 'action', title: 'Send mail', url: '/danger' }, pageRow('External trap', 'https://invalid.example/trap')] :
      [pageRow('Home', '/opened-home'), pageRow('Work', '/opened-work')]
  if (revoked) results = []
  return { results, groups: { navigation: results }, total: results.length, navigation: { status: query === 'home' && !revoked ? 'matched' : results.length ? 'choose' : 'no_match', destination: results[0] } }
}
const web = createServer(async (request, response) => {
  const url = new URL(request.url, 'http://localhost')
  if (url.pathname.startsWith('/build/')) {
    const file = resolve(root, 'public', '.' + url.pathname)
    if (!file.startsWith(resolve(root, 'public/build'))) { response.writeHead(403); response.end(); return }
    response.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'application/javascript')
    response.end(await readFile(file)); return
  }
  if (url.pathname === '/assistant-preview') {
    response.end('<!doctype html><html><head><meta name="csrf-token" content="fictional-token">' + (assistantEntry.css || []).map(file => '<link rel="stylesheet" href="/build/' + file + '">').join('') + '</head><body><main><h1>Fictional Cedar Works</h1></main><div data-ai-assistant data-base-url="/ai-branch?tenant=fictional-voice" data-workspace="Fictional Cedar Works"></div><script type="module">import {mountAiAssistantNow} from "/build/' + assistantEntry.file + '";mountAiAssistantNow();</script></body></html>'); return
  }
  if (url.pathname.startsWith('/ai-branch')) {
    response.setHeader('Content-Type', 'application/json')
    if (!assistantAllowed) { response.writeHead(403); response.end(JSON.stringify({ message: 'Access was revoked.' })); return }
    let body = ''; for await (const chunk of request) body += chunk
    response.end(JSON.stringify(assistantResponse(url.pathname, request.method, body ? JSON.parse(body) : {}, url.searchParams.has('conversation'), url.searchParams))); return
  }
  if (url.pathname === '/search') {
    response.setHeader('Content-Type', 'application/json')
    response.end(JSON.stringify(fixture(url.searchParams.get('q') || '')))
  } else response.end('<!doctype html><html lang="en"><head><style>.hidden{display:none!important}body{font:16px system-ui;margin:30px;background:#f4f7f5}section{background:white;padding:25px;border-radius:20px}a{display:block;padding:10px}button,input,textarea{font:inherit;padding:10px}</style></head><body><button data-command-voice-trigger>Ask Everbranch</button><main><label>Draft<textarea aria-label="Draft"></textarea></label></main>' + markup + '<script>' + script + '</script></body></html>')
})
await new Promise(resolve => web.listen(0, '127.0.0.1', resolve))
const webUrl = 'http://127.0.0.1:' + web.address().port
const portProbe = createServer()
await new Promise(resolve => portProbe.listen(0, '127.0.0.1', resolve))
const mobilePort = portProbe.address().port
await new Promise(resolve => portProbe.close(resolve))
const vite = spawn(process.execPath, [resolve(mobile, 'node_modules/vite/bin/vite.js'), '--host', '127.0.0.1', '--port', String(mobilePort), '--strictPort'], { cwd: mobile, windowsHide: true, stdio: 'ignore' })
const mobileUrl = 'http://127.0.0.1:' + mobilePort
let browser
let page
const errors = []
const checks = []
try {
  for (let attempt = 0; attempt < 60; attempt++) {
    try { const response = await fetch(mobileUrl); if (response.ok) break } catch {}
    await new Promise(resolve => setTimeout(resolve, 100))
  }
  browser = await chromium.launch({ headless: true, ...(process.env.VOICE_BROWSER_CHANNEL ? { channel: process.env.VOICE_BROWSER_CHANNEL } : {}) })
  page = await browser.newPage({ viewport: { width: 390, height: 844 } })
  page.setDefaultTimeout(10_000)
  page.on('pageerror', error => errors.push(error.message))
  await page.route('**/*', route => {
    const host = new URL(route.request().url()).hostname
    return host === '127.0.0.1' || host === 'localhost' ? route.continue() : route.abort()
  })
  await page.addInitScript(() => {
    class Recognition {
      start() { window.testSpeech = this; queueMicrotask(() => this.onstart?.()) }
      stop() { this.onend?.() }
      abort() { this.onend?.() }
    }
    window.SpeechRecognition = Recognition
  })
  await page.goto(webUrl)
  await page.getByRole('button', { name: 'Ask Everbranch', exact: true }).click()
  await page.getByRole('status').filter({ hasText: 'Listening.' }).waitFor()
  await page.evaluate(() => window.testSpeech.onend())
  await page.getByRole('status').filter({ hasText: 'No speech' }).waitFor()
  checks.push('web no-speech completion')
  await page.locator('[data-command-voice]').click()
  await page.getByRole('status').filter({ hasText: 'Listening.' }).waitFor()
  await page.evaluate(() => { window.testSpeech.onerror({ error: 'not-allowed' }); window.testSpeech.onend() })
  await page.getByRole('status').filter({ hasText: 'permission was denied' }).waitFor()
  checks.push('web microphone denial')
  await page.locator('[data-command-input]').fill('mail')
  await page.getByRole('option', { name: /Mail inbox/ }).waitFor()
  assert.equal(await page.getByRole('option').count(), 2)
  assert.equal(new URL(page.url()).pathname, '/')
  checks.push('web ambiguity and action/external URL filtering')
  await page.screenshot({ path: resolve(output, 'web-voice.png') })
  revoked = true
  await page.getByRole('option', { name: /Mail inbox/ }).click()
  await page.getByRole('status').filter({ hasText: 'Access changed' }).waitFor()
  checks.push('web selected destination revocation')
  revoked = false
  await page.locator('[data-command-close]').click()
  await page.getByRole('textbox', { name: 'Draft', exact: true }).fill('Unsubmitted work')
  await page.getByRole('button', { name: 'Ask Everbranch', exact: true }).click()
  page.once('dialog', dialog => dialog.dismiss())
  await page.locator('[data-command-input]').fill('home')
  await page.getByRole('status').filter({ hasText: 'Stayed on this page' }).waitFor()
  assert.equal(await page.getByRole('textbox', { name: 'Draft', exact: true }).inputValue(), 'Unsubmitted work')
  assert.equal(await page.getByRole('dialog').isVisible(), true)
  checks.push('web unsaved-work cancellation')
  await page.locator('[data-command-close]').click()
  assert.equal(await page.evaluate(() => document.activeElement?.hasAttribute('data-command-voice-trigger')), true)
  checks.push('web focus restoration')

  await page.route('**/api/mobile/v1/**', route => {
    const url = new URL(route.request().url())
    if (!url.pathname.includes('/ai-assistant')) return route.fulfill({ json: { results: [] } })
    if (!assistantAllowed) return route.fulfill({ status: 403, json: { message: 'Access was revoked.' } })
    return route.fulfill({ json: assistantResponse(url.pathname, route.request().method(), route.request().postDataJSON() || {}, url.searchParams.has('conversation'), url.searchParams) })
  })
  await page.goto(mobileUrl + '/tests/browser/voice-navigation.html')
  const openMobile = () => page.getByRole('button', { name: 'Ask Everbranch', exact: true }).click()
  const search = async text => { await page.getByRole('textbox', { name: 'Page or record to find' }).fill(text); await page.getByRole('button', { name: 'Find', exact: true }).click() }
  await openMobile()
  await search('advanced reports')
  await page.getByRole('button', { name: /Advanced reports/ }).click()
  await page.getByRole('status').filter({ hasText: 'authenticated website' }).waitFor()
  checks.push('mobile unsupported destination')
  await page.screenshot({ path: resolve(output, 'mobile-voice.png') })
  await page.getByRole('button', { name: 'Close voice navigation' }).click()
  await page.getByRole('textbox', { name: 'Job note' }).fill('Keep this fictional job note')
  await openMobile()
  page.once('dialog', dialog => dialog.dismiss())
  await search('work')
  await page.getByRole('status').filter({ hasText: 'Stayed on this page' }).waitFor()
  assert.equal(await page.getByRole('textbox', { name: 'Job note' }).inputValue(), 'Keep this fictional job note')
  assert.equal(await page.evaluate(() => window.voicePilot.events.length), 0)
  checks.push('mobile unsaved-work cancellation and truthful result')
  await page.keyboard.press('Escape')
  await openMobile()
  await page.evaluate(() => { window.voicePilot.uploading = true })
  page.once('dialog', dialog => dialog.accept())
  await search('work')
  await page.getByRole('status').filter({ hasText: 'Stayed on this page' }).waitFor()
  checks.push('mobile pending upload protection')
  await page.evaluate(() => { window.voicePilot.uploading = false; window.voicePilot.delay = 300 })
  await search('work')
  await page.getByRole('textbox', { name: 'Page or record to find' }).fill('advanced')
  await page.waitForTimeout(400)
  assert.equal(await page.evaluate(() => window.voicePilot.events.length), 0)
  checks.push('mobile edited query invalidates in-flight navigation')
  await page.evaluate(() => { window.voicePilot.delay = 0; window.voicePilot.revoked = true })
  await search('work')
  await page.getByRole('status').filter({ hasText: 'No accessible page' }).waitFor()
  checks.push('mobile revoked destination')
  await page.getByRole('button', { name: 'Go back', exact: true }).focus()
  await page.keyboard.press('Tab')
  assert.equal(await page.getByRole('button', { name: 'Close voice navigation' }).evaluate(node => node === document.activeElement), true)
  checks.push('mobile keyboard containment')
  await page.keyboard.press('Escape')
  assert.equal(await page.getByRole('button', { name: 'Ask Everbranch', exact: true }).evaluate(node => node === document.activeElement), true)
  checks.push('mobile focus restoration')
  assistantData.financial_summary = { available: true, source: 'Fictional QuickBooks report', period_start: '2026-10-01', period_end: '2026-10-16', currency: null, basis: 'cash', amounts_cents: { total_income: 105001, total_expenses: 10102, net_income: 94899 }, observed_at: '2026-10-16T12:00:00Z', stale: false, coverage: 'Fictional saved report totals only.' }
  assistantData.actions = [{ id: 1, kind: 'internal_job_note', status: 'executed', payload: { job_title: 'Fictional panel work', body: 'Reviewed fictional internal note' }, digest: '0'.repeat(64), result_id: 1, can_undo: true }]
  assistantData.actions_available = true
  assistantData.note_jobs = [{ id: 1, title: 'Fictional panel work' }]
  assistantData.reports_available = true
  assistantData.report_audience = [{ id: 1, name: 'Fictional Alex' }]
  assistantData.attention_available = true
  assistantData.phone_practice_available = true
  await openMobile()
  await page.getByRole('button', { name: 'Ask about my business' }).click()
  await page.getByRole('status').filter({ hasText: 'Nothing is sent' }).waitFor()
  assert.equal(assistantRequests, 0)
  checks.push('mobile assistant view does not dispatch a provider request')
  await page.getByRole('textbox', { name: 'Question', exact: true }).fill('Summarize the fictional work')
  await page.getByRole('button', { name: 'Ask', exact: true }).click()
  await page.getByText('Fictional answer:', { exact: false }).waitFor()
  assert.equal(await page.locator('.assistant-messages img').count(), 0)
  assert.equal(assistantRequests, 1)
  checks.push('mobile contextual conversation renders generated text safely')
  await page.getByRole('button', { name: 'Financial snapshot', exact: true }).click()
  await page.getByText('Currency unrecorded', { exact: false }).waitFor()
  await page.getByText('1,050.01', { exact: true }).waitFor()
  checks.push('mobile saved financial totals preserve currency and period disclosure')
  await page.getByRole('button', { name: 'Activity and approvals', exact: true }).click()
  assert.equal(await page.getByRole('button', { name: 'Undo note', exact: true }).isDisabled(), true)
  await page.getByRole('checkbox', { name: 'Remove this exact unchanged internal note.' }).check()
  await page.getByRole('button', { name: 'Undo note', exact: true }).click()
  await page.getByText('Fictional panel work · reversed', { exact: true }).waitFor()
  checks.push('mobile supported undo requires an explicit exact-note review')
  await page.getByRole('combobox', { name: 'Job', exact: true }).selectOption('1')
  await page.getByRole('textbox', { name: 'Internal note', exact: true }).fill('Fictional customer requested a panel review')
  await page.getByRole('button', { name: 'Prepare note preview', exact: true }).click()
  await page.getByText('Fictional panel work · pending', { exact: true }).waitFor()
  assert.equal(await page.getByRole('button', { name: 'Approve note', exact: true }).isDisabled(), true)
  await page.getByRole('checkbox', { name: 'I reviewed this exact note and job.' }).check()
  await page.getByRole('button', { name: 'Approve note', exact: true }).click()
  await page.getByText('Fictional panel work · executed', { exact: true }).waitFor()
  checks.push('mobile internal note preparation is separate from exact-preview approval')
  await page.getByRole('button', { name: 'Weekly report', exact: true }).click()
  await page.getByRole('combobox', { name: 'Audience', exact: true }).selectOption('team')
  assert.equal(await page.getByRole('button', { name: 'Save weekly snapshot', exact: true }).isDisabled(), true)
  await page.getByRole('checkbox', { name: 'Fictional Alex', exact: true }).check()
  await page.getByRole('button', { name: 'Save weekly snapshot', exact: true }).click()
  await page.getByText('Team edition · 60 activity entries', { exact: true }).waitFor()
  await page.getByText('Fictional registered task events only.', { exact: false }).waitFor()
  await page.getByRole('button', { name: 'Show more (10 remaining)', exact: true }).click()
  await page.getByText('Fictional task 59', { exact: true }).waitFor()
  checks.push('mobile weekly snapshots require team audience and disclose reconciled coverage with full pagination')
  await page.getByRole('button', { name: 'Needs attention', exact: true }).click()
  await page.getByRole('button', { name: 'Claim follow-up', exact: true }).click()
  await page.getByRole('button', { name: 'Resolve follow-up', exact: true }).waitFor()
  await page.screenshot({ path: resolve(output, 'mobile-attention.png') })
  await page.getByRole('textbox', { name: 'Resolution', exact: true }).fill('Fictional callback reviewed')
  page.once('dialog', dialog => dialog.dismiss())
  await page.getByRole('button', { name: 'Close assistant', exact: true }).click()
  assert.equal(await page.getByRole('textbox', { name: 'Resolution', exact: true }).inputValue(), 'Fictional callback reviewed')
  await page.getByRole('button', { name: 'Resolve follow-up', exact: true }).click()
  await page.getByRole('status').filter({ hasText: 'Change recorded. Receipt' }).waitFor()
  await page.getByRole('combobox', { name: 'Follow-up view', exact: true }).selectOption('resolved')
  await page.getByRole('button', { name: 'Claim follow-up', exact: true }).click()
  await page.getByRole('button', { name: 'Reopen follow-up', exact: true }).click()
  await page.getByRole('status').filter({ hasText: 'Change recorded. Receipt' }).waitFor()
  checks.push('mobile attention claim resolution and reopen require explicit changes and retain a declined draft')
  await page.getByRole('button', { name: 'Phone practice', exact: true }).click()
  await page.getByRole('combobox', { name: 'Fictional phone scenario', exact: true }).selectOption('provider_outage')
  await page.getByRole('button', { name: 'Run fictional practice', exact: true }).click()
  await page.getByText('Practice outcome: unconfirmed', { exact: true }).waitFor()
  await page.getByText('Fictional practice only. No call', { exact: false }).waitFor()
  assert.equal(assistantRequests, 1)
  checks.push('mobile phone practice labels provider uncertainty and causes no model dispatch')
  await page.getByRole('button', { name: 'Private memory', exact: true }).click()
  await page.getByRole('textbox', { name: 'Remember this' }).fill('Use brief fictional summaries')
  await page.keyboard.press('Tab')
  assert.equal(await page.getByRole('button', { name: 'Save private memory' }).evaluate(node => node === document.activeElement), true)
  checks.push('mobile assistant focus order includes textareas and select controls')
  await page.getByRole('button', { name: 'Save private memory' }).click()
  await page.getByRole('button', { name: 'Forget', exact: true }).waitFor()
  await page.getByRole('button', { name: 'Forget', exact: true }).click()
  await page.getByRole('button', { name: 'Forget', exact: true }).waitFor({ state: 'hidden' })
  checks.push('mobile explicit memory save and forget')
  assistantAllowed = false
  await page.getByRole('button', { name: 'Refresh conversation', exact: true }).click()
  await page.getByRole('status').filter({ hasText: 'Access was revoked' }).waitFor()
  assert.equal(await page.getByText('Fictional answer:', { exact: false }).count(), 0)
  checks.push('mobile assistant revocation clears prior answers')
  assistantAllowed = true
  await page.goto(webUrl + '/assistant-preview')
  await page.getByRole('button', { name: 'Ask Everbranch', exact: true }).click()
  await page.getByRole('status').filter({ hasText: 'Review dictated text' }).waitFor()
  assert.equal(assistantRequests, 1)
  await page.getByRole('textbox', { name: 'Question', exact: true }).fill('Summarize this fictional workspace')
  await page.getByRole('button', { name: 'Ask', exact: true }).click()
  await page.getByText('Fictional answer:', { exact: false }).waitFor()
  assert.equal(await page.locator('dialog img').count(), 0)
  checks.push('web assistant dispatches only on Ask and escapes generated text')
  await page.screenshot({ path: resolve(output, 'web-assistant.png') })
  await page.getByRole('button', { name: 'Financial snapshot', exact: true }).click()
  await page.getByText('Currency unrecorded', { exact: false }).waitFor()
  await page.getByText('1,050.01', { exact: true }).waitFor()
  checks.push('web saved financial totals preserve currency and period disclosure')
  await page.screenshot({ path: resolve(output, 'web-financial-snapshot.png') })
  await page.getByRole('button', { name: 'Needs attention', exact: true }).click()
  await page.getByRole('button', { name: 'Claim follow-up', exact: true }).click()
  await page.getByRole('button', { name: 'Snooze 24 hours', exact: true }).click()
  await page.getByRole('status').filter({ hasText: 'Change recorded. Receipt' }).waitFor()
  await page.getByRole('combobox', { name: 'Follow-up view', exact: true }).selectOption('snoozed')
  await page.getByText('Fictional customer follow-up', { exact: true }).waitFor()
  checks.push('web attention shows the shared follow-up and a truthful snooze receipt')
  await page.setViewportSize({ width: 1360, height: 900 })
  await page.screenshot({ path: resolve(output, 'web-attention.png') })
  await page.getByRole('button', { name: 'Phone practice', exact: true }).click()
  await page.getByRole('combobox', { name: 'Fictional phone scenario', exact: true }).selectOption('transfer_declined')
  await page.getByRole('button', { name: 'Run fictional practice', exact: true }).click()
  await page.getByText('Practice outcome: callback offer', { exact: true }).waitFor()
  await page.getByText('Call state (simulated): unanswered', { exact: true }).waitFor()
  checks.push('web phone practice does not label a declined transfer as connected')
  await page.screenshot({ path: resolve(output, 'web-phone-practice.png') })
  await page.getByRole('button', { name: 'Weekly report', exact: true }).click()
  await page.getByRole('button', { name: 'Save weekly snapshot', exact: true }).click()
  await page.getByText('Executive edition · 60 activity entries', { exact: true }).waitFor()
  assert.equal(new URL(await page.getByRole('link', { name: 'Print / Save as PDF', exact: true }).getAttribute('href')).origin, new URL(webUrl).origin)
  checks.push('web weekly snapshot exposes authenticated same-origin print and coverage')
  await page.setViewportSize({ width: 1360, height: 900 })
  await page.screenshot({ path: resolve(output, 'web-weekly-report.png') })
  await page.getByRole('button', { name: 'Conversation', exact: true }).click()
  await page.getByRole('textbox', { name: 'Question', exact: true }).fill('An unsent question')
  page.once('dialog', dialog => dialog.dismiss())
  await page.getByRole('button', { name: 'Close assistant' }).click()
  assert.equal(await page.getByRole('dialog').isVisible(), true)
  checks.push('web assistant retains an unsent draft when closing is declined')
  assistantAllowed = false
  await page.getByRole('button', { name: 'Refresh conversation', exact: true }).click()
  await page.getByRole('status').filter({ hasText: 'Access was revoked' }).waitFor()
  assert.equal(await page.getByText('Fictional answer:', { exact: false }).count(), 0)
  checks.push('web assistant revocation clears prior answers')
  assert.deepEqual(errors, [])
  console.log(JSON.stringify({ passed: checks.length, checks, output }, null, 2))
} catch (error) {
  console.error({ checks, errors, page: await page?.locator('body').innerText().catch(() => '') })
  await page?.screenshot({ path: resolve(output, 'last-failure.png') }).catch(() => undefined)
  throw error
} finally {
  await browser?.close()
  vite.kill()
  await new Promise(resolve => web.close(resolve))
}
