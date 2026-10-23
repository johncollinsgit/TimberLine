<?php

use App\Services\Trajectory\GeraldAudioProvider;
use WebSocket\Client;
use WebSocket\Message\Text;

test('voice uploads reject malformed or excessive audio before a provider can be called', function () {
    $audio = app(GeraldAudioProvider::class);
    expect($audio->duration($audio->wave(str_repeat("\0", 48000))))->toBe(1.0);
    expect(fn () => $audio->duration($audio->wave(str_repeat("\0", 1440002))))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $audio->duration(str_repeat('x', 100)))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('natural speech is British cedar, verifies its words, and meters cached text and audio separately', function () {
    $audio = Mockery::mock(GeraldAudioProvider::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $client = Mockery::mock(Client::class);
    $audio->shouldReceive('client')->once()->andReturn($client);
    $sent = [];
    $client->shouldReceive('text')->times(3)->andReturnUsing(function ($text) use (&$sent) {
        $sent[] = json_decode($text, true);

        return new Text($text);
    });
    $usage = ['input_tokens' => 100, 'output_tokens' => 210, 'input_token_details' => ['text_tokens' => 100, 'audio_tokens' => 0, 'cached_tokens' => 20], 'output_token_details' => ['text_tokens' => 10, 'audio_tokens' => 200]];
    $events = [['type' => 'session.updated'], ['type' => 'response.output_audio.delta', 'delta' => base64_encode(str_repeat("\0", 4800))], ['type' => 'response.output_audio_transcript.done', 'transcript' => 'Income needed: three thousand nine hundred dollars.'], ['type' => 'response.done', 'response' => ['id' => 'resp_speech', 'status' => 'completed', 'usage' => $usage]]];
    $client->shouldReceive('receive')->times(4)->andReturn(...array_map(fn ($e) => new Text(json_encode($e)), $events));
    $client->shouldReceive('close')->once();
    $result = $audio->speak('Income needed: $3,900.00.');
    expect($result['value']['verified'])->toBeTrue()->and(base64_decode($result['value']['audio']))->toStartWith('RIFF')
        ->and($result['cost_micros'])->toBe(4074)->and($sent[0]['session']['audio']['output']['voice'])->toBe('cedar')
        ->and($sent[0]['session']['instructions'])->toContain('British English accent');
});

test('unverified spoken amounts never become playable audio', function () {
    $audio = Mockery::mock(GeraldAudioProvider::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $client = Mockery::mock(Client::class);
    $audio->shouldReceive('client')->andReturn($client);
    $client->shouldReceive('text')->andReturn(new Text(''));
    $usage = ['input_tokens' => 100, 'output_tokens' => 200, 'input_token_details' => ['text_tokens' => 100, 'audio_tokens' => 0, 'cached_tokens' => 0], 'output_token_details' => ['text_tokens' => 0, 'audio_tokens' => 200]];
    $events = [['type' => 'session.updated'], ['type' => 'response.output_audio.delta', 'delta' => base64_encode(str_repeat("\0", 4800))], ['type' => 'response.output_audio_transcript.done', 'transcript' => 'Your debt is already paid.'], ['type' => 'response.done', 'response' => ['id' => 'resp_bad_speech', 'status' => 'completed', 'usage' => $usage]]];
    $client->shouldReceive('receive')->andReturn(...array_map(fn ($e) => new Text(json_encode($e)), $events));
    $client->shouldReceive('close');
    $result = $audio->speak('The remaining debt is $500.00.');
    expect($result['value']['verified'])->toBeFalse()->and($result['value']['audio'])->toBeNull()->and($result['cost_micros'])->toBeGreaterThan(0);
});
