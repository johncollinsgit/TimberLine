// Shared verbatim with the native shell. API turns use the same saved text agent.
export function pcmWave(chunks, rate) {
  const inputLength = chunks.reduce((n, chunk) => n + chunk.length, 0)
  const input = new Float32Array(inputLength)
  let offset = 0
  for (const chunk of chunks) { input.set(chunk, offset); offset += chunk.length }
  const length = Math.min(480000, Math.floor(input.length * 16000 / rate))
  const buffer = new ArrayBuffer(44 + length * 2), view = new DataView(buffer)
  const ascii = (at, text) => { for (let i = 0; i < text.length; ++i) view.setUint8(at + i, text.charCodeAt(i)) }
  ascii(0, 'RIFF'); view.setUint32(4, buffer.byteLength - 8, true); ascii(8, 'WAVEfmt '); view.setUint32(16, 16, true)
  view.setUint16(20, 1, true); view.setUint16(22, 1, true); view.setUint32(24, 16000, true); view.setUint32(28, 32000, true)
  view.setUint16(32, 2, true); view.setUint16(34, 16, true); ascii(36, 'data'); view.setUint32(40, length * 2, true)
  for (let i = 0; i < length; ++i) {
    const sample = Math.max(-1, Math.min(1, input[Math.min(input.length - 1, Math.floor(i * rate / 16000))]))
    view.setInt16(44 + i * 2, Math.round(sample * (sample < 0 ? 32768 : 32767)), true)
  }
  return new Uint8Array(buffer)
}

export function createApiVoice({ transcribe, answer, speech, onState, onTranscript, onError, env = globalThis }) {
  let active = false, generation = 0, context = null, stream = null, source = null, processor = null, gain = null
  let playback = null, timer = null, controller = null, finish = null
  const state = phase => onState?.(phase)
  const release = () => {
    clearTimeout(timer); timer = null; finish = null
    if (processor) processor.onaudioprocess = null
    processor?.disconnect(); source?.disconnect(); gain?.disconnect()
    processor = source = gain = null
    stream?.getTracks().forEach(track => track.stop()); stream = null
  }
  const stop = () => {
    active = false; ++generation; controller?.abort(); controller = null; release()
    playback?.stop(); playback = null
    const old = context; context = null; old?.close().catch(() => {})
    state('idle')
  }
  const fail = error => { if (active) { stop(); onError?.(error?.message || 'Voice stopped. You can type your question.') } }
  const listen = async turn => {
    if (!active || generation !== turn) return
    state('starting')
    const pendingStream = await env.navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true } })
    if (!active || generation !== turn) { pendingStream.getTracks().forEach(track => track.stop()); return }
    stream = pendingStream
    source = context.createMediaStreamSource(stream); processor = context.createScriptProcessor(4096, 1, 1); gain = context.createGain(); gain.gain.value = 0
    source.connect(processor); processor.connect(gain); gain.connect(context.destination)
    let samples = 0, voiceSamples = 0, silence = 0, heard = false, chunks = [], ending = false
    const complete = async () => {
      if (ending || !active || generation !== turn) return
      ending = true
      const rate = context.sampleRate
      release()
      if (!heard || voiceSamples / rate < 0.15) { fail(new Error('No speech captured. Start again or type your question.')); return }
      try {
        state('thinking'); controller = new AbortController()
        const wav = pcmWave(chunks, rate); chunks = []
        let binary = ''; for (let i = 0; i < wav.length; i += 8192) binary += String.fromCharCode(...wav.subarray(i, i + 8192))
        const text = await transcribe(env.btoa(binary), env.crypto.randomUUID(), controller.signal)
        if (!active || generation !== turn) return
        onTranscript?.(text)
        const message = await answer(text, controller.signal)
        if (!active || generation !== turn) return
        if (!message?.uuid) throw new Error('Answer saved. Refresh the conversation to continue.')
        const result = await speech(message, env.crypto.randomUUID(), controller.signal)
        if (!active || generation !== turn) return
        const bytes = Uint8Array.from(env.atob(result.audio), char => char.charCodeAt(0))
        const buffer = await context.decodeAudioData(bytes.buffer)
        if (!active || generation !== turn) return
        playback = context.createBufferSource(); playback.buffer = buffer; playback.connect(context.destination)
        playback.onended = () => { playback = null; if (active && generation === turn) listen(turn).catch(fail) }
        state('speaking'); playback.start()
      } catch (error) { if (active && generation === turn) fail(error) }
    }
    finish = complete
    processor.onaudioprocess = event => {
      if (!active || generation !== turn || ending) return
      const chunk = new Float32Array(event.inputBuffer.getChannelData(0)), rate = context.sampleRate
      chunks.push(chunk); samples += chunk.length
      const rms = Math.sqrt(chunk.reduce((sum, value) => sum + value * value, 0) / chunk.length)
      if (rms > 0.015) { heard = true; voiceSamples += chunk.length; silence = 0 } else silence += chunk.length
      if (heard && silence / rate > 1.2 || samples / rate >= 30) void complete()
    }
    timer = setTimeout(() => { if (!heard) fail(new Error('No speech captured. Start again when ready.')) }, 10000)
    state('listening')
  }
  return {
    isActive: () => active,
    finish: () => finish?.(),
    stop,
    start: async () => {
      if (active) return
      if (!env.navigator?.mediaDevices?.getUserMedia || !env.AudioContext) { onError?.('API voice is unavailable on this device. Type instead.'); return }
      active = true; const turn = ++generation
      try { context = new env.AudioContext(); await context.resume(); if (active && generation === turn) await listen(turn) }
      catch (error) { if (active && generation === turn) fail(error) }
    },
  }
}
