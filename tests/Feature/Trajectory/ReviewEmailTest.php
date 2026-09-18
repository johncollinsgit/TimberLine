<?php

use App\Jobs\Trajectory\SendReviewEmail;
use App\Mail\TrajectoryReviewMail;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\ReviewEmailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(now()->setDate(2026, 9, 21)->setTime(13, 15)); // Monday 9:15 AM New York
    config(['trajectory.enabled' => true, 'mail.default' => 'smtp']);
    Mail::fake();
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->account = Account::create(['space_id' => $this->space->id, 'source_key' => 'test:cash', 'name' => 'Checking', 'kind' => 'cash']);
    $this->emails = app(ReviewEmailService::class);
    for ($i = 1; $i <= 5; $i++) {
        app(LedgerService::class)->ingest($this->account, [['id' => 'row-'.$i, 'date' => '2026-09-'.(10 + $i), 'merchant' => 'Merchant '.$i, 'amount_cents' => -12345]]);
    }
});

test('review email requires opt in and sends the count and four newest allocated amounts exactly once', function (): void {
    expect($this->emails->send($this->owner->id, $this->space->id))->toBe('skipped');
    $this->emails->save($this->owner, $this->space, 'daily', 'America/New_York');
    $newest = Transaction::latest('id')->first();
    Allocation::where('transaction_id', $newest->id)->update(['amount_cents' => -4321]);
    expect($this->emails->send($this->owner->id, $this->space->id))->toBe('accepted');
    expect($this->emails->send($this->owner->id, $this->space->id, true))->toBe('skipped');
    Mail::assertSent(TrajectoryReviewMail::class, function ($mail): bool {
        expect($mail->reviewCount)->toBe(5)->and(array_column($mail->transactions, 'merchant'))->toBe(['Merchant 5', 'Merchant 4', 'Merchant 3', 'Merchant 2']);
        expect($mail->transactions[0]['amount_cents'])->toBe(-4321);
        $html = $mail->render();
        expect($html)->toContain('− $43.21', 'tab=transactions', 'tab=accounts')->not->toContain('Merchant 1');

        return $mail->hasTo($this->owner->email);
    });
    Mail::assertSentCount(1);
    expect(Event::where('action', 'review_email_accepted')->count())->toBe(1);
});

test('reviewed pending removed and future transactions do not create reminders', function (): void {
    $this->emails->save($this->owner, $this->space, 'daily', 'America/New_York');
    $ids = Transaction::orderBy('id')->pluck('id');
    Transaction::find($ids[0])->update(['pending' => true]);
    Transaction::find($ids[1])->update(['removed' => true]);
    Transaction::find($ids[2])->update(['posted_on' => '2026-09-22']);
    Transaction::whereKey([$ids[3], $ids[4]])->update(['reviewed' => true]);
    expect($this->emails->send($this->owner->id, $this->space->id))->toBe('skipped');
    Mail::assertNothingSent();
});

test('shared allocations never reveal a foreign merchant account or full amount', function (): void {
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$space] = app(PilotService::class)->prepare($other, null, true);
    $account = Account::create(['space_id' => $space->id, 'source_key' => 'other:cash', 'name' => 'Secret account', 'kind' => 'cash']);
    app(LedgerService::class)->ingest($account, [['id' => 'foreign', 'date' => '2026-09-21', 'merchant' => 'Secret business merchant', 'amount_cents' => -990099]]);
    $tx = Transaction::where('account_id', $account->id)->first();
    Allocation::create(['space_id' => $this->space->id, 'transaction_id' => $tx->id, 'amount_cents' => -1234]);
    $this->emails->save($this->owner, $this->space, 'daily', 'America/New_York');
    $this->emails->send($this->owner->id, $this->space->id);
    Mail::assertSent(TrajectoryReviewMail::class, function ($mail): bool {

        expect($mail->render())->toContain('Shared allocation', '− $12.34')->not->toContain('Secret business merchant', 'Secret account', '9,900.99');

        return true;
    });
});

test('preferences are private per recipient and authorization is rechecked by the queued job', function (): void {
    $stranger = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $url = '/trajectory/spaces/'.$this->space->id.'/review-email';
    $this->actingAs($stranger)->patchJson($url, ['frequency' => 'daily', 'timezone' => 'America/New_York'])->assertForbidden();
    DB::table('trajectory_members')->insert(['space_id' => $this->space->id, 'user_id' => $stranger->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($stranger)->patchJson($url, ['frequency' => 'daily', 'timezone' => 'America/New_York', 'user_id' => $this->owner->id])->assertOk()->assertJsonPath('email', $stranger->email);
    expect($this->emails->preferences($this->owner, $this->space)['frequency'])->toBe('off');
    DB::table('trajectory_members')->where('user_id', $stranger->id)->delete();
    (new SendReviewEmail($stranger->id, $this->space->id))->handle($this->emails);
    Mail::assertNothingSent();
});

test('disabled spaces unverified changed emails and opt outs prevent delivery', function (): void {
    $this->emails->save($this->owner, $this->space, 'daily', 'America/New_York');
    $this->space->update(['enabled' => false]);
    expect($this->emails->send($this->owner->id, $this->space->id))->toBe('skipped');
    $this->space->update(['enabled' => true]);
    $this->owner->forceFill(['email_verified_at' => null])->save();
    expect($this->emails->send($this->owner->id, $this->space->id))->toBe('skipped');
    $this->owner->forceFill(['email_verified_at' => now(), 'email' => 'changed@example.test'])->save();
    expect($this->emails->send($this->owner->id, $this->space->id))->toBe('skipped');
    $this->emails->save($this->owner, $this->space, 'off', 'America/New_York');
    expect($this->emails->send($this->owner->id, $this->space->id))->toBe('skipped');
    Mail::assertNothingSent();
});

test('weekly scheduling follows local monday morning including daylight savings', function (): void {
    Queue::fake();
    $this->emails->save($this->owner, $this->space, 'weekly', 'America/New_York');
    $this->artisan('trajectory:review-emails')->assertSuccessful();
    Queue::assertPushed(SendReviewEmail::class, 1);
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(13, 15));
    expect($this->emails->due($this->emails->preferences($this->owner, $this->space)))->toBeFalse();
    $this->travelTo(now()->setTime(14, 15));
    expect($this->emails->due($this->emails->preferences($this->owner, $this->space)))->toBeTrue();
    expect($this->emails->send($this->owner->id, $this->space->id))->toBe('accepted');
    $this->travel(1)->days();
    expect($this->emails->send($this->owner->id, $this->space->id, true))->toBe('skipped');
    $this->travel(6)->days();
    expect($this->emails->send($this->owner->id, $this->space->id))->toBe('accepted');
    Mail::assertSentCount(2);
});

test('log delivery is blocked and ambiguous provider failures cannot be retried into duplicates', function (): void {
    $this->emails->save($this->owner, $this->space, 'daily', 'America/New_York');
    config(['mail.default' => 'log']);
    expect($this->emails->send($this->owner->id, $this->space->id))->toBe('provider_unavailable');
    config(['mail.default' => 'smtp']);
    Mail::shouldReceive('mailer')->once()->andThrow(new RuntimeException('provider message may contain private data'));
    expect($this->emails->send($this->owner->id, $this->space->id))->toBe('uncertain');
    expect($this->emails->send($this->owner->id, $this->space->id, true))->toBe('skipped');
    expect(Event::where('action', 'review_email_uncertain')->first()->after)->toBe(['count' => 5]);
});

test('email escapes imported content and preserves cent precision with text alternative', function (): void {
    $mail = new TrajectoryReviewMail('John', 'Household', 42, 1, [['merchant' => '<script>test</script>', 'category' => 'groceries', 'flow' => 'expense', 'date' => '2026-09-20', 'amount_cents' => -100001]]);
    expect($mail->render())->toContain('&lt;script&gt;test&lt;/script&gt;', '− $1,000.01')->not->toContain('<script>');
    $mail->assertSeeInText('− $1,000.01');
    $mail->assertSeeInText('space=42&tab=transactions');
});

test('invalid preferences fail validation and daily emails resume next day only while work remains', function (): void {
    $url = '/trajectory/spaces/'.$this->space->id.'/review-email';
    $this->actingAs($this->owner)->patchJson($url, ['frequency' => 'hourly', 'timezone' => 'invalid'])->assertUnprocessable();
    $this->actingAs($this->owner)->patchJson($url, ['frequency' => 'daily', 'timezone' => 'America/New_York'])->assertOk();
    expect($this->emails->send($this->owner->id, $this->space->id))->toBe('accepted');
    $this->travel(1)->days();
    expect($this->emails->send($this->owner->id, $this->space->id))->toBe('accepted');
    Transaction::query()->update(['reviewed' => true]);
    $this->travel(1)->days();
    expect($this->emails->send($this->owner->id, $this->space->id))->toBe('skipped');
    Mail::assertSentCount(2);
});
