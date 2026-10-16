import { useCallback, useEffect, useRef, useState } from 'react'

export type AttentionItem = { id: number; ref: string; title: string; status: string; version: string; due_at?: string; snoozed_until?: string; assigned_name?: string; assigned_user_id?: number; claim: { user_id: number; name: string; until: string } | null }
export type AttentionPayload = { version: number; viewer_id: number; items: AttentionItem[]; next_cursor: number | null; assignees: Array<{ id: number; name: string }>; coverage: string; generated_at: string }
export type AttentionOperation = 'claim' | 'release' | 'assign' | 'snooze' | 'resolve' | 'reopen'
export type AttentionOptions = { assignee_id?: number; until?: string; resolution?: string }
type View = 'open' | 'snoozed' | 'resolved'

/** Paired web/native component. All mutations require a fresh server version. */
export default function SharedAttention({ active, load, mutate }: {
  active: boolean
  load: (view: View, after: number, signal: AbortSignal) => Promise<AttentionPayload>
  mutate: (item: AttentionItem, operation: AttentionOperation, options: AttentionOptions, uuid: string) => Promise<{ receipt_id: number; replayed: boolean }>
}) {
  const [data, setData] = useState<AttentionPayload | null>(null)
  const [view, setView] = useState<View>('open')
  const [cursors, setCursors] = useState([0])
  const [page, setPage] = useState(0)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('Loading registered follow-ups…')
  const [resolutions, setResolutions] = useState<Record<number, string>>({})
  const [assignees, setAssignees] = useState<Record<number, string>>({})
  const epoch = useRef(0)
  const abort = useRef<AbortController | null>(null)
  const methods = useRef({ load, mutate }); methods.current = { load, mutate }
  const working = useRef(false)
  const mounted = useRef(true)
  const visible = useRef(active); visible.current = active
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  const request = useRef<{ key: string; id: string } | null>(null)
  const after = cursors[page] || 0

  const refresh = useCallback(async (quiet = false) => {
    if (working.current) return
    const turn = ++epoch.current
    abort.current?.abort(); const controller = new AbortController(); abort.current = controller
    if (!quiet) setBusy(true)
    try {
      const value = await methods.current.load(view, after, controller.signal)
      if (turn !== epoch.current) return
      if (value.version !== 1) throw new Error('This attention interface needs an update.')
      setData(value)
      if (!quiet) setNotice('Review current ownership before changing a follow-up. Claims last five minutes.')
      return true
    } catch (error) { if (turn === epoch.current && !controller.signal.aborted) { setData(null); setNotice((error as Error).message) } }
    finally { if (turn === epoch.current) setBusy(false) }
  }, [view, after])
  const latestRefresh = useRef(refresh); latestRefresh.current = refresh
  const invalidate = useCallback(() => { ++epoch.current; abort.current?.abort() }, [])

  useEffect(() => {
    if (!active) return
    setData(null); void refresh()
    const timer = window.setInterval(() => { if (!document.hidden) void refresh(true) }, 15_000)
    return () => { invalidate(); window.clearInterval(timer) }
  }, [active, refresh, invalidate])

  async function change(item: AttentionItem, operation: AttentionOperation, options: AttentionOptions = {}) {
    if (working.current) return
    const key = JSON.stringify([item.id, item.version, operation, options])
    if (request.current?.key !== key) request.current = { key, id: crypto.randomUUID() }
    const turn = ++epoch.current
    abort.current?.abort(); working.current = true; setBusy(true)
    let refreshed = false
    try {
      const result = await methods.current.mutate(item, operation, options, request.current.id)
      if (turn !== epoch.current) return
      setResolutions(current => ({ ...current, [item.id]: '' })); request.current = null
      working.current = false
      refreshed = Boolean(await refresh(true))
      if (refreshed && turn + 1 === epoch.current) setNotice(`Change recorded. Receipt ${result.receipt_id}${result.replayed ? ' (previously completed)' : ''}. No customer message was sent.`)
    } catch (error) {
      if (turn === epoch.current) {
        setData(null)
        setNotice((error as Error).message + ' Refresh before changing this item again. The server may have recorded the previous request.')
      }
    } finally { working.current = false; if (mounted.current) { setBusy(false); if (!refreshed && turn !== epoch.current && visible.current) void latestRefresh.current(true) } }
  }

  return <section className="assistant-shared-attention" data-assistant-dirty={Object.values(resolutions).some(value => value.trim())}>
    <h3>Needs attention</h3><p role="status" aria-live="polite">{notice}</p>
    <label>Follow-up view<select value={view} disabled={busy} onChange={event => { setView(event.target.value as View); setPage(0); setCursors([0]) }}><option value="open">Open follow-ups</option><option value="snoozed">Snoozed follow-ups</option><option value="resolved">Resolved follow-ups</option></select></label>
    <button className="assistant-link text-button" disabled={busy} onClick={() => void refresh()}>Refresh attention</button>
    {data && <><p>{data.coverage}</p><small>Checked {new Date(data.generated_at).toLocaleTimeString()}. Visible-page ownership refreshes every 15 seconds.</small>
      {data.items.length === 0 && <p>No follow-ups were found in this view of the permitted registered source.</p>}
      {data.items.map(item => {
        const held = item.claim && new Date(item.claim.until).getTime() > Date.now()
        const mine = held && item.claim?.user_id === data.viewer_id
        const resolved = ['completed', 'dismissed'].includes(item.status)
        return <article key={item.id}><strong>{item.title}</strong><p>{item.status}{item.assigned_name ? ' · Assigned to ' + item.assigned_name : ''}</p>
          {item.due_at && <small>Due {new Date(item.due_at).toLocaleString()}</small>}
          {item.snoozed_until && <small>Snoozed until {new Date(item.snoozed_until).toLocaleString()}</small>}
          {held && <p>Working: {item.claim?.name} · claim ends {new Date(item.claim!.until).toLocaleTimeString()}</p>}
          {!mine ? <button className="assistant-primary primary-button" disabled={busy || Boolean(held)} onClick={() => void change(item, 'claim')}>Claim follow-up</button> : <>
            <button disabled={busy} onClick={() => void change(item, 'release')}>Release claim</button>
            {resolved ? <button disabled={busy} onClick={() => void change(item, 'reopen')}>Reopen follow-up</button> : <>
              <label>Assign follow-up<select value={assignees[item.id] || ''} disabled={busy} onChange={event => setAssignees(current => ({ ...current, [item.id]: event.target.value }))}><option value="">Choose an authorized person</option>{data.assignees.map(user => <option key={user.id} value={user.id}>{user.name}</option>)}</select></label>
              <button disabled={busy || !assignees[item.id]} onClick={() => void change(item, 'assign', { assignee_id: Number(assignees[item.id]) })}>Assign follow-up</button>
              <button disabled={busy} onClick={() => void change(item, 'snooze', { until: new Date(Date.now() + 86_400_000).toISOString() })}>Snooze 24 hours</button>
              <label>Resolution<textarea value={resolutions[item.id] || ''} maxLength={500} disabled={busy} onChange={event => setResolutions(current => ({ ...current, [item.id]: event.target.value }))} /></label>
              <button className="assistant-primary primary-button" disabled={busy || !resolutions[item.id]?.trim()} onClick={() => void change(item, 'resolve', { resolution: resolutions[item.id].trim() })}>Resolve follow-up</button>
            </>}
          </>}
        </article>
      })}
      <div><button disabled={busy || page === 0} onClick={() => setPage(value => value - 1)}>Previous follow-ups</button><button disabled={busy || !data.next_cursor} onClick={() => { setCursors(current => [...current.slice(0, page + 1), data.next_cursor!]); setPage(value => value + 1) }}>Next follow-ups</button></div>
    </>}
  </section>
}
