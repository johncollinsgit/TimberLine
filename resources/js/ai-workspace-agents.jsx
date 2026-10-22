import React from 'react'

export default function WorkspaceAgents({ agents, busy, run, pause, openConversation }) {
  return <section aria-label="Workspace agents"><h3>Customer follow-up agents</h3>
    <p>Ask Everbranch to create a customer follow-up agent, describe its work, and choose when it runs. Each run prepares one email for your review.</p>
    {agents.map(agent => <article className="workspace-action" key={agent.id}>
      <h4>{agent.name} · {agent.active ? 'Active' : 'Paused'}</h4><p>{agent.instructions}</p>
      <p>{agent.cadence === 'daily' ? 'Daily at ' + agent.time + ' · ' + agent.timezone : 'Runs when you choose'}</p>
      <div className="assistant-actions"><button disabled={busy || !agent.active} onClick={() => run(agent)}>Run now</button><button disabled={busy || !agent.active} onClick={() => pause(agent)}>Pause agent</button></div>
      {agent.runs.map(row => <p key={row.id}>{new Date(row.created_at).toLocaleString()} · {row.status} {row.conversation_id && <button disabled={busy} onClick={() => openConversation(row.conversation_id)}>Open work</button>}</p>)}
    </article>)}
    <h3>On the roadmap</h3><ul><li>Job coordinator: organize scheduling, assignments and job follow-up.</li><li>Daily briefing agent: prepare a workspace summary and priorities.</li></ul>
  </section>
}
