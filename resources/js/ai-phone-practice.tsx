import { useEffect, useRef, useState } from 'react'

export type PhonePracticeResult = { version: number; simulated: boolean; simulation_id: string; scenario: string; outcome: string; disclosure: string; coverage: string; steps: string[]; routing: { route: string; policy_version: number }; call: { status: string; requires_review: boolean; needs_follow_up: boolean }; knowledge: { name: string; version: number; fictional: boolean }; provider_capabilities: { live_dispatch: boolean } }
const scenarios = [
  ['open_hours', 'A call during business hours'], ['closed_hours', 'A call after hours'], ['staff_unavailable', 'No staff are available'],
  ['transfer_declined', 'A person declines the transfer'], ['callback_requested', 'A caller requests a callback'], ['private_data_request', 'A caller asks for private business data'], ['provider_outage', 'The phone provider is unavailable'],
] as const

/** Scripted sandbox; it is not a live receptionist or an emergency service. */
export default function PhonePractice({ run }: { run: (scenario: string, uuid: string, signal: AbortSignal) => Promise<PhonePracticeResult> }) {
  const [scenario, setScenario] = useState<string>('open_hours')
  const [result, setResult] = useState<PhonePracticeResult | null>(null)
  const [notice, setNotice] = useState('Choose a fictional scenario, then start practice.')
  const [busy, setBusy] = useState(false)
  const epoch = useRef(0)
  const abort = useRef<AbortController | null>(null)
  const cancel = () => { ++epoch.current; abort.current?.abort() }
  useEffect(() => cancel, [])
  async function practice() {
    if (busy) return
    const turn = ++epoch.current; abort.current?.abort(); const controller = new AbortController(); abort.current = controller
    setBusy(true); setResult(null)
    try {
      const value = await run(scenario, crypto.randomUUID(), controller.signal)
      if (turn !== epoch.current) return
      if (value.version !== 1 || value.simulated !== true || value.knowledge.fictional !== true || value.provider_capabilities.live_dispatch !== false) throw new Error('This phone sandbox contract is unavailable.')
      setResult(value); setNotice('Fictional practice complete. Review the routing decision and recovery steps.')
    } catch (error) { if (turn === epoch.current && !controller.signal.aborted) setNotice((error as Error).message) }
    finally { if (turn === epoch.current) setBusy(false) }
  }
  return <section className="assistant-phone-practice"><h3>Phone practice</h3><p>The sandbox uses fixed fictional data for Fictional Cedar Works. It never reaches a person or provider.</p>
    <p role="status" aria-live="polite">{notice}</p>
    <form onSubmit={event => { event.preventDefault(); void practice() }}><label>Fictional phone scenario<select value={scenario} disabled={busy} onChange={event => { setScenario(event.target.value); setResult(null) }}>{scenarios.map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
      <button className="assistant-primary primary-button" disabled={busy}>{busy ? 'Practicing…' : 'Run fictional practice'}</button></form>
    {result && <><p>{result.disclosure}</p><article><strong>Practice outcome: {result.outcome.replaceAll('_', ' ')}</strong><p>Routing: {result.routing.route.replaceAll('_', ' ')} · policy {result.routing.policy_version}</p><p>Call state (simulated): {result.call.status}</p>{result.steps.map((step, index) => <p key={index}>{index + 1}. {step}</p>)}</article>
      <small>{result.knowledge.name} · version {result.knowledge.version}</small><p>{result.coverage}</p><p>No number is provisioned. Emergency calling and real transfers are unavailable in this sandbox.</p>
    </>}
  </section>
}
