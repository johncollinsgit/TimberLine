<?php

use App\Models\MarketingEmailDelivery;
use App\Services\Operations\MySqlSchemaFingerprint;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ObservedSchemaDriftFixture;

uses(Tests\TestCase::class);

function reviewedDriftDelivery(?int $profileId, string $email, ?int $tenantId = null, ?int $recipientId = null): int
{
    return DB::table('marketing_email_deliveries')->insertGetId(['marketing_profile_id' => $profileId,
        'marketing_campaign_recipient_id' => $recipientId, 'tenant_id' => $tenantId, 'email' => $email,
        'status' => 'failed', 'store_key' => 'rehearsal', 'batch_id' => 'historical-batch', 'source_label' => 'Rehearsal only',
        'message_subject' => 'Historical delivery fixture', 'provider' => 'fixture', 'provider_message_id' => 'retained-provider-id',
        'campaign_type' => 'fixture', 'template_key' => 'fixture-template', 'sendgrid_message_id' => 'retained-sendgrid-id',
        'failed_at' => '2026-04-07 12:00:00', 'raw_payload' => json_encode(['fixture' => 'retained payload']),
        'metadata' => json_encode(['fixture' => 'retained metadata']), 'created_at' => '2026-04-07 11:59:00', 'updated_at' => '2026-04-07 12:00:00']);
}

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('The reviewed production drift rehearsal requires disposable MySQL.');
    }
    (new ObservedSchemaDriftFixture)->assertDisposable(DB::connection());
});

it('converges the exact observed production schema and approved baseline without losing historical rows', function (): void {
    $fixture = new ObservedSchemaDriftFixture;
    $fingerprints = new MySqlSchemaFingerprint;
    $migration = require database_path('migrations/2026_10_15_220000_reconcile_reviewed_schema_drift.php');
    $approved = $fixture->approved(DB::connection());
    expect($approved['raw_fingerprint'])->toBe('79322533c76854e55f36920596f93070063d71463aa62d44eab341a40266d03d');
    $migration->up();
    $clean = $fingerprints->inspect(DB::connection());
    $migration->up();
    expect($fingerprints->inspect(DB::connection())['raw_fingerprint'])->toBe($clean['raw_fingerprint']);

    $observed = $fixture->observed(DB::connection());
    expect($observed['raw_fingerprint'])->toBe('0aa3562df3cca1d362cd49211982ebb47bf02b97526ffe55251cfa95574d76ee')
        ->and($observed['fingerprint'])->not->toBe($clean['fingerprint']);
    $tenantId = DB::table('tenants')->insertGetId(['name' => 'Disposable drift tenant', 'slug' => 'disposable-drift-tenant']);
    $scentId = DB::table('scents')->insertGetId(['name' => 'Disposable historical scent']);
    $collectionId = DB::table('collections')->insertGetId(['name' => 'Historical retained collection']);
    $seasonId = DB::table('seasonal_scents')->insertGetId(['scent_id' => $scentId, 'season' => 'Historical season']);
    $noteId = DB::table('development_notes')->insertGetId(['title' => 'Historical global note', 'body' => 'Preserve this unresolved historical note.']);
    $area = str_repeat('a', 150);
    $changeId = DB::table('development_change_logs')->insertGetId(['title' => 'Historical change', 'summary' => 'Preserve this historical change.', 'area' => $area]);
    $settingsId = DB::table('subscription_candle_club_settings')->insertGetId(['tenant_id' => $tenantId, 'allowed_pauses_per_commitment' => 1]);
    $profileId = DB::table('marketing_profiles')->insertGetId(['tenant_id' => $tenantId, 'email' => 'valid-profile@example.test']);
    $campaignId = DB::table('marketing_campaigns')->insertGetId(['tenant_id' => $tenantId, 'name' => 'Disposable campaign']);
    $recipientId = DB::table('marketing_campaign_recipients')->insertGetId(['campaign_id' => $campaignId, 'marketing_profile_id' => $profileId, 'channel' => 'email']);
    $orphanId = reviewedDriftDelivery(900000001, 'orphan@example.test', $tenantId, $recipientId);
    $validId = reviewedDriftDelivery($profileId, 'valid@example.test', $tenantId, $recipientId);
    $nullId = reviewedDriftDelivery(null, 'null@example.test', $tenantId, $recipientId);
    $deliveryBefore = DB::table('marketing_email_deliveries')->whereIn('id', [$orphanId, $validId, $nullId])->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    $legacyBefore = [DB::table('collections')->where('id', $collectionId)->first(), DB::table('seasonal_scents')->where('id', $seasonId)->first()];
    $migration->up();
    $repaired = $fingerprints->inspect(DB::connection());
    expect($repaired['fingerprint'])->toBe($clean['fingerprint']);
    $migration->up();
    expect($fingerprints->inspect(DB::connection())['raw_fingerprint'])->toBe($repaired['raw_fingerprint'])
        ->and([DB::table('collections')->where('id', $collectionId)->first(), DB::table('seasonal_scents')->where('id', $seasonId)->first()])->toEqual($legacyBefore)
        ->and(DB::table('development_notes')->where('id', $noteId)->value('body'))->toBe('Preserve this unresolved historical note.')
        ->and(DB::table('development_notes')->where('id', $noteId)->value('tenant_id'))->toBeNull()
        ->and(DB::table('development_change_logs')->where('id', $changeId)->value('area'))->toBe($area)
        ->and(DB::table('development_change_logs')->where('id', $changeId)->value('tenant_id'))->toBeNull()
        ->and(DB::table('subscription_candle_club_settings')->where('id', $settingsId)->value('allowed_pauses_per_commitment'))->toBe(1);
    $newTenant = DB::table('tenants')->insertGetId(['name' => 'New settings tenant', 'slug' => 'new-settings-tenant']);
    $newSettings = DB::table('subscription_candle_club_settings')->insertGetId(['tenant_id' => $newTenant]);
    expect(DB::table('subscription_candle_club_settings')->where('id', $newSettings)->value('allowed_pauses_per_commitment'))->toBe(2);
    foreach ($deliveryBefore as $before) {
        $expected = $before + ['legacy_marketing_profile_id' => null];
        if ($before['id'] === $orphanId) {
            $expected['legacy_marketing_profile_id'] = $before['marketing_profile_id'];
            $expected['marketing_profile_id'] = null;
        }
        expect((array) DB::table('marketing_email_deliveries')->where('id', $before['id'])->first())->toEqual($expected);
    }
    $model = MarketingEmailDelivery::query()->forTenantId($tenantId)->findOrFail($orphanId);
    expect($model->toArray())->not->toHaveKey('legacy_marketing_profile_id')
        ->and($model->toJson())->not->toContain('legacy_marketing_profile_id');
    expect(fn () => reviewedDriftDelivery(900000002, 'new-orphan@example.test', $tenantId))->toThrow(QueryException::class);

    // A durable interruption can retain all earlier repairs while later keys
    // are missing. The real forward migration must resume without touching rows.
    Schema::table('customer_loop_activities', fn (Blueprint $table) => $table->dropUnique('customer_loop_activities_event_key_unique'));
    $foreign = collect(Schema::getForeignKeys('marketing_group_import_rows'))->first(fn (array $key): bool => $key['columns'] === ['marketing_profile_id']);
    expect($foreign)->not->toBeNull();
    Schema::table('marketing_group_import_rows', fn (Blueprint $table) => $table->dropForeign($foreign['name']));
    expect($fingerprints->inspect(DB::connection())['fingerprint'])->not->toBe($clean['fingerprint']);
    $migration->up();
    $migration->up();
    expect($fingerprints->inspect(DB::connection())['fingerprint'])->toBe($clean['fingerprint'])
        ->and(DB::table('development_change_logs')->where('id', $changeId)->value('area'))->toBe($area);

    $activity = ['tenant_id' => $tenantId, 'source_type' => 'schema_rehearsal', 'event_key' => 'reviewed-sentinel-event', 'title' => 'Retained idempotency sentinel', 'occurred_at' => now()];
    DB::table('customer_loop_activities')->insert($activity);
    expect(fn () => DB::table('customer_loop_activities')->insert($activity))->toThrow(QueryException::class);

    // The raw legacy fingerprint intentionally omits actions; the canonical
    // gate must catch a real action change even when names/references agree.
    $foreign = collect(Schema::getForeignKeys('development_notes'))->first(fn (array $key): bool => $key['columns'] === ['tenant_id']);
    $settled = $fingerprints->inspect(DB::connection());
    Schema::table('development_notes', fn (Blueprint $table) => $table->dropForeign($foreign['name']));
    Schema::table('development_notes', fn (Blueprint $table) => $table->foreign('tenant_id', $foreign['name'])->references('id')->on('tenants')->nullOnDelete());
    $changedAction = $fingerprints->inspect(DB::connection());
    expect($changedAction['raw_fingerprint'])->toBe($settled['raw_fingerprint'])
        ->and($changedAction['fingerprint'])->not->toBe($settled['fingerprint']);
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Unexpected existing reconciliation foreign key');
    Schema::table('development_notes', fn (Blueprint $table) => $table->dropForeign($foreign['name']));
    Schema::table('development_notes', fn (Blueprint $table) => $table->foreign('tenant_id', $foreign['name'])->references('id')->on('tenants')->cascadeOnDelete());
    expect($fingerprints->inspect(DB::connection())['fingerprint'])->toBe($settled['fingerprint']);
});

it('rejects conflicting retained delivery evidence before any durable repair', function (): void {
    (new ObservedSchemaDriftFixture)->observed(DB::connection());
    Schema::table('marketing_email_deliveries', fn (Blueprint $table) => $table->unsignedBigInteger('legacy_marketing_profile_id')->nullable());
    $id = reviewedDriftDelivery(900000010, 'conflict@example.test');
    DB::table('marketing_email_deliveries')->where('id', $id)->update(['legacy_marketing_profile_id' => 900000011]);
    $before = (array) DB::table('marketing_email_deliveries')->where('id', $id)->first();
    $fingerprint = (new MySqlSchemaFingerprint)->inspect(DB::connection())['raw_fingerprint'];
    $migration = require database_path('migrations/2026_10_15_220000_reconcile_reviewed_schema_drift.php');
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'conflicting retained delivery reference evidence');
    expect((new MySqlSchemaFingerprint)->inspect(DB::connection())['raw_fingerprint'])->toBe($fingerprint)
        ->and((array) DB::table('marketing_email_deliveries')->where('id', $id)->first())->toBe($before);
});

it('rolls back both delivery ID assignments and safely resumes after retained archival DDL', function (): void {
    (new ObservedSchemaDriftFixture)->observed(DB::connection());
    $first = reviewedDriftDelivery(900000020, 'rollback-first@example.test');
    $second = reviewedDriftDelivery(900000021, 'rollback-second@example.test');
    $before = DB::table('marketing_email_deliveries')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    DB::unprepared('CREATE TRIGGER reviewed_drift_fail_update BEFORE UPDATE ON marketing_email_deliveries FOR EACH ROW BEGIN IF OLD.id = '.$second." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Disposable archival failure'; END IF; END");
    $migration = require database_path('migrations/2026_10_15_220000_reconcile_reviewed_schema_drift.php');
    try {
        expect(fn () => $migration->up())->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER reviewed_drift_fail_update');
    }
    expect(Schema::hasColumn('marketing_email_deliveries', 'legacy_marketing_profile_id'))->toBeTrue();
    foreach ($before as $row) {
        expect((array) DB::table('marketing_email_deliveries')->where('id', $row['id'])->first())->toEqual($row + ['legacy_marketing_profile_id' => null]);
    }
    $migration->up();
    $migration->up();
    expect(DB::table('marketing_email_deliveries')->where('id', $first)->first())->toMatchObject(['marketing_profile_id' => null, 'legacy_marketing_profile_id' => 900000020])
        ->and(DB::table('marketing_email_deliveries')->where('id', $second)->first())->toMatchObject(['marketing_profile_id' => null, 'legacy_marketing_profile_id' => 900000021]);
});

it('blocks unreviewed duplicate keys and other orphan references without altering evidence', function (string $conflict): void {
    $fixture = new ObservedSchemaDriftFixture;
    $fixture->observed(DB::connection());
    if ($conflict === 'duplicate') {
        $tenant = DB::table('tenants')->insertGetId(['name' => 'Duplicate fixture', 'slug' => 'duplicate-fixture']);
        $row = ['tenant_id' => $tenant, 'source_type' => 'fixture', 'event_key' => 'duplicate-preserve', 'title' => 'Preserve duplicate fixture', 'occurred_at' => now()];
        DB::table('customer_loop_activities')->insert([$row, $row]);
    } else {
        DB::table('marketing_group_import_rows')->insert(['marketing_group_import_run_id' => 900000030, 'status' => 'pending']);
    }
    $fingerprint = (new MySqlSchemaFingerprint)->inspect(DB::connection())['raw_fingerprint'];
    $migration = require database_path('migrations/2026_10_15_220000_reconcile_reviewed_schema_drift.php');
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, $conflict === 'duplicate' ? 'duplicate key groups' : 'orphan references');
    expect((new MySqlSchemaFingerprint)->inspect(DB::connection())['raw_fingerprint'])->toBe($fingerprint);
    if ($conflict === 'duplicate') {
        expect(DB::table('customer_loop_activities')->count())->toBe(2);
    } else {
        expect(DB::table('marketing_group_import_rows')->sole()->marketing_group_import_run_id)->toBe(900000030);
    }
})->with(['duplicate', 'orphan']);
