import { useEffect, useRef, useState } from 'react'

export type CommitmentSources = { version: number; timezone: string; coverage: string; notes: Array<{ id: number; text: string }>; assignees: Array<{ id: number; name: string }> }
export type CommitmentInput = { job_id: number; note_id: number; quote: string; title: string; assignee_id: number; due_local: string; due_offset?: string; confirmed_promise: boolean; request_id: string }
export type CommitmentList = { version: number; coverage: string; timezone?: string; next_cursor: number | null; tasks: Array<{ id: number; ref: string; title: string; status: string; due_at?: string; assignment?: string }> }

export default function CommitmentDraft({ jobs, load, propose, list, onPrepared }: {
  jobs: Array<{ id: number; title: string }>
  load: (job: number, signal: AbortSignal) => Promise<CommitmentSources>
  propose: (input: CommitmentInput) => Promise<{ action_id: number }>
  list: (view: string, after: number, signal: AbortSignal) => Promise<CommitmentList>
  onPrepared: () => Promise<void>
}) {
  const [job, setJob] = useState('')
  const [sources, setSources] = useState<CommitmentSources | null>(null)
  const [note, setNote] = useState('')
  const [quote, setQuote] = useState('')
  const [title, setTitle] = useState('')
  const [assignee, setAssignee] = useState('')
  const [due, setDue] = useState('')
  const [offset, setOffset] = useState('')
  const [confirmed, setConfirmed] = useState(false)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('A recorded promise can become a reviewed internal task. Preparing a preview creates no task.')
  const [view, setView] = useState('pending')
  const [tasks, setTasks] = useState<CommitmentList | null>(null)
  const epoch = useRef(0)
  const abort = useRef<AbortController | null>(null)
  const request = useRef<{ key: string; id: string } | null>(null)
  const details = useRef<HTMLDetailsElement | null>(null)
  const invalidate = () => { ++epoch.current; abort.current?.abort() }
  useEffect(() => invalidate, [])
  const dirty = Boolean(quote.trim() || title.trim() || due || offset)
  async function selectJob(value: string) {
    if (dirty && !window.confirm('Discard the commitment draft you have not prepared?')) return
    const turn = ++epoch.current; abort.current?.abort(); const controller = new AbortController(); abort.current = controller
    setJob(value); setSources(null); setNote(''); setQuote(''); setTitle(''); setDue(''); setOffset(''); setConfirmed(false); setAssignee('')
    if (!value) return
    setBusy(true)
    try { const data = await load(Number(value), controller.signal); if (turn === epoch.current) { if (data.version !== 1) throw new Error('Commitment sources need an update.'); setSources(data) } }
    catch (error) { if (turn === epoch.current && !controller.signal.aborted) setNotice((error as Error).message) }
    finally { if (turn === epoch.current) setBusy(false) }
  }
  async function prepare() {
    if (busy || !sources || !confirmed) return
    const input = { job_id: Number(job), note_id: Number(note), quote: quote.trim(), title: title.trim(), assignee_id: Number(assignee), due_local: due, ...(offset ? { due_offset: offset.trim() } : {}), confirmed_promise: confirmed }
    const key = JSON.stringify(input)
    if (request.current?.key !== key) request.current = { key, id: crypto.randomUUID() }
    const turn = ++epoch.current; setBusy(true)
    try {
      const preview = await propose({ ...input, request_id: request.current.id })
      if (turn !== epoch.current) return
      setQuote(''); setTitle(''); setDue(''); setOffset(''); setConfirmed(false); setNote(''); request.current = null
      await onPrepared()
      if (turn === epoch.current) {
        setNotice('Task preview prepared. Review the exact source, assignee and due time in the approval below. No task has been created yet.')
        if (details.current) details.current.open = false
        window.requestAnimationFrame(() => {
          if (turn !== epoch.current) return
          const panel = details.current?.closest('dialog, [role="dialog"]')
          const review = panel?.querySelector<HTMLInputElement>(`[data-assistant-action="${preview.action_id}"] input[type="checkbox"]`)
          review?.focus(); review?.scrollIntoView({ block: 'nearest' })
        })
      }
    } catch (error) { if (turn === epoch.current) setNotice((error as Error).message) }
    finally { if (turn === epoch.current) setBusy(false) }
  }
  async function refreshTasks(after = 0) {
    const turn = ++epoch.current; abort.current?.abort(); const controller = new AbortController(); abort.current = controller; setBusy(true)
    try { const data = await list(view, after, controller.signal); if (turn === epoch.current) { if (data.version !== 1) throw new Error('Commitments need an update.'); setTasks(data) } }
    catch (error) { if (turn === epoch.current && !controller.signal.aborted) { setTasks(null); setNotice((error as Error).message) } }
    finally { if (turn === epoch.current) setBusy(false) }
  }
  return <section data-assistant-dirty={dirty}><details ref={details}><summary>Create a task from a recorded promise</summary><p role="status">{notice}</p>
    <form onSubmit={event => { event.preventDefault(); void prepare() }}><label>Commitment job<select value={job} disabled={busy} onChange={event => void selectJob(event.target.value)}><option value="">Choose a recent permitted job</option>{jobs.map(row => <option key={row.id} value={row.id}>{row.title}</option>)}</select></label>
      {sources && <><p>{sources.coverage}</p><label>Source job note<select value={note} disabled={busy} onChange={event => { setNote(event.target.value); setConfirmed(false) }}><option value="">Choose a team-visible note</option>{sources.notes.map(row => <option key={row.id} value={row.id}>Note {row.id}: {row.text.slice(0, 80)}</option>)}</select></label>
        {note && <blockquote>{sources.notes.find(row => row.id === Number(note))?.text}</blockquote>}
        <label>Exact promise quote<textarea value={quote} maxLength={600} disabled={busy} onChange={event => { setQuote(event.target.value); setConfirmed(false) }} /></label>
        <label>Task title<input value={title} maxLength={255} disabled={busy} onChange={event => setTitle(event.target.value)} /></label>
        <label>Task assignee<select value={assignee} disabled={busy} onChange={event => setAssignee(event.target.value)}><option value="">Choose a person</option>{sources.assignees.map(row => <option key={row.id} value={row.id}>{row.name}</option>)}</select></label>
        <label>Due time in {sources.timezone}<input type="datetime-local" value={due} disabled={busy} onChange={event => setDue(event.target.value)} /></label><p>Enter the intended date and time explicitly. Relative dates and the person responsible are not inferred.</p>
        <label>UTC offset for a repeated time (optional)<input value={offset} maxLength={6} placeholder="-04:00" disabled={busy} onChange={event => setOffset(event.target.value)} /></label>
        <label><input type="checkbox" checked={confirmed} disabled={busy} onChange={event => setConfirmed(event.target.checked)} />This exact quote records a commitment, not a suggestion.</label><p>The approved task title and quote will be visible to the job team.</p>
        <button className="assistant-primary primary-button" disabled={busy || !confirmed || !note || !title.trim() || !quote.trim() || !assignee || !due}>Prepare task preview</button>
      </>}
    </form></details>
    <details><summary>Find reviewed commitment tasks</summary><label>Commitment view<select value={view} disabled={busy} onChange={event => { setView(event.target.value); setTasks(null) }}><option value="pending">Pending</option><option value="overdue">Overdue</option><option value="completed">Completed</option></select></label><button disabled={busy} onClick={() => void refreshTasks()}>Find commitments</button>
      {tasks && <><p>{tasks.coverage}</p>{tasks.tasks.length === 0 && <p>No reviewed commitments found in this view.</p>}{tasks.tasks.map(task => <article key={task.id}><strong>{task.title}</strong><p>{task.status} · {task.assignment}</p>{task.due_at && <p>Due {new Date(task.due_at).toLocaleString(undefined, { timeZone: tasks.timezone || 'UTC' })} ({tasks.timezone || 'UTC'})</p>}<small>{task.ref}</small></article>)}{tasks.next_cursor && <button disabled={busy} onClick={() => void refreshTasks(tasks.next_cursor!)}>Next commitment tasks</button>}</>}
    </details>
  </section>
}
