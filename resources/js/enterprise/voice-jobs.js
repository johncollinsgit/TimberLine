import { createVoiceConversation } from '../ai-voice-conversation.js'

const followup = /^(?:(?:and |so )?(?:where(?: is|['’]s) (?:it|that|this)(?: located| at)?|who(?: is|['’]s)? (?:working on|handling|owns) (?:it|that|this)|what(?: is| are|['’]s)? (?:its|the) (?:status|due date|next action|blockers)|when is (?:it|that|this) due|tell me more(?: about (?:it|that|this))?))[?.!]*$/i
const controls = /^(?:stop|cancel|stop listening|stop talking)[?.!]*$/i

/** Adds voice to the existing Ask pane. All records come from the authorized transport. */
export function createVoiceJobs({ context, lookup, openJob, onReply, onState, onTranscript, onError,
  Recognition, Utterance, synthesis, now = () => performance.now(), voiceOptions = {} }) {
  let generation = 0, selected = null, choices = [], waiting = null, disposed = false
  let boundScope = null
  const scope = () => {
    const value = context()
    if (!value || !['private', 'sample'].includes(value.runtime) || !value.tenantId || !value.userId || !value.areaId
      || (value.runtime === 'private' && value.authenticated !== true)) throw new Error('Current authorized workspace context is unavailable.')
    return { ...value, stamp: JSON.stringify([value.runtime, value.tenantId, value.userId, value.areaId]) }
  }
  const checkScope = () => {
    const current = scope()
    if (boundScope !== current.stamp) { selected = null; choices = []; boundScope = current.stamp }
    return current
  }
  const query = async (question, { signal, focus } = {}) => {
    if (disposed) throw new Error('Voice controls are closed.')
    const current = checkScope(), token = generation
    if (controls.test(question.trim())) { stop(); return '' }
    if (!question.trim() || question.length > 1500) throw new Error('Use a shorter job question, up to 1,500 characters.')
    if (waiting) throw new Error('A job lookup is still pending. Stop and refresh before asking again.')
    const controller = new AbortController(); waiting = controller
    const cancel = () => controller.abort()
    signal?.addEventListener('abort', cancel, { once: true })
    if (signal?.aborted) controller.abort()
    const started = now()
    const valid = () => !disposed && !controller.signal.aborted && token === generation && scope().stamp === current.stamp
    try {
      const result = await lookup({ area_id: current.areaId, question: question.trim(),
        ...((focus || (selected && followup.test(question.trim()))) ? { focus: focus || selected } : {}) }, { signal: controller.signal })
      if (!valid()) return ''
      if (result.mode !== 'record_backed' || result.provider_dispatch !== false ||
        String(result.scope?.tenant_id) !== String(current.tenantId) || String(result.scope?.user_id) !== String(current.userId) || String(result.scope?.area_id) !== String(current.areaId) ||
        !['matched', 'choose', 'no_match', 'review_required'].includes(result.outcome) || typeof result.reply !== 'string') {
        throw new Error('The current job scope could not be verified. Refresh before continuing.')
      }
      choices = result.outcome === 'choose' ? (result.candidates || []).slice(0, 10) : []
      if (result.outcome === 'matched') {
        if (!result.job?.id) throw new Error('No verified job was returned.')
        // The shell opens its existing detail/map pane and protects unsaved work.
        // Navigation callbacks must honor this signal before committing UI changes.
        const opened = await openJob(result.job, { signal: controller.signal })
        if (!valid()) return ''
        selected = result.job.id
        onReply({ ...result, opened: opened === true, latency_ms: Math.max(0, Math.round(now() - started)) })
      } else {
        selected = null
        onReply({ ...result, latency_ms: Math.max(0, Math.round(now() - started)) })
      }
      return result.reply
    } finally {
      signal?.removeEventListener('abort', cancel)
      if (waiting === controller) waiting = null
    }
  }
  const voice = createVoiceConversation({ ...voiceOptions, Recognition, Utterance, synthesis,
    send: query, onState, onTranscript, onError })
  function stop() { generation++; waiting?.abort(); voice.stop() }
  return {
    start() { try { if (disposed) return; checkScope(); voice.start() } catch (error) { onError(error.message) } },
    stop,
    interrupt: () => voice.interrupt(),
    async ask(question) { stop(); return query(question) },
    async choose(id) {
      if (!choices.some(row => row.id === id)) throw new Error('Choose one of the current permitted jobs.')
      stop(); return query('Show the selected job', { focus: id })
    },
    contextChanged() { stop(); selected = null; choices = []; boundScope = null },
    dispose() { stop(); disposed = true; selected = null; choices = [] },
    isActive: () => voice.isActive(),
  }
}

/** Optional controls only; the owner supplies the existing Ask log and job opener. */
export function mountVoiceJobs(host, options) {
  const doc = host.ownerDocument
  const talk = doc.createElement('button'), stop = doc.createElement('button'), interrupt = doc.createElement('button'), status = doc.createElement('p'), disclosure = doc.createElement('p')
  for (const button of [talk, stop, interrupt]) { button.type = 'button'; button.className = 'button' }
  talk.textContent = 'Talk'; stop.textContent = 'Stop'; interrupt.textContent = 'Interrupt reply'
  status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite')
  disclosure.textContent = 'Ask Everbranch · Record-backed job lookup. Browser speech may be processed online. No generative AI or message sending is connected here. You can type instead.'
  disclosure.className = 'caption'; host.append(talk, stop, interrupt, status, disclosure)
  const labels = { idle: 'Ready. Microphone off.', starting: 'Starting microphone…', listening: 'Listening. Finish speaking to look up a job.', thinking: 'Checking permitted job records. Microphone paused.', speaking: 'Speaking. Microphone paused.' }
  const session = createVoiceJobs({ ...options,
    onState(phase) { status.textContent = labels[phase]; talk.disabled = phase !== 'idle'; stop.disabled = phase === 'idle'; interrupt.disabled = phase !== 'speaking'; options.onState?.(phase) },
    onError(message) { status.textContent = message; options.onError?.(message) } })
  talk.onclick = () => session.start(); stop.onclick = () => session.stop(); interrupt.onclick = () => session.interrupt()
  const hidden = () => { if (doc.hidden) session.stop() }, pagehide = () => session.stop()
  doc.addEventListener('visibilitychange', hidden); doc.defaultView.addEventListener('pagehide', pagehide)
  stop.disabled = true; interrupt.disabled = true; status.textContent = labels.idle
  return { ...session, dispose() { session.dispose(); doc.removeEventListener('visibilitychange', hidden); doc.defaultView.removeEventListener('pagehide', pagehide); host.replaceChildren() } }
}
