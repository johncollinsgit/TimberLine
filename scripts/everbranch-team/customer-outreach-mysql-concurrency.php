<?php

// Opt-in original-ledger acceptance. Never creates, resets, truncates or drops a database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\LandlordOperatorAction;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Marketing\CustomerOutreachDraft;
use App\Services\Marketing\CustomerOutreachPolicy;
use App\Services\Marketing\MarketingConsentService;
use App\Services\Marketing\MarketingEmailExecutionService;
use App\Services\Marketing\MarketingEmailReadiness;
use App\Services\Marketing\MarketingSmsExecutionService;
use App\Services\Marketing\MarketingTemplateRenderer;
use App\Services\Marketing\MessageClickTrackingService;
use App\Services\Marketing\SendGridEmailService;
use App\Services\Marketing\TwilioSmsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n");
    exit(1);
});
$db = DB::connection();
if (! app()->environment('testing') || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1' || $db->getDriverName() !== 'mysql'
    || ! str_starts_with($db->getDatabaseName(), 'codex_teams_') || ! in_array($db->getConfig('host'), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Requires an explicitly isolated local codex_teams_ MySQL database.');
}
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
DB::statement('SET SESSION innodb_lock_wait_timeout=6');
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-07T15:00:00Z'));
\Carbon\Carbon::setTestNow(CarbonImmutable::now());
config(['marketing.customer_profile.email_dispatch_enabled' => true, 'marketing.customer_profile.sms_dispatch_enabled' => true]);
Http::preventStrayRequests();
Mail::fake();
Queue::fake();

function outreachRaceWait(string $file, int $seconds = 12): void
{
    $deadline = microtime(true) + $seconds;
    while (! is_file($file) && microtime(true) < $deadline) {
        usleep(1000);
    }
    if (! is_file($file)) {
        throw new RuntimeException('Timed out waiting for '.basename($file));
    }
}

function outreachRaceFixture(array $channels, bool $padding): array
{
    $tenant = Tenant::create(['name' => 'Fictional concurrency merchant', 'slug' => 'outreach-race-'.Str::lower(Str::random(14))]);
    $owner = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $tenant->users()->attach($owner, ['role' => 'owner', 'membership_active' => true]);
    TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'campaigns', 'availability_status' => 'available', 'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped', 'entitlement_source' => 'test']);
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional concurrency customer', 'email' => 'fictional-race@example.test',
        'normalized_email' => 'fictional-race@example.test', 'phone' => '+15552223344', 'normalized_phone' => '+15552223344',
        'accepts_email_marketing' => true, 'accepts_sms_marketing' => true]);
    $policies = app(CustomerOutreachPolicy::class);
    $rules = array_replace($policies->editor($tenant, $owner)['defaults'], ['daily_cap' => 1, 'rolling_7_day_cap' => 3,
        'email_daily_cap' => 1, 'sms_daily_cap' => 1, 'cross_channel_cooldown_minutes' => 0, 'email_cooldown_minutes' => 0, 'sms_cooldown_minutes' => 0,
        'delivery_history_verified_from' => ['email' => '2026-01-01T00:00:00Z', 'sms' => '2026-01-01T00:00:00Z'],
        'coverage_review_note' => 'Fictional fixture histories verified for local concurrency acceptance.']);
    $policies->revise($tenant, $owner, ['request_id' => (string) Str::uuid(), 'expected_version' => 0, 'publish' => true,
        'reason' => 'Fictional local concurrency policy review.', 'rules' => $rules]);
    $recipients = [];
    foreach ($channels as $channel) {
        $campaign = app(CustomerOutreachDraft::class)->save($tenant, $owner, $profile, ['request_id' => (string) Str::uuid(), 'channel' => $channel,
            'subject' => 'Fictional private note', 'body' => 'Hi {{first_name}}, this is a fictional concurrency note.', 'heading' => '', 'button_label' => '', 'button_url' => '']);
        $campaign->update(['store_key' => 'fictional-concurrency-shop']);
        $recipient = $campaign->recipients()->firstOrFail();
        $recipient->update(['status' => 'approved', 'approved_by' => $owner->id, 'approved_at' => now()]);
        $recipients[] = (int) $recipient->id;
    }
    $paddingIds = [];
    if ($padding) {
        // All rows belong to this fictional fixture. Weight the legacy writer so
        // InnoDB rolls back the smaller admission transaction in the forced inversion.
        for ($i = 0; $i < 64; $i++) {
            $paddingIds[] = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional lock-weight fixture'])->id;
        }
    }

    return ['tenant' => (int) $tenant->id, 'owner' => (int) $owner->id, 'profile' => (int) $profile->id, 'recipients' => $recipients, 'padding_ids' => $paddingIds];
}

if (($argv[1] ?? '') === '--worker') {
    $context = json_decode(file_get_contents($argv[2]), true, 32, JSON_THROW_ON_ERROR);
    $index = (int) $argv[3];
    $directory = $context['directory'];
    $spec = $context['workers'][$index];
    $result = ['role' => $spec['role'], 'channel' => $spec['channel']];
    try {
        if ($spec['role'] === 'unsubscribe') {
            DB::transaction(function () use ($context, $index, $directory, $spec) {
                $profile = MarketingProfile::forTenantId($context['tenant'])->whereKey($context['profile'])->lockForUpdate()->firstOrFail();
                DB::table('marketing_profiles')->where('tenant_id', $context['tenant'])->whereIn('id', $context['padding_ids'])->update(['notes' => 'Fictional transaction-weight marker']);
                file_put_contents($directory.'/ready-'.$index, 'profile_locked');
                outreachRaceWait($directory.'/start');
                outreachRaceWait($directory.'/admission-tenant-locked');
                // Use the actual legacy profile-first consent writer, including
                // its existing model fence and consent event/audit behavior.
                $method = $spec['channel'] === 'email' ? 'setEmailConsent' : 'setSmsConsent';
                app(MarketingConsentService::class)->$method($profile, false, ['tenant_id' => $context['tenant'],
                    'source_type' => 'fictional_concurrency_unsubscribe', 'source_id' => (string) $context['profile']]);
            }, 3);
            file_put_contents($directory.'/revocation-committed', 'committed');
            $result['outcome'] = 'revoked';
        } else {
            DB::listen(function ($query) use ($directory, $context) {
                if (DB::transactionLevel() > 0) {
                    file_put_contents($directory.'/transaction-queries-'.getmypid().'.jsonl', json_encode(['at' => microtime(true), 'sql' => $query->sql], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
                }
                if (DB::transactionLevel() > 0 && preg_match('/select.*from [`"]tenants[`"].*for update/is', $query->sql)
                    && in_array($context['tenant'], $query->bindings, true)) {
                    file_put_contents($directory.'/admission-cycles', "locked\n", FILE_APPEND | LOCK_EX);
                    file_put_contents($directory.'/admission-tenant-locked', 'locked');
                }
            });
            $rendered = false;
            $renderer = Mockery::mock(MarketingTemplateRenderer::class);
            $renderer->shouldReceive('renderCampaignMessage')->andReturnUsing(function ($campaign, $body) use ($directory, $index, &$rendered) {
                if (! $rendered) {
                    $rendered = true;
                    file_put_contents($directory.'/ready-'.$index, 'before_admission');
                    outreachRaceWait($directory.'/start');
                }

                return $body;
            });
            app()->instance(MarketingTemplateRenderer::class, $renderer);
            $readiness = Mockery::mock(MarketingEmailReadiness::class);
            $readiness->shouldReceive('providerContextForDelivery')->andReturn(['tenant_id' => $context['tenant'], 'provider' => 'sendgrid',
                'readiness_status' => 'ready', 'config_status' => 'configured', 'resolution_source' => 'local_fake', 'using_fallback_config' => false]);
            app()->instance(MarketingEmailReadiness::class, $readiness);
            $accept = function (string $channel, array $options) use ($context, $directory): array {
                $consented = (bool) DB::table('marketing_profiles')->where('tenant_id', $context['tenant'])->where('id', $context['profile'])->value('accepts_'.$channel.'_marketing');
                $record = ['channel' => $channel, 'delivery_id' => $options['delivery_id'], 'transaction_level' => DB::transactionLevel(),
                    'consented_at_handoff' => $consented, 'revocation_already_committed' => is_file($directory.'/revocation-committed')];
                file_put_contents($directory.'/accepted.jsonl', json_encode($record, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
                usleep(100000); // Keep the durable pending claim visible to its competitor.

                return $record;
            };
            $email = Mockery::mock(SendGridEmailService::class);
            $email->shouldReceive('sendEmail')->andReturnUsing(function ($to, $subject, $body, $options) use ($accept) {
                $record = $accept('email', $options);

                return ['success' => true, 'provider' => 'sendgrid', 'status' => 'sent', 'message_id' => 'local-email-'.$record['delivery_id'], 'payload' => [], 'dry_run' => false];
            });
            app()->instance(SendGridEmailService::class, $email);
            $sms = Mockery::mock(TwilioSmsService::class);
            $sms->shouldReceive('sendSms')->andReturnUsing(function ($to, $body, $options) use ($accept) {
                $record = $accept('sms', $options);

                return ['success' => true, 'provider_message_id' => 'local-sms-'.$record['delivery_id'], 'status' => 'sent', 'payload' => [], 'dry_run' => false];
            });
            app()->instance(TwilioSmsService::class, $sms);
            $tracking = Mockery::mock(MessageClickTrackingService::class);
            $tracking->shouldReceive('decorateSmsMessageForDelivery')->andReturnUsing(fn ($delivery, $message) => ['message' => $message, 'links' => []]);
            app()->instance(MessageClickTrackingService::class, $tracking);
            $recipient = MarketingCampaignRecipient::whereKey($spec['recipient'])->firstOrFail();
            $service = $spec['channel'] === 'email' ? MarketingEmailExecutionService::class : MarketingSmsExecutionService::class;
            $result += app($service)->sendRecipient($recipient, ['tenant_id' => $context['tenant'], 'actor_id' => $context['owner'], 'override_windows' => true]);
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    } catch (Throwable $error) {
        $result['outcome'] = 'unexpected_error';
        $result['error'] = get_class($error).': '.$error->getMessage();
    }
    echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
    exit;
}

$checks = [];
$scenarios = ['cross_channel_cap', 'same_email_recipient', 'email_unsubscribe_deadlock', 'sms_unsubscribe_deadlock'];
if (isset($argv[1])) {
    $requested = str_starts_with($argv[1], '--scenario=') ? substr($argv[1], strlen('--scenario=')) : '';
    if (! in_array($requested, $scenarios, true)) {
        throw new RuntimeException('Choose a known --scenario= acceptance case.');
    }
    $scenarios = [$requested];
}
foreach ($scenarios as $scenario) {
    $revoking = str_contains($scenario, 'unsubscribe');
    $channel = str_starts_with($scenario, 'sms') ? 'sms' : 'email';
    $fixture = outreachRaceFixture($scenario === 'cross_channel_cap' ? ['email', 'sms'] : [$channel], $revoking);
    $directory = sys_get_temp_dir().'/customer-outreach-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $workers = $revoking ? [['role' => 'unsubscribe', 'channel' => $channel], ['role' => 'send', 'channel' => $channel, 'recipient' => $fixture['recipients'][0]]]
        : [['role' => 'send', 'channel' => 'email', 'recipient' => $fixture['recipients'][0]], ['role' => 'send', 'channel' => $scenario === 'cross_channel_cap' ? 'sms' : 'email', 'recipient' => $fixture['recipients'][1] ?? $fixture['recipients'][0]]];
    $context = [...$fixture, 'directory' => $directory, 'workers' => $workers];
    $contextFile = $directory.'/context.json';
    file_put_contents($contextFile, json_encode($context, JSON_THROW_ON_ERROR));
    $processes = [];
    try {
        foreach ([0, 1] as $index) {
            $process = proc_open([PHP_BINARY, __FILE__, '--worker', $contextFile, (string) $index], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start a local acceptance worker.');
            }
            $processes[] = [$process, $pipes];
        }
        outreachRaceWait($directory.'/ready-0');
        outreachRaceWait($directory.'/ready-1');
        file_put_contents($directory.'/start', 'start');
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if ($exit !== 0) {
                throw new RuntimeException('Worker failed: '.$errors);
            }
            $results[] = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        }
        $processes = [];
        $acceptances = is_file($directory.'/accepted.jsonl') ? array_map(fn ($line) => json_decode($line, true, 16, JSON_THROW_ON_ERROR), file($directory.'/accepted.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
        $cycles = is_file($directory.'/admission-cycles') ? count(file($directory.'/admission-cycles')) : 0;
        $deliveries = MarketingEmailDelivery::forTenantId($fixture['tenant'])->count() + MarketingMessageDelivery::forTenantId($fixture['tenant'])->count();
        $outcomes = array_column($results, 'outcome');
        sort($outcomes);
        $expected = $revoking ? ['revoked', 'skipped'] : ['sent', 'skipped'];
        if ($outcomes !== $expected || count($acceptances) !== ($revoking ? 0 : 1) || $deliveries !== ($revoking ? 0 : 1)
            || collect($acceptances)->contains(fn ($record) => $record['transaction_level'] !== 0 || ! $record['consented_at_handoff'] || $record['revocation_already_committed'])
            || ($revoking && ($cycles < 2 || ! is_file($directory.'/revocation-committed')))) {
            $queries = [];
            foreach (glob($directory.'/transaction-queries-*.jsonl') ?: [] as $queryFile) {
                $queries[basename($queryFile)] = array_slice(array_map(fn ($line) => json_decode($line, true, 16, JSON_THROW_ON_ERROR), file($queryFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)), 0, 12);
            }
            throw new RuntimeException('Race invariant failed: '.json_encode(['scenario' => $scenario, 'results' => $results, 'acceptances' => $acceptances, 'admission_cycles' => $cycles, 'delivery_rows' => $deliveries, 'transaction_queries' => $queries], JSON_THROW_ON_ERROR));
        }
        $decision = app(CustomerOutreachPolicy::class)->decision(MarketingProfile::forTenantId($fixture['tenant'])->findOrFail($fixture['profile']), $channel);
        if (! $revoking && ! in_array('outreach_daily_cap', $decision['reason_codes'], true)) {
            throw new RuntimeException('Accepted delivery did not consume the reviewed daily cap.');
        }
        $checks[$scenario] = ['outcomes' => $outcomes, 'provider_handoffs' => count($acceptances), 'original_delivery_rows' => $deliveries,
            'admission_cycles' => $cycles, 'dispatch_decision_audits' => LandlordOperatorAction::forTenantId($fixture['tenant'])->where('action_type', 'customer_outreach_dispatch_decision')->count(),
            'daily_cap_rechecked' => ! $revoking, 'revocation_committed' => $revoking];
    } finally {
        foreach ($processes as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
        }
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}
echo json_encode(['driver' => 'mysql', 'isolation' => 'REPEATABLE READ', 'processes_per_race' => 2, 'provider_interfaces' => 'local_recording_fakes_only', 'checks' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
