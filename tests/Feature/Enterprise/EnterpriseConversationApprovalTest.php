<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Trial\TrialBudget;
use App\Enterprise\Trial\TrialStore;
use App\Models\TeamOperationRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EnterpriseConversationFixture;

final class EnterpriseConversationApprovalTest extends EnterpriseConversationFixture
{
    public static function invalidAbsoluteExpiries(): array
    {
        return [['+1 hour'], ['tomorrow'], ['2027-02-31T04:00:00Z'], ['2027-13-01T04:00:00+00:00'],
            ['2027-01-01T24:00:00Z'], ['2027-01-01T04:00:60Z'], ['2027-01-01'], ['2027-01-01T04:00:00Z trailing'], ['2027-01-01T04:00:00+99:99'],
            ['2027-01-01T04:00:00+12:99'], ['2027-01-01T04:00:00-00:00']];
    }

    #[DataProvider('invalidAbsoluteExpiries')]
    public function test_relative_ambiguous_and_normalized_invalid_approval_dates_deny_without_http(string $expiry): void
    {
        $this->approve(['valid_until' => $expiry]);
        $digest = config('enterprise.conversation.approval_sha256');
        $this->travel(48)->hours();
        $this->turn()->assertStatus(503);
        $this->assertSame($digest, config('enterprise.conversation.approval_sha256'));
        Http::assertNothingSent();
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
    }

    public function test_absolute_expiry_stays_fixed_and_valid_rfc3339_z_and_fractional_offsets_work(): void
    {
        $this->fake();
        $this->approve(['valid_until' => now()->addHour()->format('Y-m-d\TH:i:s\Z')]);
        $this->turn()->assertOk();
        $digest = config('enterprise.conversation.approval_sha256');
        $this->travel(48)->hours();
        $this->turn()->assertStatus(503);
        $this->assertSame($digest, config('enterprise.conversation.approval_sha256'));
        Http::assertSentCount(1);
        $this->approve(['valid_until' => now()->addHour()->format('Y-m-d\TH:i:s.uP')]);
        $this->turn()->assertOk();
        Http::assertSentCount(2);
        $this->approve(['valid_until' => now()->addHour()->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i:sP')]);
        $this->turn()->assertOk();
        Http::assertSentCount(3);
    }

    public function test_missing_replaced_or_changed_approved_ledger_never_initializes_a_campaign(): void
    {
        $ledger = TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->firstOrFail();
        $approvedId = $ledger->id;
        $ledger->delete();
        $this->turn()->assertStatus(503);
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->count());
        $replacement = $ledger->replicate();
        $replacement->id = (string) Str::uuid();
        $replacement->save();
        $this->turn()->assertStatus(503);
        $this->assertSame([$replacement->id], TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->pluck('id')->all());
        $replacement->delete();
        $ledger->id = $approvedId;
        $ledger->exists = false;
        $ledger->save();
        $data = $ledger->data;
        $data['baseline']['prior_microusd'] = 1;
        $ledger->data = $data;
        $ledger->save();
        $this->turn()->assertStatus(503);
        Http::assertNothingSent();
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
    }

    public static function ledgerRacePoints(): array
    {
        return [['reserve', false], ['reserve', true], ['claimDispatch', false], ['claimDispatch', true], ['afterClaim', false], ['settle', false]];
    }

    #[DataProvider('ledgerRacePoints')]
    public function test_exact_existing_ledger_is_rechecked_inside_budget_locks_and_uncertainty_cleanup(string $point, bool $replace): void
    {
        $ledger = TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->firstOrFail();
        $replacementId = (string) Str::uuid();
        $change = function () use ($ledger, $replace, $replacementId): void {
            TeamOperationRecord::query()->whereKey($ledger->id)->delete();
            if ($replace) {
                $replacement = $ledger->replicate();
                $replacement->id = $replacementId;
                $replacement->save();
            }
        };
        $this->fake(function ($request, $response) use ($point, $change) {
            if ($point === 'settle') {
                $change();
            }

            return Http::response($response);
        });
        if ($point !== 'settle') {
            $real = app(TrialBudget::class);
            $proxy = \Mockery::mock(TrialBudget::class, [app(TrialStore::class)])->makePartial();
            $method = $point === 'afterClaim' ? 'claimDispatch' : $point;
            $proxy->shouldReceive($method)->once()->andReturnUsing(function (...$arguments) use ($point, $method, $real, $change) {
                if ($point === 'afterClaim') {
                    $real->{$method}(...$arguments);
                    $change();

                    return;
                }
                $change();

                return $real->{$method}(...$arguments);
            });
            $this->app->instance(TrialBudget::class, $proxy);
        }
        $this->turn()->assertStatus(503)->assertJsonMissingPath('reply');
        Http::assertSentCount($point === 'settle' ? 1 : 0);
        $this->assertSame($replace ? [$replacementId] : [], TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->pluck('id')->all(),
            'No conversation lifecycle stage may silently create or replace the approved ledger.');
        $charges = TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->get();
        $this->assertCount($point === 'reserve' ? 0 : 1, $charges);
        foreach ($charges as $charge) {
            $this->assertContains($charge->status, ['reserved', 'dispatching', 'uncertain']);
            $this->assertSame(20300, $charge->data['charged_microusd'] ?? $charge->data['reserved_microusd']);
        }
    }
}
