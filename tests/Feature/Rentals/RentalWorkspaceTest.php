<?php

use App\Models\TenantModuleEntitlement;
use App\Services\Rentals\RentalFiles;
use App\Services\Rentals\RentalRepository;
use App\Services\Team\TeamFileScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/RentalFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
});

test('rentals require rollout entitlement verified active membership and property membership', function (): void {
    [$tenant, $admin, $state] = rentalFixture();
    $url = route('rentals.data', ['property' => $state['property']['id'], 'tenant' => $tenant->slug]);
    $response = $this->actingAs($admin)->getJson($url)->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    config(['rentals.enabled' => false]);
    $this->getJson($url)->assertNotFound();
    config(['rentals.enabled' => true, 'rentals.tenant_allowlist' => []]);
    $this->getJson($url)->assertNotFound();
    config(['rentals.tenant_allowlist' => [(string) $tenant->id]]);
    $member = rentalMember($tenant);
    $this->actingAs($member)->getJson($url)->assertNotFound();
    $state = rentalHousehold($tenant, $admin, $state, $member, 1);
    $this->getJson($url)->assertOk();
    $tenant->users()->updateExistingPivot($member->id, ['membership_active' => false]);
    $this->getJson($url)->assertForbidden();
    $this->actingAs($admin);
    TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->where('module_key', 'property_rentals')->update(['enabled_status' => 'disabled']);
    $this->getJson($url)->assertForbidden();
});

test('vanilla properties persist empty and never copy demo records', function (): void {
    [$tenant, $admin, $state] = rentalFixture();
    expect($state['weeks'])->toBe([])->and($state['households'])->toBe([])->and($state['dues'])->toBe([])
        ->and($state['capabilities']['payments'])->toBe('offline-records')->and($state['capabilities']['rentals'])->toBeFalse();
    expect(DB::table('rental_properties')->value('payload'))->not->toContain('Fictional villa');
    $this->actingAs($admin)->get(route('rentals.index', ['tenant' => $tenant->slug]))->assertOk()->assertSee('rental-workspace');
    [$otherTenant, $otherAdmin] = rentalFixture();
    $this->actingAs($otherAdmin)->getJson(route('rentals.data', ['property' => $state['property']['id'], 'tenant' => $otherTenant->slug]))->assertNotFound();
    expect(DB::table('rental_events')->count())->toBe(2);
});

test('released weeks have one winner and original receipt retries cannot duplicate a claim', function (): void {
    [$tenant, $admin, $state] = rentalFixture();
    $a = rentalMember($tenant);
    $b = rentalMember($tenant);
    $c = rentalMember($tenant);
    foreach ([$a, $b, $c] as $i => $member) {
        $state = rentalHousehold($tenant, $admin, $state, $member, $i + 1);
    }
    $state = rentalChange($tenant, $admin, $state, 'createStay', ['start' => '2035-05-06', 'end' => '2035-05-13', 'held' => false, 'share' => 1, 'householdId' => $state['households'][0]['id']]);
    $week = $state['weeks'][0];
    $state = rentalChange($tenant, $a, $state, 'releaseWeek', ['weekId' => $week['id']]);
    $input = ['action' => 'claimWeek', 'weekId' => $week['id'], 'version' => $state['version'], 'requestKey' => (string) Str::uuid()];
    $repo = app(RentalRepository::class);
    $won = $repo->execute($tenant, $b, $state['property']['id'], $input);
    expect($won['weeks'][0]['owner'])->toBe($week['owner'])->and($won['weeks'][0]['originalOwner'])->toBe($week['originalOwner']);
    expect($repo->execute($tenant, $b, $state['property']['id'], $input)['replayed'])->toBeTrue();
    $this->actingAs($c)->postJson(route('rentals.command', ['property' => $state['property']['id'], 'tenant' => $tenant->slug]), array_replace($input, ['requestKey' => (string) Str::uuid()]))->assertConflict();
    expect($repo->read($tenant, $admin, $state['property']['id'])['weeks'][0]['currentOwner'])->toBe($state['households'][1]['id']);
    $this->actingAs($b)->postJson(route('rentals.command', ['property' => $state['property']['id'], 'tenant' => $tenant->slug]), array_replace($input, ['action' => 'releaseWeek']))->assertConflict();
});

test('swaps serialize and retain permanent allocations and reject stale counterpart changes', function (): void {
    [$tenant, $admin, $state] = rentalFixture();
    $a = rentalMember($tenant);
    $b = rentalMember($tenant);
    $state = rentalHousehold($tenant, $admin, $state, $a, 1);
    $state = rentalHousehold($tenant, $admin, $state, $b, 2);
    foreach ([['2035-06-03', '2035-06-10'], ['2035-06-10', '2035-06-17']] as $i => [$start, $end]) {
        $state = rentalChange($tenant, $admin, $state, 'createStay', ['start' => $start, 'end' => $end, 'held' => false, 'share' => $i + 1, 'householdId' => $state['households'][$i]['id']]);
    }
    $state = rentalChange($tenant, $a, $state, 'requestSwap', ['fromWeekId' => $state['weeks'][0]['id'], 'toWeekId' => $state['weeks'][1]['id']]);
    $state = rentalChange($tenant, $b, $state, 'acceptSwap', ['swapId' => $state['swaps'][0]['id']]);
    expect($state['weeks'][0]['owner'])->toBe($state['households'][0]['id'])->and($state['weeks'][0]['currentOwner'])->toBe($state['households'][1]['id'])
        ->and($state['weeks'][1]['currentOwner'])->toBe($state['households'][0]['id'])->and($state['swaps'][0]['status'])->toBe('accepted');
});

test('offline receipts use cents replay once retain void evidence and never fabricate a charge', function (): void {
    [$tenant, $admin, $state] = rentalFixture();
    $member = rentalMember($tenant);
    $state = rentalHousehold($tenant, $admin, $state, $member, 1);
    $state = rentalChange($tenant, $admin, $state, 'chargeDues', ['householdId' => $state['households'][0]['id'], 'amountCents' => 12345, 'dueDate' => '2035-01-01', 'description' => 'Reviewed annual dues']);
    $input = ['action' => 'recordPayment', 'duesId' => $state['dues'][0]['id'], 'amountCents' => 2345, 'method' => 'check', 'date' => now()->toDateString(), 'reference' => 'Fictional check 123', 'version' => $state['version'], 'requestKey' => (string) Str::uuid()];
    $repo = app(RentalRepository::class);
    $state = $repo->execute($tenant, $admin, $state['property']['id'], $input);
    $state = $repo->execute($tenant, $admin, $state['property']['id'], $input);
    expect($state['dues'][0]['paidCents'])->toBe(2345)->and($state['dues'][0]['payments'])->toHaveCount(1)->and($state['dues'][0]['payments'][0]['simulated'])->toBeFalse();
    $this->actingAs($admin)->postJson(route('rentals.command', ['property' => $state['property']['id'], 'tenant' => $tenant->slug]),
        array_replace($input, ['version' => $state['version'], 'requestKey' => (string) Str::uuid()]))->assertConflict();
    $state = rentalChange($tenant, $admin, $state, 'voidPayment', ['duesId' => $state['dues'][0]['id'], 'paymentId' => $state['dues'][0]['payments'][0]['id'], 'reason' => 'Correcting duplicate paper evidence']);
    expect($state['dues'][0]['paidCents'])->toBe(0)->and($state['dues'][0]['payments'][0]['amountCents'])->toBe(2345)->and($state['dues'][0]['payments'][0]['voidReason'])->toContain('Correcting');
    $this->actingAs($member)->postJson(route('rentals.command', ['property' => $state['property']['id'], 'tenant' => $tenant->slug]), array_replace($input, ['version' => $state['version'], 'requestKey' => (string) Str::uuid()]))->assertForbidden();
    $this->actingAs($admin)->postJson(route('rentals.command', ['property' => $state['property']['id'], 'tenant' => $tenant->slug]), ['action' => 'demoCheckout', 'version' => $state['version'], 'requestKey' => (string) Str::uuid()])->assertUnprocessable();
    Http::assertNothingSent();
});

test('private team notes and files reauthorize membership and never leak storage paths', function (): void {
    [$tenant, $admin, $state] = rentalFixture();
    $member = rentalMember($tenant);
    $outsider = rentalMember($tenant);
    $state = rentalHousehold($tenant, $admin, $state, $member, 1);
    $state = rentalHousehold($tenant, $admin, $state, $outsider, 2);
    $state = rentalChange($tenant, $admin, $state, 'team', ['name' => 'Private maintenance', 'description' => 'Only assigned households', 'maintenance' => true]);
    $state = rentalChange($tenant, $admin, $state, 'teamMembership', ['teamId' => 'maintenance', 'householdId' => $state['households'][0]['id']]);
    $state = rentalChange($tenant, $member, $state, 'teamNote', ['teamId' => 'maintenance', 'body' => 'Private work note']);
    Storage::fake('rentals_private');
    $this->mock(TeamFileScanner::class)->shouldReceive('scan')->andReturn('clean');
    $key = (string) Str::uuid();
    $file = UploadedFile::fake()->createWithContent('private-note.txt', 'Fictional private team content');
    $result = app(RentalFiles::class)->upload($tenant, $member, $state['property']['id'], $file, ['version' => $state['version'], 'requestKey' => $key, 'teamId' => 'maintenance']);
    expect($result['state']['documents'])->toHaveCount(1)->and(json_encode($result))->not->toContain('storagePath')->not->toContain('sha256');
    $other = app(RentalRepository::class)->read($tenant, $outsider, $state['property']['id']);
    expect($other['documents'])->toBe([])->and($other['teams'][0]['notes'])->toBe([]);
    $url = route('rentals.file', ['property' => $state['property']['id'], 'file' => $key, 'tenant' => $tenant->slug]);
    $this->actingAs($outsider)->get($url)->assertNotFound();
    $this->actingAs($member)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $state = rentalChange($tenant, $admin, $result['state'], 'teamMembership', ['teamId' => 'maintenance', 'householdId' => $state['households'][0]['id']]);
    $this->get($url)->assertNotFound();
    expect(app(RentalRepository::class)->read($tenant, $member, $state['property']['id'])['documents'])->toBe([]);
});

test('uncleared file bytes cannot be published and viewers cannot mutate', function (): void {
    [$tenant, $admin, $state] = rentalFixture();
    $viewer = rentalMember($tenant, 'viewer');
    $state = rentalHousehold($tenant, $admin, $state, $viewer, 1);
    $this->mock(TeamFileScanner::class)->shouldReceive('scan')->andReturn('unavailable');
    $this->actingAs($admin)->post(route('rentals.upload', ['property' => $state['property']['id'], 'tenant' => $tenant->slug]), ['file' => UploadedFile::fake()->createWithContent('note.txt', 'Sample'), 'version' => $state['version'], 'requestKey' => (string) Str::uuid()])->assertStatus(422);
    $this->actingAs($viewer)->postJson(route('rentals.command', ['property' => $state['property']['id'], 'tenant' => $tenant->slug]), ['action' => 'announcement', 'title' => 'Denied', 'body' => 'Denied', 'version' => $state['version'], 'requestKey' => (string) Str::uuid()])->assertForbidden();
    expect(app(RentalRepository::class)->read($tenant, $viewer, $state['property']['id'])['capabilities']['write'])->toBeFalse();
});

test('private dues and message drafts stay with their household and role is resolved server side', function (): void {
    [$tenant, $admin, $state] = rentalFixture();
    $a = rentalMember($tenant);
    $b = rentalMember($tenant);
    $state = rentalHousehold($tenant, $admin, $state, $a, 1);
    $state = rentalHousehold($tenant, $admin, $state, $b, 2);
    foreach ($state['households'] as $household) {
        $state = rentalChange($tenant, $admin, $state, 'chargeDues', ['householdId' => $household['id'], 'amountCents' => 15000, 'dueDate' => '2035-01-01', 'description' => 'Private charge '.$household['id']]);
    }
    $state = rentalChange($tenant, $admin, $state, 'message', ['recipientIds' => [$state['households'][1]['id']], 'channel' => 'email', 'subject' => 'Private draft', 'body' => 'Second household only']);
    $view = app(RentalRepository::class)->read($tenant, $a, $state['property']['id']);
    expect($view['dues'])->toHaveCount(1)->and($view['dues'][0]['householdId'])->toBe($state['households'][0]['id'])
        ->and($view['messages'])->toBe([])->and($view['audit'])->toBe([])->and($view['members'])->toBe([]);
    $this->actingAs($a)->postJson(route('rentals.command', ['property' => $state['property']['id'], 'tenant' => $tenant->slug]), [
        'action' => 'announcement', 'admin' => true, 'persona' => 'admin', 'title' => 'Spoofed', 'body' => 'Denied', 'version' => $state['version'], 'requestKey' => (string) Str::uuid(),
    ])->assertForbidden();
    Http::assertNothingSent();
});

test('held and overlapping weeks reject changes and comment deletion checks the actual author', function (): void {
    [$tenant, $admin, $state] = rentalFixture();
    $a = rentalMember($tenant);
    $b = rentalMember($tenant);
    $state = rentalHousehold($tenant, $admin, $state, $a, 1);
    $state = rentalHousehold($tenant, $admin, $state, $b, 2);
    $state = rentalChange($tenant, $admin, $state, 'createStay', ['start' => '2035-07-01', 'end' => '2035-07-08', 'held' => true]);
    $url = route('rentals.command', ['property' => $state['property']['id'], 'tenant' => $tenant->slug]);
    $this->actingAs($admin)->postJson($url, ['action' => 'createStay', 'start' => '2035-07-03', 'end' => '2035-07-10', 'held' => true, 'version' => $state['version'], 'requestKey' => (string) Str::uuid()])->assertConflict();
    $this->actingAs($a)->postJson($url, ['action' => 'claimWeek', 'weekId' => $state['weeks'][0]['id'], 'version' => $state['version'], 'requestKey' => (string) Str::uuid()])->assertConflict();
    $state = rentalChange($tenant, $a, $state, 'stayComment', ['weekId' => $state['weeks'][0]['id'], 'body' => 'House-care note', 'authorUserId' => (string) $b->id]);
    $comment = $state['weeks'][0]['comments'][0];
    expect($comment['authorUserId'])->toBe((string) $a->id);
    $this->actingAs($b)->postJson($url, ['action' => 'deleteStayComment', 'weekId' => $state['weeks'][0]['id'], 'commentId' => $comment['id'], 'version' => $state['version'], 'requestKey' => (string) Str::uuid()])->assertForbidden();
    $state = rentalChange($tenant, $admin, $state, 'deleteStayComment', ['weekId' => $state['weeks'][0]['id'], 'commentId' => $comment['id']]);
    expect($state['weeks'][0]['comments'])->toBe([]);
});

test('owner stays require an ownership slot belonging to the allocated household', function (): void {
    [$tenant, $admin, $state] = rentalFixture();
    $a = rentalMember($tenant);
    $b = rentalMember($tenant);
    $state = rentalHousehold($tenant, $admin, $state, $a, 1);
    $state = rentalHousehold($tenant, $admin, $state, $b, 2);
    $url = route('rentals.command', ['property' => $state['property']['id'], 'tenant' => $tenant->slug]);
    $input = ['action' => 'createStay', 'start' => '2035-07-01', 'end' => '2035-07-08', 'held' => false,
        'householdId' => $state['households'][0]['id'], 'version' => $state['version']];
    foreach ([null, 2, 99] as $share) {
        $this->actingAs($admin)->postJson($url, $input + ['share' => $share, 'requestKey' => (string) Str::uuid()])->assertUnprocessable();
    }
    $state = rentalChange($tenant, $admin, $state, 'createStay', ['start' => '2035-07-01', 'end' => '2035-07-08', 'held' => false,
        'householdId' => $state['households'][0]['id'], 'share' => 1]);
    expect($state['weeks'][0]['share'])->toBe(1)->and($state['weeks'][0]['owner'])->toBe($state['households'][0]['id']);
    $state = rentalChange($tenant, $admin, $state, 'createStay', ['start' => '2035-07-08', 'end' => '2035-07-15', 'held' => true, 'share' => 2]);
    expect($state['weeks'][1]['share'])->toBe(0)->and($state['weeks'][1]['owner'])->toBe('');
});

test('temporary occupants cannot request a permanent transfer of another household allocation', function (): void {
    [$tenant, $admin, $state] = rentalFixture();
    $a = rentalMember($tenant);
    $b = rentalMember($tenant);
    $state = rentalHousehold($tenant, $admin, $state, $a, 1);
    $state = rentalHousehold($tenant, $admin, $state, $b, 2);
    $state = rentalChange($tenant, $admin, $state, 'createStay', ['start' => '2035-07-01', 'end' => '2035-07-08', 'held' => false,
        'householdId' => $state['households'][0]['id'], 'share' => 1]);
    $week = $state['weeks'][0];
    $state = rentalChange($tenant, $a, $state, 'releaseWeek', ['weekId' => $week['id']]);
    $state = rentalChange($tenant, $b, $state, 'claimWeek', ['weekId' => $week['id']]);
    $this->actingAs($b)->postJson(route('rentals.command', ['property' => $state['property']['id'], 'tenant' => $tenant->slug]), [
        'action' => 'permanentChange', 'weekId' => $week['id'], 'householdId' => $state['households'][1]['id'],
        'reason' => 'Temporary occupancy is not permanent ownership', 'version' => $state['version'], 'requestKey' => (string) Str::uuid(),
    ])->assertConflict();
    $current = app(RentalRepository::class)->read($tenant, $admin, $state['property']['id']);
    expect($current['permanentRequests'])->toBe([])->and($current['weeks'][0]['owner'])->toBe($state['households'][0]['id']);
    $state = rentalChange($tenant, $admin, $current, 'createStay', ['start' => '2035-07-08', 'end' => '2035-07-15', 'held' => false,
        'householdId' => $state['households'][0]['id'], 'share' => 1]);
    $state = rentalChange($tenant, $a, $state, 'permanentChange', ['weekId' => $state['weeks'][1]['id'],
        'householdId' => $state['households'][1]['id'], 'reason' => 'Owner-requested reviewed transfer']);
    $state = rentalChange($tenant, $admin, $state, 'adminApprove', ['requestId' => $state['permanentRequests'][0]['id']]);
    expect($state['weeks'][1]['owner'])->toBe($state['households'][1]['id'])
        ->and($state['weeks'][1]['originalOwner'])->toBe($state['households'][0]['id'])
        ->and($state['permanentRequests'][0]['status'])->toBe('approved');
});

test('received receipt dates use the property day instead of the UTC day', function (string $timezone, string $instant, string $localDate, string $tomorrow): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse($instant));
    [$tenant, $admin, $state] = rentalFixture();
    $state = rentalChange($tenant, $admin, $state, 'property', ['name' => 'Fictional timezone villa', 'timezone' => $timezone]);
    $state = rentalHousehold($tenant, $admin, $state, rentalMember($tenant), 1);
    $state = rentalChange($tenant, $admin, $state, 'chargeDues', ['householdId' => $state['households'][0]['id'],
        'amountCents' => 10000, 'dueDate' => '2035-07-01', 'description' => 'Reviewed dues']);
    expect($state['today'])->toBe($localDate);
    $state = rentalChange($tenant, $admin, $state, 'recordPayment', ['duesId' => $state['dues'][0]['id'], 'amountCents' => 100,
        'method' => 'cash', 'date' => $localDate, 'reference' => 'Received on the property local day']);
    expect($state['dues'][0]['payments'][0]['date'])->toBe($localDate);
    $this->actingAs($admin)->postJson(route('rentals.command', ['property' => $state['property']['id'], 'tenant' => $tenant->slug]), [
        'action' => 'recordPayment', 'duesId' => $state['dues'][0]['id'], 'amountCents' => 100, 'method' => 'cash', 'date' => $tomorrow,
        'reference' => 'Future local receipt', 'version' => $state['version'], 'requestKey' => (string) Str::uuid(),
    ])->assertUnprocessable();
})->with([
    'property ahead of UTC' => ['Pacific/Kiritimati', '2035-06-01T12:00:00Z', '2035-06-02', '2035-06-03'],
    'property behind UTC' => ['Pacific/Honolulu', '2035-06-01T01:00:00Z', '2035-05-31', '2035-06-01'],
]);
