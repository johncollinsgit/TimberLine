import React, { useEffect, useRef, useState } from 'react'
import { createRoot } from 'react-dom/client'
import './ai-assistant.css'
import { formatReportCents } from './ai-report-money'
import WeeklyReport from './ai-weekly-report'
import SharedAttention from './ai-shared-attention'
import PhonePractice from './ai-phone-practice'
import CommitmentDraft from './ai-commitment-draft'

function Assistant({ host }) {
  const dialog = useRef(null)
  const trigger = useRef(null)
  const sequence = useRef(0)
  const abort = useRef(null)
  const recognition = useRef(null)
  const recognitionTimer = useRef(null)
  const requestId = useRef(null)
  const [open, setOpen] = useState(false)
  const [session, setSession] = useState(null)
  const [conversation, setConversation] = useState(null)
  const [tab, setTab] = useState('chat')
  const [draft, setDraft] = useState('')
  const [memory, setMemory] = useState('')
  const [note, setNote] = useState('')
  const [jobId, setJobId] = useState('')
  const noteRequest = useRef(null)
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [uncertain, setUncertain] = useState(false)
  const [listening, setListening] = useState(false)
  const [readAloud, setReadAloud] = useState(false)
  const [reviewed, setReviewed] = useState([])
  const base = new URL(host.dataset.baseUrl, location.origin)
  let origin = {}
  try { const value = JSON.parse(host.dataset.origin || '{}'); if (value && ['workspace', 'job', 'customer', 'class'].includes(value.kind)) origin = value } catch {}
  const stopSpeech = () => window.speechSynthesis?.cancel()
  const stopCapture = () => { const current = recognition.current; recognition.current = null; clearTimeout(recognitionTimer.current); current?.abort(); setListening(false) }
  const endpoint = (path) => { const url = new URL(base); const [pathname, query] = path.split('?', 2); url.pathname = url.pathname.replace(/\/$/, '') + pathname; if (query) for (const [key, value] of new URLSearchParams(query)) url.searchParams.set(key, value); return url }
  const api = async (path, method = 'GET', body, signal) => {
    if (base.origin !== location.origin) throw new Error('Assistant endpoint is unavailable.')
    const payload = body && (path === '/conversations' || path.endsWith('/messages')) ? { ...body, ...(Object.keys(origin).length ? { origin } : {}) } : body
    const response = await fetch(endpoint(path), { method, body: payload ? JSON.stringify(payload) : undefined, signal, credentials: 'same-origin', cache: 'no-store',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' } })
    const data = await response.json().catch(() => ({}))
    if (!response.ok) throw new Error(data.message || 'The assistant is unavailable with your current access.')
    return data
  }
  // Query parameters belong in the URL query, not its pathname.
  const loadSession = async (id, signal) => {
    if (base.origin !== location.origin) throw new Error('Assistant endpoint is unavailable.')
    const url = endpoint('/assistant')
    if (id) url.searchParams.set('conversation', id)
    if (origin.kind) { url.searchParams.set('origin[kind]', origin.kind); if (origin.id) url.searchParams.set('origin[id]', origin.id) }
    const response = await fetch(url, { credentials: 'same-origin', signal, cache: 'no-store', headers: { Accept: 'application/json' } })
    const data = await response.json().catch(() => ({}))
    if (!response.ok) throw new Error(data.message || 'The assistant is unavailable with your current access.')
    if (data.version !== 1) throw new Error('This assistant interface needs an update.')
    return data
  }
  useEffect(() => {
    if (!open) return
    const turn = ++sequence.current
    abort.current = new AbortController()
    setNotice('Loading your private workspace…')
    loadSession(null, abort.current.signal).then(data => { if (turn === sequence.current) { setSession(data); setNotice('Ask about permitted workspace records. Review dictated text before choosing Ask.') } }).catch(error => { if (turn === sequence.current) { setSession(null); setNotice(error.message) } })
    const hidden = () => { if (document.hidden) stopSpeech() }
    document.addEventListener('visibilitychange', hidden)
    return () => { ++sequence.current; abort.current?.abort(); stopCapture(); stopSpeech(); document.removeEventListener('visibilitychange', hidden) }
  }, [open])
  const close = () => {
    if ((draft.trim() || memory.trim() || note.trim() || dialog.current?.querySelector('[data-assistant-dirty="true"]')) && !window.confirm('Discard the assistant draft you have not sent?')) return false
    ++sequence.current; abort.current?.abort(); stopCapture(); stopSpeech(); setBusy(false)
    dialog.current?.close(); setOpen(false); setDraft(''); setMemory(''); setNote(''); noteRequest.current = null; setSession(null); setConversation(null); requestId.current = null; setUncertain(false); trigger.current?.focus()
    return true
  }
  const refresh = async (id = conversation) => {
    const turn = ++sequence.current
    abort.current?.abort(); abort.current = new AbortController(); setBusy(true)
    try { const data = await loadSession(id, abort.current.signal); if (turn !== sequence.current) return; setSession(data); setConversation(data.conversation?.id || null); setUncertain(Boolean(data.conversation?.pending)); setNotice(data.conversation?.pending ? 'A request is pending or needs usage review. Do not resend.' : 'Conversation and access refreshed.') }
    catch (error) { if (turn === sequence.current) { setSession(null); setNotice(error.message) } }
    finally { if (turn === sequence.current) setBusy(false) }
  }
  const ask = async event => {
    event.preventDefault()
    if (busy || uncertain || !draft.trim()) return
    stopCapture(); stopSpeech()
    const question = draft.trim()
    if (requestId.current?.question !== question) requestId.current = { question, id: crypto.randomUUID() }
    const turn = ++sequence.current
    abort.current?.abort(); abort.current = new AbortController(); setBusy(true); setNotice('Preparing your answer…')
    try {
      const id = conversation || (await api('/conversations', 'POST', {})).conversation_id
      if (turn !== sequence.current) return
      setConversation(id)
      await api('/conversations/' + id + '/messages', 'POST', { question, request_id: requestId.current.id }, abort.current.signal)
      if (turn !== sequence.current) return
      const data = await loadSession(id, abort.current.signal)
      if (turn !== sequence.current) return
      setSession(data); setDraft(''); requestId.current = null; setUncertain(false); setNotice('Answer ready. The checked evidence may be incomplete.')
      const message = data.conversation?.messages.filter(row => row.role === 'assistant').at(-1)
      if (readAloud && message && 'speechSynthesis' in window && typeof SpeechSynthesisUtterance !== 'undefined') { const speech = new SpeechSynthesisUtterance(message.text); speech.lang = document.documentElement.lang || 'en-US'; window.speechSynthesis.speak(speech) }
    } catch (error) { if (turn === sequence.current) { setSession(null); setUncertain(true); setNotice(error.message + ' Refresh the conversation before asking again.') } }
    finally { if (turn === sequence.current) setBusy(false) }
  }
  const dictate = (target = 'question') => {
    if (recognition.current) { recognition.current.stop(); return }
    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition
    if (!Recognition) { setNotice('Speech recognition is unavailable. You can type your question.'); return }
    stopSpeech()
    const capture = new Recognition()
    recognition.current = capture
    capture.lang = document.documentElement.lang || navigator.language || 'en-US'; capture.continuous = false; capture.interimResults = false
    let heard = false, failed = false
    capture.onstart = () => { if (recognition.current === capture) { setListening(true); setNotice('Listening. Your words will become an editable draft.') } }
    capture.onresult = event => { if (recognition.current !== capture) return; const text = Array.from(event.results).slice(event.resultIndex || 0).map(result => result[0]?.transcript || '').join(' ').trim(); heard = Boolean(text); if (heard) { (target === 'note' ? setNote : setDraft)(current => (current ? current + '\n' : '') + text); setNotice('Review the text, then choose Ask.') } }
    capture.onerror = event => { if (recognition.current === capture) { failed = true; setNotice(event.error === 'not-allowed' ? 'Microphone permission was denied. Type instead.' : 'Speech could not be captured. Try again or type instead.') } }
    capture.onend = () => { if (recognition.current !== capture) return; recognition.current = null; clearTimeout(recognitionTimer.current); setListening(false); if (!heard && !failed) setNotice('No speech was captured. Try again or type instead.') }
    try { capture.start(); recognitionTimer.current = setTimeout(() => { if (recognition.current === capture) capture.stop() }, 90_000) }
    catch { stopCapture(); setNotice('Microphone unavailable. Type instead.') }
  }
  const updateMemory = async id => {
    const turn = ++sequence.current; setBusy(true)
    try { await api('/memories' + (id ? '/' + id : ''), id ? 'DELETE' : 'POST', id ? undefined : { body: memory.trim(), confirmed: true }); if (turn !== sequence.current) return; setMemory(''); setConversation(null); requestId.current = null; await refresh(null) }
    catch (error) { if (turn === sequence.current) { setSession(null); setNotice(error.message); setBusy(false) } }
  }
  const decide = async (action, decision) => {
    const turn = ++sequence.current; setBusy(true)
    try { await api('/actions/' + action.id + (decision === 'undo' ? '/undo' : '/decision'), 'POST', { digest: action.digest, decision, request_id: crypto.randomUUID(), confirmed: true }); if (turn !== sequence.current) return; setReviewed([]); await refresh() }
    catch (error) { if (turn === sequence.current) { setSession(null); setNotice(error.message); setBusy(false) } }
  }
  const proposeNote = async event => {
    event.preventDefault()
    if (busy || !jobId || !note.trim()) return
    const key = JSON.stringify([Number(jobId), note.trim()])
    if (noteRequest.current?.key !== key) noteRequest.current = { key, id: crypto.randomUUID() }
    const turn = ++sequence.current; setBusy(true)
    try {
      await api('/actions', 'POST', { job_id: Number(jobId), body: note.trim(), request_id: noteRequest.current.id })
      if (turn !== sequence.current) return
      setNote(''); noteRequest.current = null; setReviewed([]); await refresh()
      if (dialog.current?.open) setNotice('Preview prepared. Review the exact job and note before approving. No note has been added yet.')
    } catch (error) { if (turn === sequence.current) { setSession(null); setNotice(error.message); setBusy(false) } }
  }
  const sourceLink = source => {
    if (!source.url) return <span>{source.title || source.ref}</span>
    let url
    try { url = new URL(source.url, location.origin) } catch { return <span>{source.title || source.ref}</span> }
    if (url.origin !== location.origin) return <span>{source.title || source.ref}</span>
    return <a href={url.href} onClick={async event => {
      event.preventDefault()
      const turn = ++sequence.current; abort.current?.abort(); const controller = new AbortController(); abort.current = controller; setBusy(true)
      try {
        const fresh = await loadSession(conversation, controller.signal)
        if (turn !== sequence.current) return
        if (!fresh.conversation?.messages.some(row => row.sources?.some(item => item.url === source.url))) throw new Error('Source access changed.')
        const detail = { allowed: null, hasAssistantDraft: Boolean(draft.trim() || memory.trim() || note.trim() || dialog.current?.querySelector('[data-assistant-dirty="true"]')), message: '' }
        document.dispatchEvent(new CustomEvent('everbranch:check-navigation', { cancelable: true, detail }))
        if (detail.allowed !== true) { setNotice(detail.message || 'Navigation protection is unavailable. Open this record through the menus.'); return }
        location.assign(url.href)
      } catch (error) { if (turn === sequence.current && !controller.signal.aborted) { setSession(null); setNotice(error.message) } }
      finally { if (turn === sequence.current) setBusy(false) }
    }}>{source.title || source.ref}</a>
  }
  return <>
    <button ref={trigger} type="button" className="everbranch-assistant-launcher" onClick={() => { setOpen(true); dialog.current.showModal() }}>Ask Everbranch</button>
    <dialog ref={dialog} className="everbranch-assistant-panel" aria-labelledby="everbranch-assistant-title" onCancel={event => { event.preventDefault(); close() }}>
      <header><div><small>{host.dataset.workspace}</small><h2 id="everbranch-assistant-title">Ask Everbranch</h2></div><button onClick={close} aria-label="Close assistant">×</button></header>
      <button className="assistant-link" onClick={() => { if (close()) document.dispatchEvent(new CustomEvent('app-command-palette:open', { detail: { voice: true, query: '' } })) }}>Find a page</button>
      <nav aria-label="Assistant views">{[['chat', 'Conversation'], ['memory', 'Private memory'], ['activity', 'Activity and approvals'], ...(session?.financial_summary ? [['finance', 'Financial snapshot']] : []), ...(session?.reports_available ? [['reports', 'Weekly report']] : []), ...(session?.attention_available ? [['attention', 'Needs attention']] : []), ...(session?.phone_practice_available ? [['phone', 'Phone practice']] : [])].map(([key, label]) => <button key={key} aria-pressed={tab === key} disabled={busy} onClick={() => { stopCapture(); stopSpeech(); setTab(key) }}>{label}</button>)}</nav>
      <p role="status" aria-live="polite">{notice}</p><button disabled={busy} className="assistant-link" onClick={() => refresh()}>Refresh conversation</button>
      {session?.context?.page?.title && <p>Context: {session.context.page.title}</p>}
      {session?.commitments_available && <div hidden={tab !== 'activity'}><CommitmentDraft jobs={session.note_jobs || []} load={(job, signal) => api('/commitments/sources?job_id=' + job, 'GET', undefined, signal)} propose={input => api('/commitments', 'POST', input)} list={(view, after, signal) => api('/commitments?' + new URLSearchParams({ view, after: String(after) }), 'GET', undefined, signal)} onPrepared={() => refresh()} /></div>}
      {session?.phone_practice_available && tab === 'phone' && <PhonePractice run={(scenario, uuid, signal) => api('/phone/practice', 'POST', { scenario, request_id: uuid }, signal)} />}
      {session?.attention_available && <div hidden={tab !== 'attention'}><SharedAttention active={tab === 'attention'} load={(view, after, signal) => api('/attention?' + new URLSearchParams({ view, after: String(after) }), 'GET', undefined, signal)} mutate={(item, operation, options, uuid) => api('/attention/' + item.id, 'POST', { operation, version: item.version, request_id: uuid, confirmed: true, ...options })} /></div>}
      {tab === 'reports' && session?.reports_available && <WeeklyReport api={api} audience={session.report_audience || []} printUrl={id => endpoint('/reports/' + encodeURIComponent(id)).href} />}
      {tab === 'chat' && <>
        <label>Conversation<select disabled={busy} value={conversation || ''} onChange={event => { if (draft.trim() && !window.confirm('Discard the question you have not sent?')) return; setDraft(''); setSession(null); requestId.current = null; stopSpeech(); refresh(Number(event.target.value) || null) }}><option value="">New private conversation</option>{session?.conversations.map(row => <option key={row.id} value={row.id}>Conversation {row.id}</option>)}</select></label>
        <div aria-label="Conversation messages">{session?.conversation?.messages.map((message, index) => <article key={message.uuid ? message.uuid + message.role : index} className={message.role}><strong>{message.role === 'assistant' ? 'Everbranch' : 'You'}</strong><p>{message.text}</p>{message.generated_at && <small>Generated {new Date(message.generated_at).toLocaleString()}</small>}{message.sources?.length ? <details><summary>Sources ({message.sources.length})</summary>{message.sources.map(source => <p key={source.ref}>{sourceLink(source)}</p>)}</details> : null}</article>)}</div>
        {session?.context && <details><summary>Context coverage</summary><p>Permitted evidence is sampled. This is not a complete activity report or financial ledger.</p></details>}
        <form onSubmit={ask}><label>Question<textarea value={draft} maxLength={3000} disabled={busy} onChange={event => { stopCapture(); setDraft(event.target.value); requestId.current = null }} placeholder="What needs my attention?" /></label><div className="assistant-actions"><button type="button" disabled={busy || uncertain} onClick={() => dictate()}>{listening ? 'Stop listening' : 'Voice to text'}</button><button className="assistant-primary" disabled={busy || uncertain || !draft.trim()}>Ask</button></div></form>
        <label><input type="checkbox" checked={readAloud} disabled={!('speechSynthesis' in window)} onChange={event => { setReadAloud(event.target.checked); stopSpeech() }} />Read answers aloud</label><button className="assistant-link" onClick={stopSpeech}>Stop speaking</button>
        {busy && <button className="assistant-link" onClick={() => { ++sequence.current; abort.current?.abort(); stopCapture(); stopSpeech(); setBusy(false); setUncertain(true); setNotice('Stopped waiting. The server may still finish. Refresh before asking again.') }}>Stop waiting</button>}
      </>}
      {tab === 'finance' && <section><h3>Saved financial report</h3>{session?.financial_summary?.available ? <>
        <p>{session.financial_summary.source} · {session.financial_summary.period_start} through {session.financial_summary.period_end}</p><p>{session.financial_summary.currency || 'Currency unrecorded'} · {session.financial_summary.basis || 'Basis unrecorded'}</p>
        {[['total_income', 'Income'], ['total_expenses', 'Expenses'], ['net_income', 'Net result']].map(([key, label]) => <article key={key}><strong>{label}</strong><p>{formatReportCents(session.financial_summary.amounts_cents?.[key])}</p></article>)}
        <p>{session.financial_summary.stale ? 'Stale saved report' : 'Saved report'} · Observed {new Date(session.financial_summary.observed_at).toLocaleString()}</p><p>{session.financial_summary.coverage}</p>
      </> : <p>{session?.financial_summary?.reason || 'A permitted report is unavailable.'}</p>}<p>Read only. No accounting transaction is created or changed.</p></section>}
      {tab === 'memory' && <><p>Explicit private preferences stay in this workspace. They cannot grant access.</p>{session?.memories.map(row => <article key={row.id}><p>{row.body}</p><small>Expires {new Date(row.expires_at).toLocaleDateString()}</small><button disabled={busy} onClick={() => updateMemory(row.id)}>Forget</button></article>)}<label>Remember this<textarea value={memory} maxLength={500} onChange={event => setMemory(event.target.value)} /></label><button className="assistant-primary" disabled={busy || !memory.trim()} onClick={() => updateMemory()}>Save private memory</button></>}
      {tab === 'activity' && session?.actions_available && <form onSubmit={proposeNote}><h3>Draft an internal job note</h3><p>Recent permitted jobs only. Preparing a preview adds no note and sends no message.</p><label>Job<select value={jobId} disabled={busy} onChange={event => setJobId(event.target.value)}><option value="">Choose a job</option>{session.note_jobs?.map(job => <option key={job.id} value={job.id}>{job.title}</option>)}</select></label><label>Internal note<textarea maxLength={3000} value={note} disabled={busy} onChange={event => { stopCapture(); setNote(event.target.value) }} /></label><button type="button" disabled={busy} onClick={() => dictate('note')}>{listening ? 'Stop listening' : 'Voice to text'}</button><button className="assistant-primary" disabled={busy || !jobId || !note.trim()}>Prepare note preview</button></form>}
      {tab === 'activity' && <><p>Review exact changes before approving. Only unchanged internal notes support undo; sent communications cannot be recalled.</p>{session?.actions.map(action => <article key={action.id} data-assistant-action={action.id}><strong>{action.payload.job_title || 'Internal job note'} · {action.status}</strong><p>{action.payload.body}</p>{action.result_id && <small>Receipt: internal {action.kind === 'internal_commitment_task' ? 'task' : 'note'} {action.result_id}</small>}{action.can_undo && <><label><input type="checkbox" checked={reviewed.includes(action.id)} onChange={event => setReviewed(event.target.checked ? [...reviewed, action.id] : reviewed.filter(id => id !== action.id))} />Remove this exact unchanged internal note.</label><button disabled={busy || !reviewed.includes(action.id)} onClick={() => decide(action, 'undo')}>Undo note</button></>}{action.status === 'pending' && new Date(action.expires_at) > new Date() && <><label><input type="checkbox" checked={reviewed.includes(action.id)} onChange={event => setReviewed(event.target.checked ? [...reviewed, action.id] : reviewed.filter(id => id !== action.id))} />I reviewed this exact {action.kind === 'internal_commitment_task' ? 'task, source, assignee and due time' : 'note and job'}.</label><button disabled={busy || !reviewed.includes(action.id)} onClick={() => decide(action, 'approve')}>{action.kind === 'internal_commitment_task' ? 'Approve task' : 'Approve note'}</button><button disabled={busy} onClick={() => decide(action, 'reject')}>Reject</button></>}</article>)}</>}
    </dialog>
  </>
}

const mounted = new Map()
export function mountAiAssistantNow() {
  for (const [host, root] of mounted) if (!host.isConnected) { root.unmount(); mounted.delete(host) }
  document.querySelectorAll('[data-ai-assistant]').forEach(host => {
    if (mounted.has(host)) return
    const root = createRoot(host); mounted.set(host, root); root.render(<Assistant host={host} />)
  })
}
document.addEventListener('livewire:navigating', () => { for (const root of mounted.values()) root.unmount(); mounted.clear() })
