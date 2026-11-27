<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

test('session refresh returns a private token only for the signed-in viewer from the original tab', function (): void {
    config(['trajectory.enabled' => true]);
    $this->withoutVite();
    $viewer = User::factory()->create();
    $header = ['X-Trajectory-Viewer' => (string) $viewer->id];
    $this->getJson('/trajectory/session-token', $header)->assertUnauthorized();
    $this->actingAs($viewer)->get('/trajectory')->assertOk()->assertSee('data-viewer-id="'.$viewer->id.'"', false);
    $this->getJson('/trajectory/session-token')->assertForbidden();
    $this->getJson('/trajectory/session-token', ['X-Trajectory-Viewer' => (string) ($viewer->id + 1)])->assertForbidden();
    $response = $this->getJson('/trajectory/session-token', $header)->assertOk()->assertExactJson(['csrf_token' => session()->token()]);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    config(['trajectory.enabled' => false]);
    $this->getJson('/trajectory/session-token', $header)->assertNotFound();
});

test('an old token cannot change a transaction and a refreshed token still requires its original version', function (): void {
    config(['trajectory.enabled' => true]);
    $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
    $viewer = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$space] = app(PilotService::class)->prepare($viewer, null, true);
    $account = Account::create(['space_id' => $space->id, 'source_key' => 'aged-tab:cash', 'name' => 'Example account',
        'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 10000, 'observed_at' => now()]);
    app(LedgerService::class)->ingest($account, [['id' => 'example', 'date' => '2026-11-10', 'merchant' => 'Example purchase', 'amount_cents' => -1750]]);
    $tx = Transaction::where('source_key', 'aged-tab:cash:example')->sole();
    $path = '/trajectory/spaces/'.$space->id.'/transactions/'.$tx->id;
    $payload = ['category' => 'groceries', 'flow' => 'expense', 'version' => $tx->version, 'face_punched' => false, 'bullshit_spending' => false, 'learn' => false];
    $this->actingAs($viewer)->patchJson($path, $payload, ['X-CSRF-TOKEN' => 'stale-token'])
        ->assertStatus(419)->assertJsonPath('message', 'CSRF token mismatch.');
    expect($tx->fresh()->version)->toBe($tx->version);
    $token = $this->getJson('/trajectory/session-token', ['X-Trajectory-Viewer' => (string) $viewer->id])->assertOk()->json('csrf_token');
    $this->patchJson($path, $payload, ['X-CSRF-TOKEN' => $token])->assertOk();
    expect($tx->fresh()->category)->toBe('groceries')->and($tx->fresh()->version)->toBe($tx->version + 1);
    $this->patchJson($path, $payload, ['X-CSRF-TOKEN' => $token])->assertConflict();
});
