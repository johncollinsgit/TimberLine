import React, { useEffect, useRef, useState } from 'react'

export default function WeeklyReport({ api, audience, printUrl }) {
  const [edition, setEdition] = useState('executive')
  const [recipients, setRecipients] = useState([])
  const [week, setWeek] = useState('')
  const [report, setReport] = useState(null)
  const [limit, setLimit] = useState(50)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('Blank date uses this week in the workspace timezone.')
  const sequence = useRef(0)
  const request = useRef(null)
  useEffect(() => () => { ++sequence.current }, [])
  const generate = async event => {
    event.preventDefault()
    const turn = ++sequence.current
    const payload = { ...(week ? { week_start: week } : {}), audience: edition, audience_user_ids: edition === 'team' ? [...recipients].sort((a, b) => a - b) : [] }
    const key = JSON.stringify(payload)
    if (request.current?.key !== key) request.current = { key, id: crypto.randomUUID() }
    setBusy(true); setReport(null)
    try { const saved = await api('/reports', 'POST', { ...payload, request_id: request.current.id }); if (turn === sequence.current) { setReport(saved); setLimit(50); setNotice('Private snapshot saved. Review its audience and coverage before sharing.') } }
    catch (error) { if (turn === sequence.current) { setReport(null); setNotice(error.message) } }
    finally { if (turn === sequence.current) setBusy(false) }
  }
  return <section><h3>Weekly activity report</h3><p role="status">{notice}</p><form onSubmit={generate}>
    <label>Week starts<input type="date" value={week} disabled={busy} onChange={event => setWeek(event.target.value)} /></label>
    <label>Audience<select value={edition} disabled={busy} onChange={event => setEdition(event.target.value)}><option value="executive">Executive edition</option><option value="team">Selected team edition</option></select></label>
    {edition === 'team' && <fieldset><legend>Everyone who will receive this report</legend>{audience.map(user => <label key={user.id}><input type="checkbox" checked={recipients.includes(user.id)} disabled={busy} onChange={event => setRecipients(event.target.checked ? [...recipients, user.id] : recipients.filter(id => id !== user.id))} />{user.name}</label>)}</fieldset>}
    <button className="assistant-primary" disabled={busy || edition === 'team' && !recipients.length}>{busy ? 'Preparing…' : 'Save weekly snapshot'}</button></form>
    {report && <><h4>{report.audience === 'team' ? 'Team' : 'Executive'} edition · {report.count} activity entries</h4><p>{report.starts_at} through {report.ends_before} (exclusive) · {report.timezone}</p><p>{report.coverage_notice}</p><a href={printUrl(report.request_id)} target="_blank" rel="noopener">Print / Save as PDF</a>
      {report.entries.slice(0, limit).map(entry => <article key={entry.ref}><strong>{entry.event}</strong><p>{entry.title}</p><small>{new Date(entry.occurred_at).toLocaleString()} · {entry.ref}</small></article>)}
      {limit < report.entries.length && <button onClick={() => setLimit(value => value + 50)}>Show more ({report.entries.length - limit} remaining)</button>}
    </>}
  </section>
}
