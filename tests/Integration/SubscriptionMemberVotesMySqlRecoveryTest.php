<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Disposable MySQL recovery only.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Refusing member voting recovery outside disposable local MySQL.');
    }
    foreach (['subscription_member_votes', 'subscription_billing_actions', 'subscription_billing_cycles', 'subscription_billing_agreements',
        'subscription_pilot_cycles', 'subscription_pilot_enrollments', 'subscription_pilot_events', 'subscription_pilot_members'] as $table) {
        Schema::dropIfExists($table);
    }
    (require database_path('migrations/2026_10_27_210000_create_subscription_pilot_evidence.php'))->up();
    (require database_path('migrations/2026_10_30_220000_create_subscription_billing_ledger.php'))->up();
    $this->voteParentStubs = [];
    foreach (['subscription_polls', 'subscription_poll_options'] as $table) {
        if (! Schema::hasTable($table)) {
            Schema::create($table, fn (Blueprint $t) => $t->id());
            $this->voteParentStubs[] = $table;
        }
    }
    $this->voteRecoveryOwned = true;
});

afterEach(function () {
    if ($this->voteRecoveryOwned ?? false) {
        Schema::dropIfExists('subscription_member_votes');
        if ($this->voteRecoveryPoll ?? null) {
            Schema::withoutForeignKeyConstraints(function () {
                DB::table('subscription_poll_options')->where('id', $this->voteRecoveryOption)->delete();
                DB::table('subscription_polls')->where('id', $this->voteRecoveryPoll)->delete();
            });
        }
        foreach (array_reverse($this->voteParentStubs) as $table) {
            Schema::drop($table);
        }
        foreach (['subscription_billing_actions', 'subscription_billing_cycles', 'subscription_billing_agreements', 'subscription_pilot_events', 'subscription_pilot_members'] as $table) {
            Schema::dropIfExists($table);
        }
    }
});

function memberVoteRecoveryRow($case): array
{
    $cipher = Crypt::encryptString('{"accepted_vote":"fictional retained source membership"}');
    $member = DB::table('subscription_pilot_members')->insertGetId(['tenant_id' => 101, 'public_id' => '00000000-0000-4000-8000-000000000701',
        'shopify_store_id' => 11, 'recharge_customer_id' => '123', 'shopify_customer_id' => '456', 'target_date' => '2027-01-31',
        'timezone' => 'America/New_York', 'currency' => 'USD', 'details' => $cipher, 'prepared_by' => 1]);
    $agreement = DB::table('subscription_billing_agreements')->insertGetId(['tenant_id' => 101, 'member_id' => $member,
        'public_id' => '00000000-0000-4000-8000-000000000702', 'processor' => 'braintree', 'terms' => $cipher, 'terms_hash' => str_repeat('a', 64),
        'method_binding' => $cipher, 'authorization_evidence' => $cipher, 'prepared_by' => 1, 'billing_anchor_date' => '2027-01-31']);
    // Parent fixtures satisfy their IDs without depending on unrelated module seed state.
    Schema::withoutForeignKeyConstraints(function () use ($case) {
        $row = ['tenant_id' => 101, 'poll_type' => 'candle_club_scent', 'title' => 'Fictional retained poll', 'status' => 'closed', 'share_token' => (string) \Illuminate\Support\Str::uuid()];
        $case->voteRecoveryPoll = DB::table('subscription_polls')->insertGetId(array_intersect_key($row, array_flip(Schema::getColumnListing('subscription_polls'))));
        $row = ['tenant_id' => 101, 'subscription_poll_id' => $case->voteRecoveryPoll, 'label' => 'Fictional scent'];
        $case->voteRecoveryOption = DB::table('subscription_poll_options')->insertGetId(array_intersect_key($row, array_flip(Schema::getColumnListing('subscription_poll_options'))));
    });

    return ['tenant_id' => 101, 'member_id' => $member, 'agreement_id' => $agreement, 'subscription_poll_id' => $case->voteRecoveryPoll,
        'subscription_poll_option_id' => $case->voteRecoveryOption, 'public_id' => '00000000-0000-4000-8000-000000000703',
        'request_uuid' => '00000000-0000-4000-8000-000000000704', 'agreement_revision' => 2, 'evidence' => $cipher];
}

test('member voting recovery retains accepted ballots through partial constraints replay and code rollback', function (string $partial) {
    $migration = require database_path('migrations/2026_10_30_230000_create_subscription_member_votes.php');
    $migration->up();
    $row = memberVoteRecoveryRow($this);
    DB::table('subscription_member_votes')->insert($row);
    if ($partial !== 'completed-before-bookkeeping') {
        Schema::table('subscription_member_votes', function (Blueprint $t) use ($partial) {
            $t->dropForeign('smv_agreement_fk');
            $t->dropUnique('smv_request_uq');
            if ($partial === 'bare-table') {
                $t->dropForeign('smv_member_fk');
                $t->dropForeign('smv_poll_fk');
                $t->dropForeign('smv_option_fk');
                $t->dropUnique('smv_poll_member_uq');
                $t->dropUnique('smv_public_uq');
            }
        });
    }
    $migration->up();
    $migration->up();
    $migration->down();
    expect(DB::table('subscription_member_votes')->value('evidence'))->toBe($row['evidence'])
        ->and(Schema::hasIndex('subscription_member_votes', 'smv_poll_member_uq'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('subscription_member_votes'))->firstWhere('name', 'smv_agreement_fk')['on_delete'])->toBe('restrict');
    $foreign = array_replace($row, ['tenant_id' => 202, 'public_id' => (string) \Illuminate\Support\Str::uuid(), 'request_uuid' => (string) \Illuminate\Support\Str::uuid()]);
    expect(fn () => DB::table('subscription_member_votes')->insert($foreign))->toThrow(\Illuminate\Database\QueryException::class);
})->with(['bare-table', 'missing-constraints', 'completed-before-bookkeeping']);

test('member voting recovery refuses an incompatible named index without deleting accepted evidence', function () {
    $migration = require database_path('migrations/2026_10_30_230000_create_subscription_member_votes.php');
    $migration->up();
    $row = memberVoteRecoveryRow($this);
    DB::table('subscription_member_votes')->insert($row);
    Schema::table('subscription_member_votes', function (Blueprint $t) {
        $t->dropUnique('smv_request_uq');
        $t->index(['agreement_revision'], 'smv_request_uq');
    });
    expect(fn () => $migration->up())->toThrow(RuntimeException::class);
    expect(DB::table('subscription_member_votes')->value('evidence'))->toBe($row['evidence']);
});
