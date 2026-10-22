<?php

use App\Models\TenantSupportTicket;
use App\SoftwareSupport\Transport\CampusTransport;
use App\SoftwareSupport\Transport\DepartmentBinding;
use App\SoftwareSupport\Transport\TestCampusClient;
use App\SoftwareSupport\Transport\TestReceiver;
use App\SoftwareSupport\Transport\TestSigner;
use App\SoftwareSupport\Transport\TestVerifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\FixtureCampusStore;
use Tests\Support\FixtureOwnerInbox;

require_once __DIR__.'/../../Support/SoftwareSupportTransportFixtures.php';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    config(['software_support.test_transport_enabled' => true]);
    $this->directory = sys_get_temp_dir().'/support-transport-'.Str::uuid();
    mkdir($this->directory);
    $this->campus = new FixtureCampusStore(new PDO('sqlite:'.$this->directory.'/campus.sqlite'));
    $this->campus->db->exec("INSERT INTO memberships VALUES ('campus-user:7','department-it',1),('campus-user:8','department-finance',1),('campus-user:9','department-other-client',1)");
    $this->owner = new FixtureOwnerInbox(new PDO('sqlite:'.$this->directory.'/owner.sqlite'));
    $campusKeys = sodium_crypto_sign_keypair();
    $ownerKeys = sodium_crypto_sign_keypair();
    $this->campusSigner = new TestSigner('campus-test-key', sodium_crypto_sign_secretkey($campusKeys));
    $this->ownerSigner = new TestSigner('owner-test-key', sodium_crypto_sign_secretkey($ownerKeys));
    $this->requestVerifier = new TestVerifier(['campus-test-key' => ['public_key' => sodium_crypto_sign_publickey($campusKeys), 'issuer' => 'fixture-campus']]);
    $this->responseVerifier = new TestVerifier(['owner-test-key' => ['public_key' => sodium_crypto_sign_publickey($ownerKeys), 'issuer' => 'fixture-owner']]);
    $this->bindings = [
        new DepartmentBinding('fixture-campus', 'campus-workspace:7', 'department-it', 'campus-user:7', 'owner-workspace:7', 'client-a', 'technology', ['submit', 'read', 'reply']),
        new DepartmentBinding('fixture-campus', 'campus-workspace:7', 'department-finance', 'campus-user:8', 'owner-workspace:7', 'client-a', 'finance', ['submit', 'read', 'reply']),
        new DepartmentBinding('fixture-campus', 'campus-workspace:7', 'department-other-client', 'campus-user:9', 'owner-workspace:7', 'client-b', 'technology', ['submit', 'read', 'reply']),
    ];
    $this->installReceiver = function (?array $bindings = null): void {
        app()->instance(TestReceiver::class, new TestReceiver('fixture-owner', $this->requestVerifier, $this->ownerSigner, $bindings ?? $this->bindings, $this->owner));
    };
    ($this->installReceiver)();
    $test = $this;
    $this->transport = new class($test) implements CampusTransport
    {
        public bool $loseResponse = false;

        public ?array $lastResponse = null;

        public function __construct(private $test) {}

        public function exchange(array $packet): array
        {
            $response = $this->test->postJson('/api/software-support/test-campus', $packet);
            abort_unless($response->isSuccessful(), $response->status());
            $this->lastResponse = $response->json();
            if ($this->loseResponse) {
                $this->loseResponse = false;
                throw new RuntimeException('Fixture response lost after owner commit.');
            }

            return $this->lastResponse;
        }
    };
    $this->client = new TestCampusClient($this->campus, $this->campus, $this->campusSigner, $this->responseVerifier, $this->transport, 'fixture-owner');
    $this->payload = ['subject' => 'Fictional campus software issue', 'category' => 'bug', 'priority' => 'high', 'body' => 'Private synthetic reproduction steps.'];
});

afterEach(function (): void {
    // PDO handles close with the test application; Windows may retain the temporary fixture files until exit.
    $this->campus = null;
    $this->owner = null;
});

it('authenticates both directions while preserving distinct campus and owner storage', function (): void {
    $receipt = $this->client->dispatch('department-it', (string) Str::uuid(), 'submit', $this->payload);
    expect($receipt)->toMatchArray(['receipt' => 'saved', 'human_response' => false]);
    $ticket = $this->client->dispatch('department-it', (string) Str::uuid(), 'read', ['reference' => $receipt['reference']]);
    expect($ticket['messages'])->toBe([$this->payload['body']])
        ->and($this->owner->db->query('SELECT owner_workspace FROM tickets')->fetchColumn())->toBe('owner-workspace:7')
        ->and($this->owner->db->query('SELECT origin FROM tickets')->fetchColumn())->toContain('campus-user:7')
        ->and($this->campus->db->query("SELECT count(*) FROM sqlite_master WHERE name='tickets'")->fetchColumn())->toBe(0)
        ->and(TenantSupportTicket::withoutGlobalScopes()->count())->toBe(0);
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

it('recovers a lost response after owner commit without duplicate tickets or replies', function (): void {
    $key = (string) Str::uuid();
    $this->transport->loseResponse = true;
    expect(fn () => $this->client->dispatch('department-it', $key, 'submit', $this->payload))->toThrow(RuntimeException::class);
    expect($this->campus->db->query('SELECT state FROM outbox')->fetchColumn())->toBe('uncertain');
    // Reopen both independent stores to rehearse interrupted process recovery.
    $this->campus = new FixtureCampusStore(new PDO('sqlite:'.$this->directory.'/campus.sqlite'));
    $this->owner = new FixtureOwnerInbox(new PDO('sqlite:'.$this->directory.'/owner.sqlite'));
    ($this->installReceiver)();
    $this->client = new TestCampusClient($this->campus, $this->campus, $this->campusSigner, $this->responseVerifier, $this->transport, 'fixture-owner');
    $receipt = $this->client->dispatch('department-it', $key, 'submit', $this->payload);
    expect($this->owner->db->query('SELECT count(*) FROM tickets')->fetchColumn())->toBe(1);
    $replyKey = (string) Str::uuid();
    $reply = ['reference' => $receipt['reference'], 'body' => 'Additional fictional details'];
    $this->transport->loseResponse = true;
    expect(fn () => $this->client->dispatch('department-it', $replyKey, 'reply', $reply))->toThrow(RuntimeException::class);
    $this->client->dispatch('department-it', $replyKey, 'reply', $reply);
    expect(json_decode($this->owner->db->query('SELECT messages FROM tickets')->fetchColumn(), true))->toHaveCount(2);
    expect(fn () => $this->client->dispatch('department-it', $key, 'submit', [...$this->payload, 'body' => 'Changed replay']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('rejects cross-department cross-client revoked campus membership and revoked owner mappings', function (): void {
    $receipt = $this->client->dispatch('department-it', (string) Str::uuid(), 'submit', $this->payload);
    foreach (['campus-user:8' => 'department-finance', 'campus-user:9' => 'department-other-client'] as $principal => $department) {
        $this->campus->sessionPrincipal = $principal;
        try {
            $this->client->dispatch($department, (string) Str::uuid(), 'read', ['reference' => $receipt['reference']]);
            test()->fail('Foreign ticket became visible.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            expect($error->getStatusCode())->toBe(404);
        }
    }
    $this->campus->sessionPrincipal = 'campus-user:7';
    $this->campus->db->exec("UPDATE memberships SET active=0 WHERE principal='campus-user:7'");
    expect(fn () => $this->client->dispatch('department-it', (string) Str::uuid(), 'read', ['reference' => $receipt['reference']]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->campus->db->exec("UPDATE memberships SET active=1 WHERE principal='campus-user:7'");
    ($this->installReceiver)([]);
    expect(fn () => $this->client->dispatch('department-it', (string) Str::uuid(), 'read', ['reference' => $receipt['reference']]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('rejects tampering expiry wrong audience direction and client-injected native grants', function (): void {
    $command = ['version' => 1, 'issuer' => 'fixture-campus', 'audience' => 'fixture-owner', 'campus_workspace' => 'campus-workspace:7',
        'department' => 'department-it', 'principal' => 'campus-user:7', 'request_key' => (string) Str::uuid(), 'operation' => 'submit', 'payload' => $this->payload];
    $body = json_encode($command);
    $packet = $this->campusSigner->sign('campus-request', $body)->wire();
    $this->postJson('/api/software-support/test-campus', [...$packet, 'body' => str_replace('department-it', 'department-finance', $packet['body'])])->assertUnauthorized();
    $this->postJson('/api/software-support/test-campus', $this->campusSigner->sign('campus-request', $body, now()->timestamp - 61)->wire())->assertUnauthorized();
    $this->postJson('/api/software-support/test-campus', $this->campusSigner->sign('owner-response', $body)->wire())->assertUnauthorized();
    $this->postJson('/api/software-support/test-campus', $this->campusSigner->sign('campus-request', json_encode([...$command, 'audience' => 'another-owner']))->wire())->assertUnauthorized();
    $this->postJson('/api/software-support/test-campus', $this->campusSigner->sign('campus-request', json_encode([...$command, 'payload' => [...$this->payload, 'owner_tenant_id' => 7, 'role' => 'admin']]))->wire())->assertUnprocessable();
    expect($this->owner->db->query('SELECT count(*) FROM tickets')->fetchColumn())->toBe(0);
});

it('freezes the owner routing target with a mutation replay key', function (): void {
    $key = (string) Str::uuid();
    $this->client->dispatch('department-it', $key, 'submit', $this->payload);
    ($this->installReceiver)([new DepartmentBinding('fixture-campus', 'campus-workspace:7', 'department-it', 'campus-user:7',
        'different-owner-workspace', 'different-client', 'different-department', ['submit', 'read', 'reply'])]);
    try {
        $this->client->dispatch('department-it', $key, 'submit', $this->payload);
        test()->fail('A changed route reused an existing mutation.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        expect($error->getStatusCode())->toBe(409);
    }
    expect($this->owner->db->query('SELECT count(*) FROM tickets')->fetchColumn())->toBe(1);
});

it('rejects ambiguous receiver routing instead of choosing a client by order', function (): void {
    ($this->installReceiver)([$this->bindings[0], new DepartmentBinding('fixture-campus', 'campus-workspace:7', 'department-it', 'campus-user:7',
        'different-owner-workspace', 'different-client', 'technology', ['submit'])]);
    try {
        $this->client->dispatch('department-it', (string) Str::uuid(), 'submit', $this->payload);
        test()->fail('Ambiguous mapping selected a destination.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        expect($error->getStatusCode())->toBe(403);
    }
    expect($this->owner->db->query('SELECT count(*) FROM tickets')->fetchColumn())->toBe(0);
});

it('rejects a valid but mismatched signed response and hard-denies every production flag', function (): void {
    $receipt = $this->client->dispatch('department-it', (string) Str::uuid(), 'submit', $this->payload);
    $oldResponse = $this->transport->lastResponse;
    $replay = new class($oldResponse) implements CampusTransport
    {
        public function __construct(private array $response) {}

        public function exchange(array $packet): array
        {
            return $this->response;
        }
    };
    $client = new TestCampusClient($this->campus, $this->campus, $this->campusSigner, $this->responseVerifier, $replay, 'fixture-owner');
    expect(fn () => $client->dispatch('department-it', (string) Str::uuid(), 'read', ['reference' => $receipt['reference']]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    app()->detectEnvironment(fn () => 'production');
    $this->postJson('/api/software-support/test-campus', [])->assertNotFound();
    expect(fn () => $client->dispatch('department-it', (string) Str::uuid(), 'submit', $this->payload))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
