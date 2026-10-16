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
const web = createServer((request, response) => {
  const url = new URL(request.url, 'http://localhost')
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
const checks = []
try {
  for (let attempt = 0; attempt < 60; attempt++) {
    try { const response = await fetch(mobileUrl); if (response.ok) break } catch {}
    await new Promise(resolve => setTimeout(resolve, 100))
  }
  browser = await chromium.launch({ headless: true, ...(process.env.VOICE_BROWSER_CHANNEL ? { channel: process.env.VOICE_BROWSER_CHANNEL } : {}) })
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } })
  const errors = []
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
  await page.evaluate(() => window.testSpeech.onend())
  await page.getByRole('status').filter({ hasText: 'No speech' }).waitFor()
  checks.push('web no-speech completion')
  await page.locator('[data-command-voice]').click()
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

  await page.route('**/api/mobile/v1/**', route => route.fulfill({ json: { results: [] } }))
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
  assert.deepEqual(errors, [])
  console.log(JSON.stringify({ passed: checks.length, checks, output }, null, 2))
} finally {
  await browser?.close()
  vite.kill()
  await new Promise(resolve => web.close(resolve))
}
