<?php

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('bud.level_foundations_transcription_enabled', true);
    config()->set('bud.level_foundations_transcription_token', 'level-foundations-test-token');
    config()->set('bud.provider', 'openai');
    config()->set('bud.provider_configured', true);
    config()->set('bud.ai_api_key', 'provider-test-key');
});

test('the Level Foundations transcription bridge requires its integration token', function (): void {
    Http::fake();

    $this->post(route('integrations.level-foundations.transcriptions.store'), [
        'audio' => UploadedFile::fake()->create('foundation.webm', 200, 'audio/webm'),
    ])->assertUnauthorized();

    Http::assertNothingSent();
});

test('the Level Foundations transcription bridge remains disabled until explicitly enabled', function (): void {
    config()->set('bud.level_foundations_transcription_enabled', false);
    Http::fake();

    $this->withToken('level-foundations-test-token')->post(route('integrations.level-foundations.transcriptions.store'), [
        'audio' => UploadedFile::fake()->create('foundation.webm', 200, 'audio/webm'),
    ])->assertStatus(503);

    Http::assertNothingSent();
});

test('the Level Foundations transcription bridge forwards an authorized valid recording without exposing the provider credential', function (): void {
    Http::fake([
        'https://api.openai.com/v1/audio/transcriptions' => Http::response([
            'text' => 'A wise builder built his house on the rock.',
        ]),
    ]);

    $this->withToken('level-foundations-test-token')->post(route('integrations.level-foundations.transcriptions.store'), [
        'audio' => UploadedFile::fake()->create('building-on-the-rock.webm', 200, 'audio/webm'),
        'title' => 'Building on the Rock',
    ])->assertOk()->assertExactJson([
        'text' => 'A wise builder built his house on the rock.',
    ]);

    Http::assertSent(function (ClientRequest $request): bool {
        return $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
            && $request->method() === 'POST';
    });
});
