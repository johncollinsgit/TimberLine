import { createReviewerVoice } from './reviewer/live-ai-voice.js'
import { createVoiceJobs, mountVoiceJobs } from './voice-jobs.js'

// The existing Enterprise shell owns where these controls mount and when they close.
// Loading the module never starts a microphone or requests a record/provider.
window.EverbranchReviewerVoice = Object.freeze({ create: createReviewerVoice })
window.EverbranchVoiceJobs = Object.freeze({ create: createVoiceJobs, mount: mountVoiceJobs })
window.dispatchEvent(new Event('everbranch:voice-jobs-ready'))
