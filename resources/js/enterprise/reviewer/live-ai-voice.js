import { createVoiceConversation } from '../../ai-voice-conversation.js'

/** Speech is only an input/output adapter to the exact reviewed text client.
 * Every recognized question still waits for its own visible sharing confirmation.
 * Stop retires audio callbacks, never cancels/refunds an already dispatched receipt.
 */
export function createReviewerVoice({ client, Recognition, Utterance, synthesis, onState, onTranscript, onError, voiceOptions = {} }) {
  let waiting = null, closed = false
  const rejectWaiting = message => {
    if (!waiting) return
    const previous = waiting; waiting = null; previous.cleanup(); previous.reject(Error(message))
  }
  const voice = createVoiceConversation({ ...voiceOptions, Recognition, Utterance, synthesis,
    onState, onTranscript, onError,
    send: (question, { signal }) => new Promise((resolve, reject) => {
      if (closed || waiting || signal.aborted || client.read().pending) { reject(Error('Resolve the retained request or use text before starting voice.')); return }
      if (/^(stop|cancel|stop listening|stop talking)[.!?]*$/i.test(question.trim())) { voice.stop(); reject(Error('Voice stopped.')); return }
      if (question.length > 1500) { reject(Error('Shorten the question to 1,500 characters or type instead.')); return }
      const abort = () => {
        rejectWaiting('Voice stopped. Any submitted request keeps its saved status and cost.')
        if (['preparing', 'preview', 'checking'].includes(client.read().phase) && !client.read().pending) client.invalidate()
      }
      waiting = { question: question.trim(), previewId: null, resolve, reject,
        cleanup: () => signal.removeEventListener('abort', abort) }
      signal.addEventListener('abort', abort, { once: true })
      Promise.resolve(client.prepare(question)).catch(() => rejectWaiting('The sharing preview is unavailable. Type instead.'))
    }) })
  return Object.freeze({
    start() {
      if (closed || client.read().pending) { onError('Check the saved request before starting voice.'); return }
      voice.start()
    },
    stop: () => voice.stop(), interrupt: () => voice.interrupt(), isActive: () => voice.isActive(),
    observe(state) {
      if (!waiting) return
      if (state.question !== waiting.question) { rejectWaiting('The question changed. Review the typed draft.'); return }
      if (state.phase === 'preview') waiting.previewId = state.preview.id
      if (state.phase === 'accepted' && waiting.previewId && state.result?.preview_id === waiting.previewId) {
        const previous = waiting; waiting = null; previous.cleanup(); previous.resolve(state.result.answer)
      } else if (['idle', 'stale', 'settled_expired', 'uncertain', 'prepared', 'rejected'].includes(state.phase)) {
        rejectWaiting(state.error || 'No spoken answer is available. Continue with text or check saved status.')
      }
    },
    destroy() { closed = true; voice.stop(); rejectWaiting('Voice controls are closed.') },
  })
}
