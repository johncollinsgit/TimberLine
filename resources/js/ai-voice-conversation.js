// One microphone turn -> the existing scoped chat -> spoken reply -> next turn.
// No recognition runs during playback and no callback survives Stop/replacement.
export function createVoiceConversation({ Recognition, Utterance, synthesis, send, onState, onTranscript, onError, language = 'en-US',
  maxTurns = 12, maxSessionMs = 600_000, responseTimeoutMs = 45_000,
  clock = { setTimeout: (...args) => setTimeout(...args), clearTimeout: id => clearTimeout(id), now: () => performance.now() } }) {
  let active = false, epoch = 0, capture = null, timer = null, sessionTimer = null, speech = null, request = null, turns = 0, deadline = 0
  const clearTimer = () => { clock.clearTimeout(timer); timer = null }
  const stop = () => {
    active = false; epoch++; clearTimer(); clock.clearTimeout(sessionTimer)
    const previous = capture; capture = null; speech = null
    request?.abort(); request = null
    try { previous?.abort() } catch {}
    try { synthesis?.cancel() } catch {}
    onState('idle')
  }
  const fail = message => { stop(); onError(message) }
  const listen = turn => {
    if (!active || turn !== epoch) return
    let current
    try { current = new Recognition() } catch { fail('Microphone unavailable. You can type instead.'); return }
    capture = current
    current.lang = language; current.continuous = false; current.interimResults = false
    let transcript = ''
    const valid = () => active && turn === epoch && capture === current
    current.onstart = () => { if (valid()) onState('listening') }
    current.onresult = event => {
      if (!valid()) return
      transcript = Array.from(event.results).filter(result => result.isFinal).map(result => result[0]?.transcript || '').join(' ').trim()
      if (transcript) onTranscript(transcript)
    }
    current.onerror = event => {
      if (valid()) fail(event.error === 'not-allowed' || event.error === 'service-not-allowed'
        ? 'Microphone permission was denied. You can type instead.'
        : event.error === 'no-speech' ? 'No speech captured. Start voice conversation to try again.' : 'Speech recognition stopped. Start again or type instead.')
    }
    current.onend = async () => {
      if (!valid()) return
      capture = null; clearTimer()
      if (!transcript) { fail('No speech captured. Start voice conversation to try again.'); return }
      if (transcript.length > 3000) { fail('That question is too long. Shorten the draft and choose Ask.'); return }
      onState('thinking')
      const controller = new AbortController(); request = controller
      timer = clock.setTimeout(() => {
        if (active && turn === epoch) fail('Response timed out. The server may still finish. Refresh before asking again.')
      }, responseTimeoutMs)
      try {
        const reply = await send(transcript, { signal: controller.signal })
        if (!active || turn !== epoch) return
        request = null; clearTimer(); turns++
        if (!reply) { fail('No spoken answer is available. Refresh the conversation before asking again.'); return }
        speech = new Utterance(reply); speech.lang = language
        const spoken = speech
        spoken.onend = () => {
          if (!active || turn !== epoch || speech !== spoken) return
          speech = null; clearTimer()
          if (turns >= maxTurns) { fail('Voice turn limit reached. Start a new session to continue.'); return }
          onState('starting')
          timer = clock.setTimeout(() => listen(turn), 350)
        }
        spoken.onerror = () => { if (active && turn === epoch && speech === spoken) fail('Playback stopped. Your answer is in the conversation. Start voice conversation to continue.') }
        onState('speaking'); synthesis.speak(spoken)
        timer = clock.setTimeout(() => { if (active && turn === epoch && speech === spoken) fail('Playback paused. Your answer is in the conversation. Start voice conversation to continue.') }, 180_000)
      } catch (error) { if (active && turn === epoch) fail(error.message || 'Voice conversation stopped. Refresh before asking again.') }
    }
    try {
      onState('starting'); current.start()
      timer = clock.setTimeout(() => { if (valid()) fail('Listening timed out. Start voice conversation to continue.') }, 90_000)
    } catch { fail('Microphone unavailable. You can type instead.') }
  }
  return {
    start() {
      stop()
      if (!Recognition || !Utterance || !synthesis) { onError('Voice conversation is unavailable in this browser. Use text or voice to text.'); return }
      if (!Number.isInteger(maxTurns) || maxTurns < 1 || maxTurns > 20 || !Number.isFinite(maxSessionMs) || maxSessionMs < 1 || maxSessionMs > 600_000
        || !Number.isFinite(responseTimeoutMs) || responseTimeoutMs < 1 || responseTimeoutMs > 45_000) { onError('Voice session limits are invalid. Use text instead.'); return }
      active = true; turns = 0
      deadline = clock.now() + maxSessionMs
      sessionTimer = clock.setTimeout(() => fail('Voice session time limit reached. Start a new session to continue.'), Math.max(0, deadline - clock.now()))
      listen(epoch)
    },
    stop,
    interrupt() {
      if (!active || !speech) return false
      const completed = turns
      stop(); turns = completed; active = true
      sessionTimer = clock.setTimeout(() => fail('Voice session time limit reached. Start a new session to continue.'), Math.max(0, deadline - clock.now()))
      if (turns >= maxTurns) { fail('Voice turn limit reached. Start a new session to continue.'); return false }
      listen(epoch); return true
    },
    isActive: () => active,
  }
}
