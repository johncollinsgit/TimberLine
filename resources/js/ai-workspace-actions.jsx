import React, { useState } from 'react'

export default function WorkspaceActions({ actions, busy, decide }) {
  const [reviewed, setReviewed] = useState([])
  return <section className="workspace-action-list" aria-label="Jobs and customer email">
    {actions.map(action => {
      const p = action.payload
      const email = action.kind === 'workspace_customer_email'
      const agent = action.kind === 'workspace_create_followup_agent'
      const key = action.id + ':' + action.digest
      const pending = action.status === 'pending' && new Date(action.expires_at) > new Date()
      return <article className="workspace-action" key={key}>
        <h3>{agent ? 'Review customer follow-up agent' : email ? 'Review customer email' : 'Review new job'}</h3>
        <p><strong>{agent ? p.agent_name : p.customer_name}</strong> · {action.status === 'executed' ? (agent ? 'Agent created' : email ? 'Accepted by email provider' : 'Job created') : action.status}</p>
        {agent ? <><p>{p.agent_instructions}</p><p>{p.agent_cadence === 'daily' ? 'Daily at ' + p.agent_time + ' · ' + p.timezone : 'Run when you choose'}</p><p>{p.permissions}</p><p>Runs use your existing workspace AI allowance.</p></> : email ? <>
          <p>From: {p.sender.from_name} &lt;{p.sender.from_email || 'Sender needs setup'}&gt;<br />To: {p.to}<br />Subject: {p.subject}</p>
          <div className="workspace-email-preview" style={{ borderTop: '4px solid ' + (/^#[a-f0-9]{6}$/i.test(p.brand.color) ? p.brand.color : '#174f46') }}><strong>{p.brand.name}</strong><p>{p.body}</p><hr /><small>{p.sender.from_name}<br />{p.sender.reply_to_email || p.sender.from_email}</small></div>
          {!p.sender.ready && <p>Connect this workspace’s email sender, then prepare a fresh preview.</p>}
          <p>{p.is_marketing ? 'Marketing permission and unsubscribe settings are checked before sending.' : 'Service update for ' + p.job_title + ' (#' + p.job_id + ')'}</p>
        </> : <><h4>{p.title}</h4><p>{p.description}</p><p>{p.address}</p><p>{p.schedule ? p.schedule.local + ' · ' + p.schedule.timezone : 'Schedule not set'}<br />Assigned to: {p.assignee_name || 'Not assigned'}</p></>}
        {action.result_id && <p>Receipt: {email ? 'email' : 'job'} #{action.result_id}</p>}
        {['unknown', 'sending'].includes(action.status) && <p>The outcome needs confirmation. Refresh the record; sending again is blocked.</p>}
        {pending && <>
          <label><input type="checkbox" checked={reviewed.includes(key)} onChange={event => setReviewed(event.target.checked ? [...reviewed, key] : reviewed.filter(item => item !== key))} />I reviewed the exact {agent ? 'agent instructions, schedule and allowance' : email ? 'recipient, sender, subject and message' : 'customer and job details'}.</label>
          <div className="assistant-actions"><button className="assistant-primary" disabled={busy || !reviewed.includes(key) || (email && !p.sender.ready)} onClick={() => decide(action, true)}>{agent ? 'Create follow-up agent' : email ? 'Send email' : 'Create job'}</button><button disabled={busy} onClick={() => decide(action, false)}>Discard</button></div>
        </>}
      </article>
    })}
  </section>
}
