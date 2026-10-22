import { createVoiceConversation } from '../ai-voice-conversation.js'

const followup = /^(?:(?:and |so )?(?:where(?: is|['']s) (?:it|that|this)(?: located| at)?|who(?: is|['']s)? (?:working on|handling|owns) (?:it|that|this)|who(?: is|['']s)? assigned(?: to (?:it|that|this))?|what(?: is| are|['']s)? (?:its|the) (?:status|due date|next action|blockers)|when is (?:it|that|this) due|tell me more(?: about (?:it|that|this))?))[?.!]*$/i
const controls = /^(?:stop|cancel|stop listening|stop talking)[?.!]*$/i

/** Adds voice to the existing Ask pane. All records come from the authorized transport. */
export function createVoiceJobs({ context, lookup, openJob, onReply, onState, onTranscript, onError,
  generate, cancelSession, preparePreview, onPreview, uuid = () => crypto.randomUUID(),
  Recognition, Utterance, synthesis, now = () => performance.now(), voiceOptions = {} }) {
  let generation = 0, selected = null, choices = [], waiting = null, disposed = false
  let boundScope = null, remote = null, proposal = null
  const cancelRemote = (expected = remote) => {
    if (!expected || expected !== remote) return
    const previous = remote; remote = null; proposal = null
    if (previous) Promise.resolve().then(() => cancelSession({ area_id: previous.area, session_id: previous.id }))
      .catch(() => { if (!disposed) onError?.('Stopped locally. Server cancellation is unconfirmed; accepted provider costs may remain. Start a new session before continuing.') })
  }
  const scope = () => {
    const value = context()
    if (!value || !['private', 'sample'].includes(value.runtime) || !value.tenantId || !value.userId || !value.areaId
      || (value.runtime === 'private' && value.authenticated !== true)) throw new Error('Current authorized workspace context is unavailable.')
    return { ...value, stamp: JSON.stringify([value.runtime, value.tenantId, value.userId, value.areaId]) }
  }
  const checkScope = () => {
    const current = scope()
    if (boundScope !== current.stamp) { cancelRemote(); selected = null; choices = []; boundScope = current.stamp }
    return current
  }
  const query = async (question, { signal, focus, recordOnly = false } = {}) => {
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
    let ownRemote = null
    const valid = () => !disposed && !controller.signal.aborted && token === generation && scope().stamp === current.stamp
    try {
      const generated = !recordOnly && current.runtime === 'private' && typeof generate === 'function'
      if (generated && typeof cancelSession !== 'function') throw new Error('Server cancellation is required before generative voice is connected.')
      if (generated && !remote) remote = { id: uuid(), area: current.areaId }
      ownRemote = generated ? remote : null
      const requestId = generated ? uuid() : null
      const result = await (generated ? generate : lookup)({ area_id: current.areaId, question: question.trim(),
        ...((focus || (selected && followup.test(question.trim()))) ? { focus: focus || selected } : {}),
        ...(generated ? { session_id: remote.id, request_id: requestId } : {}) }, { signal: controller.signal })
      if (!valid()) { cancelRemote(ownRemote); return '' }
      const isGenerated = generated && result.mode === 'generated' && result.provider_dispatch === true
        && result.session_id === remote?.id && result.request_id === requestId && typeof result.provider_simulated === 'boolean'
      const isLookup = result.mode === 'record_backed' && result.provider_dispatch === false
      if ((!isGenerated && !isLookup) ||
        String(result.scope?.tenant_id) !== String(current.tenantId) || String(result.scope?.user_id) !== String(current.userId) || String(result.scope?.area_id) !== String(current.areaId) ||
        !(isGenerated ? ['matched', 'choose', 'answered', 'draft_proposed'] : ['matched', 'choose', 'no_match', 'review_required']).includes(result.outcome) || typeof result.reply !== 'string') {
        throw new Error('The current job scope could not be verified. Refresh before continuing.')
      }
      if (isLookup && remote === ownRemote) remote = null
      proposal = isGenerated && result.outcome === 'draft_proposed' ? { session: remote.id, turn: requestId, area: current.areaId } : null
      choices = result.outcome === 'choose' ? (result.candidates || []).slice(0, 10) : []
      if (result.outcome === 'matched') {
        if (!result.job?.id) throw new Error('No verified job was returned.')
        // The shell opens its existing detail/map pane and protects unsaved work.
        // Navigation callbacks must honor this signal before committing UI changes.
        const opened = await openJob(result.job, { signal: controller.signal })
        if (!valid()) { cancelRemote(ownRemote); return '' }
        selected = result.job.id
        onReply({ ...result, opened: opened === true, latency_ms: Math.max(0, Math.round(now() - started)) })
      } else {
        selected = null
        onReply({ ...result, latency_ms: Math.max(0, Math.round(now() - started)) })
      }
      return result.reply
    } catch (error) {
      cancelRemote(ownRemote)
      throw error
    } finally {
      signal?.removeEventListener('abort', cancel)
      if (waiting === controller) waiting = null
    }
  }
  const voice = createVoiceConversation({ ...voiceOptions, Recognition, Utterance, synthesis,
    continueListening: () => !proposal,
    send: query, onState, onTranscript, onError(message) { cancelRemote(); onError?.(message) } })
  function stop() { generation++; waiting?.abort(); waiting = null; cancelRemote(); voice.stop() }
  return {
    start() { try { if (disposed) return; checkScope(); voice.start() } catch (error) { onError(error.message) } },
    stop,
    interrupt: () => voice.interrupt(),
    async ask(question) { stop(); return query(question) },
    async choose(id) {
      if (!choices.some(row => row.id === id)) throw new Error('Choose one of the current permitted jobs.')
      stop(); return query('Show the selected job', { focus: id, recordOnly: true })
    },
    async previewDraft(to) {
      const current = checkScope(), draft = proposal, token = generation
      if (disposed || !draft || !preparePreview || waiting || current.runtime !== 'private') throw new Error('Choose a current generated draft before previewing its recipient.')
      const controller = new AbortController(); waiting = controller
      try {
        const preview = await preparePreview({ area_id: draft.area, session_id: draft.session, turn_id: draft.turn, preview_id: uuid(), to }, { signal: controller.signal })
        if (disposed || controller.signal.aborted || token !== generation || current.stamp !== scope().stamp || remote?.id !== draft.session || proposal !== draft) return null
        if (preview.session_id !== draft.session || preview.to !== to || preview.status !== 'preview_only' || preview.delivery_ready !== false || preview.can_confirm !== false || preview.recipient_verified !== false
          || typeof preview.body !== 'string' || typeof preview.subject !== 'string') throw new Error('The exact draft preview could not be verified.')
        onPreview?.(preview)
        return preview
      } finally { if (waiting === controller) waiting = null }
    },
    contextChanged() { stop(); selected = null; choices = []; boundScope = null },
    dispose() { stop(); disposed = true; selected = null; choices = [] },
    isActive: () => voice.isActive(),
    hasDraft: () => !!proposal,
  }
}

/** Optional controls only; the owner supplies the existing Ask log and job opener. */
export function mountVoiceJobs(host, options) {
  const doc = host.ownerDocument
  const talk = doc.createElement('button'), stop = doc.createElement('button'), interrupt = doc.createElement('button'), status = doc.createElement('p'), disclosure = doc.createElement('p')
  for (const button of [talk, stop, interrupt]) { button.type = 'button'; button.className = 'button' }
  talk.textContent = 'Talk'; stop.textContent = 'Stop'; interrupt.textContent = 'Interrupt reply'
  status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite')
  const lookupDisclosure = 'Ask Everbranch - Record-backed job lookup. Browser speech may be processed online. No message sending is connected here. You can type instead.'
  disclosure.textContent = lookupDisclosure
  disclosure.className = 'caption'; host.append(talk, stop, interrupt, status, disclosure)
  const draft = doc.createElement('section'), draftText = doc.createElement('pre'), recipient = doc.createElement('input'), previewButton = doc.createElement('button'), exactPreview = doc.createElement('pre')
  draft.hidden = true; recipient.type = 'text'; recipient.maxLength = 254; recipient.setAttribute('aria-label', 'Exact draft recipient'); recipient.autocomplete = 'off'
  previewButton.type = 'button'; previewButton.textContent = 'Preview draft'; draftText.style.whiteSpace = exactPreview.style.whiteSpace = 'pre-wrap'
  draft.append(draftText, recipient, previewButton, exactPreview); host.append(draft)
  const clearDraft = () => { draft.hidden = true; draftText.textContent = ''; recipient.value = ''; exactPreview.textContent = '' }
  const labels = { idle: 'Ready. Microphone off.', starting: 'Starting microphone…', listening: 'Listening. Finish speaking to look up a job.', thinking: 'Checking permitted job records. Microphone paused.', speaking: 'Speaking. Microphone paused.' }
  const session = createVoiceJobs({ ...options,
    onState(phase) { if (phase === 'idle' && !session.hasDraft()) clearDraft(); status.textContent = labels[phase]; talk.disabled = phase !== 'idle'; stop.disabled = phase === 'idle' && draft.hidden; interrupt.disabled = phase !== 'speaking'; options.onState?.(phase) },
    onReply(result) {
      disclosure.textContent = result.mode === 'generated'
        ? `Ask Everbranch - ${result.provider_simulated ? 'Simulated provider response for testing' : 'AI-generated response from permitted job facts'}. ${result.coverage || ''} Nothing was sent or changed. Browser speech may be processed online.`
        : lookupDisclosure
      draft.hidden = result.outcome !== 'draft_proposed' || !options.preparePreview
      if (!draft.hidden) stop.disabled = false
      recipient.value = ''; exactPreview.textContent = ''
      draftText.textContent = draft.hidden ? '' : `Proposed ${result.draft_proposal.channel} draft (no recipient selected):\n${result.draft_proposal.subject}\n${result.draft_proposal.body}\nEnter the exact recipient to preview. Delivery is disconnected.`
      options.onReply?.(result)
    },
    onPreview(value) {
      exactPreview.textContent = `Exact ${value.channel} preview\nTo: ${value.to}\nSubject: ${value.subject}\n${value.body}\nDigest: ${value.digest || 'Not provided by fixture'}\nExpires: ${value.expires_at}\n${value.notice}`
      options.onPreview?.(value)
    },
    onError(message) { clearDraft(); stop.disabled = !session.isActive(); status.textContent = message; options.onError?.(message) } })
  previewButton.onclick = async () => {
    previewButton.disabled = true; exactPreview.textContent = ''
    try { await session.previewDraft(recipient.value) } catch (error) { status.textContent = error.message }
    finally { previewButton.disabled = false }
  }
  const stopReview = () => { clearDraft(); session.stop() }
  talk.onclick = () => { clearDraft(); session.start() }; stop.onclick = stopReview; interrupt.onclick = () => session.interrupt()
  const hidden = () => { if (doc.hidden) stopReview() }, pagehide = stopReview
  doc.addEventListener('visibilitychange', hidden); doc.defaultView.addEventListener('pagehide', pagehide)
  stop.disabled = true; interrupt.disabled = true; status.textContent = labels.idle
  return { ...session, stop: stopReview, contextChanged() { clearDraft(); session.contextChanged() }, dispose() { session.dispose(); doc.removeEventListener('visibilitychange', hidden); doc.defaultView.removeEventListener('pagehide', pagehide); host.replaceChildren() } }
}
