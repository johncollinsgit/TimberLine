<?php

namespace App\Services\FieldService;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FieldVoiceTranscriptionService
{
    /** @return array{transcript:string,material:?array{name:string,quantity:float,unit:string},provider:string,model:string,review_required:bool} */
    public function transcribe(UploadedFile $audio, string $context): array
    {
        $apiKey = trim((string) config('services.openai.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('Voice transcription is not configured yet.');
        }

        $model = (string) config('services.openai.field_voice_model', 'gpt-transcribe');
        $stream = fopen($audio->getRealPath(), 'r');
        if ($stream === false) {
            throw new RuntimeException('The recording could not be read.');
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(75)
                ->retry(2, 350, throw: false)
                ->attach('file', $stream, $audio->getClientOriginalName() ?: 'field-recording.m4a')
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => $model,
                    'language' => 'en',
                    'response_format' => 'json',
                    'prompt' => $this->prompt($context),
                ]);
        } finally {
            fclose($stream);
        }

        if (! $response->successful()) {
            throw new RuntimeException('The recording could not be transcribed. Please try again.');
        }

        $transcript = trim((string) $response->json('text'));
        if ($transcript === '') {
            throw new RuntimeException('No speech was detected. Please try again.');
        }

        return [
            'transcript' => $transcript,
            'material' => $context === 'material_request' ? $this->material($transcript) : null,
            'provider' => 'openai',
            'model' => $model,
            'review_required' => true,
        ];
    }

    private function prompt(string $context): string
    {
        $purpose = $context === 'material_request'
            ? 'This is an electrical material request. Preserve quantities, sizes, units, and product names exactly.'
            : 'This is a concise field-service job note. Preserve names, measurements, circuit numbers, and technical details.';

        return $purpose.' Collins Upstate Electric vocabulary may include Romex, THHN, EMT, MC cable, GFCI, AFCI, receptacle, breaker, panel, disconnect, conduit, wire gauge, feet, boxes, and fixtures. Use punctuation and readable sentences.';
    }

    /** @return array{name:string,quantity:float,unit:string} */
    private function material(string $transcript): array
    {
        $quantity = 1.0;
        $unit = 'item';
        $name = trim($transcript, " \t\n\r\0\x0B.");

        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(feet|foot|ft|boxes?|rolls?|spools?|pieces?|pcs?|each|ea)?\s+(?:of\s+)?(.+)$/i', $name, $matches) === 1) {
            $quantity = (float) $matches[1];
            $rawUnit = strtolower((string) ($matches[2] ?? ''));
            $name = trim($matches[3]);
            $unit = match ($rawUnit) {
                'feet', 'foot', 'ft' => 'ft',
                'box', 'boxes' => 'box',
                'roll', 'rolls' => 'roll',
                'spool', 'spools' => 'spool',
                'piece', 'pieces', 'pc', 'pcs' => 'piece',
                'each', 'ea' => 'each',
                default => 'item',
            };
        }

        return ['name' => $name, 'quantity' => $quantity, 'unit' => $unit];
    }
}
