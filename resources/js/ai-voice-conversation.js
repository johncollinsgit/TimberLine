// One microphone turn -> the existing scoped chat -> spoken reply -> next turn.
// No recognition runs during playback and no callback survives Stop/replacement.
export function createVoiceConversation({ Recognition, Utterance, synthesis, send, onState, onTranscript, onError, language = 'en-US' }) {
  let active = false, epoch = 0, capture = null, timer = null, speech = null
  const stop = () => {
    active = false; epoch++; clearTimeout(timer)
    const previous = capture; capture = null; speech = null
    previous?.abort(); synthesis?.cancel(); onState('idle')
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
      capture = null; clearTimeout(timer)
      if (!transcript) { fail('No speech captured. Start voice conversation to try again.'); return }
      if (transcript.length > 3000) { fail('That question is too long. Shorten the draft and choose Ask.'); return }
      onState('thinking')
      try {
        const reply = await send(transcript)
        if (!active || turn !== epoch) return
        if (!reply) { fail('No spoken answer is available. Refresh the conversation before asking again.'); return }
        speech = new Utterance(reply); speech.lang = language
        const spoken = speech
        spoken.onend = () => {
          if (!active || turn !== epoch || speech !== spoken) return
          speech = null; clearTimeout(timer); onState('starting')
          timer = setTimeout(() => listen(turn), 350)
        }
        spoken.onerror = () => { if (active && turn === epoch && speech === spoken) fail('Playback stopped. Your answer is in the conversation. Start voice conversation to continue.') }
        onState('speaking'); synthesis.speak(spoken)
        timer = setTimeout(() => { if (active && turn === epoch && speech === spoken) fail('Playback paused. Your answer is in the conversation. Start voice conversation to continue.') }, 180_000)
      } catch (error) { if (active && turn === epoch) fail(error.message || 'Voice conversation stopped. Refresh before asking again.') }
    }
    try {
      onState('starting'); current.start()
      timer = setTimeout(() => { if (valid()) fail('Listening timed out. Start voice conversation to continue.') }, 90_000)
    } catch { fail('Microphone unavailable. You can type instead.') }
  }
  return {
    start() {
      stop()
      if (!Recognition || !Utterance || !synthesis) { onError('Voice conversation is unavailable in this browser. Use text or voice to text.'); return }
      active = true; listen(epoch)
    },
    stop,
    isActive: () => active,
  }
}
