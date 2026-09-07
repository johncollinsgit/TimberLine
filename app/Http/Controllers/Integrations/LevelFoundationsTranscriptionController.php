<?php

namespace App\Http\Controllers\Integrations;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class LevelFoundationsTranscriptionController
{
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasValidToken($request)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        if (! (bool) config('bud.level_foundations_transcription_enabled', false)) {
            return response()->json(['message' => 'Transcription is not available.'], 503);
        }

        if (
            (string) config('bud.provider', '') !== 'openai'
            || ! (bool) config('bud.provider_configured', false)
            || trim((string) config('bud.ai_api_key', '')) === ''
        ) {
            return response()->json(['message' => 'Transcription is not available.'], 503);
        }

        $validated = $request->validate([
            'audio' => ['required', 'file', 'max:25600', 'mimetypes:audio/webm,audio/mpeg,audio/mp4,audio/wav,audio/x-wav,audio/ogg,video/webm'],
            'title' => ['nullable', 'string', 'max:160'],
        ]);

        $audio = $request->file('audio');

        if ($audio === null || $audio->getRealPath() === false) {
            return response()->json(['message' => 'A readable audio recording is required.'], 422);
        }

        $response = Http::acceptJson()
            ->withToken((string) config('bud.ai_api_key'))
            ->timeout(90)
            ->attach('file', fopen($audio->getRealPath(), 'r'), $audio->getClientOriginalName())
            ->post('https://api.openai.com/v1/audio/transcriptions', [
                'model' => 'gpt-4o-transcribe',
                'language' => 'en',
                'prompt' => $this->transcriptionPrompt((string) ($validated['title'] ?? '')),
            ]);

        if (! $response->successful()) {
            return response()->json(['message' => 'The transcription service could not process this recording.'], 502);
        }

        $text = trim((string) $response->json('text', ''));

        if ($text === '') {
            return response()->json(['message' => 'The transcription service returned no text.'], 502);
        }

        return response()->json(['text' => $text]);
    }

    private function hasValidToken(Request $request): bool
    {
        $expected = trim((string) config('bud.level_foundations_transcription_token', ''));
        $provided = trim((string) $request->bearerToken());

        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }

    private function transcriptionPrompt(string $title): string
    {
        $context = 'Transcribe faithfully for Level Foundations, a Christian reflection library written by Ross Collins. Preserve Scripture references, names, punctuation, paragraphs, and deliberate pauses. Do not invent content.';

        return $title === '' ? $context : $context." The foundation title is: {$title}.";
    }
}
