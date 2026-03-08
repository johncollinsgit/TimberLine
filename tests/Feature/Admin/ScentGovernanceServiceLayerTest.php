<?php

use App\Actions\ScentGovernance\CreateScentAction;
use App\Actions\ScentGovernance\CreateScentAliasAction;
use App\Livewire\Admin\ScentWizard;
use App\Models\Scent;
use App\Models\ScentAlias;
use App\Models\User;
use App\Services\ScentGovernance\ResolveScentMatchService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('create scent action blocks duplicate normalized names', function () {
    $baselineCount = Scent::query()->count();

    Scent::query()->create([
        'name' => 'vintage amber',
        'display_name' => 'Vintage Amber',
        'is_active' => true,
    ]);

    $action = app(CreateScentAction::class);

    expect(fn () => $action->execute([
        'name' => 'Vintage Amber',
        'display_name' => 'Vintage Amber',
        'is_active' => true,
    ]))->toThrow(ValidationException::class);

    expect(Scent::query()->count())->toBe($baselineCount + 1);
});

test('create scent alias action enforces alias uniqueness by scope', function () {
    $first = Scent::query()->create([
        'name' => 'first scent',
        'display_name' => 'First Scent',
        'is_active' => true,
    ]);
    $second = Scent::query()->create([
        'name' => 'second scent',
        'display_name' => 'Second Scent',
        'is_active' => true,
    ]);

    $action = app(CreateScentAliasAction::class);
    $action->execute($first, 'Sale Candles', 'markets');
    $action->execute($second, 'Sale Candles', 'markets');
    $action->execute($first, 'Sale Candles', 'wholesale');

    expect(ScentAlias::query()->where('alias', 'sale candles')->where('scope', 'markets')->count())->toBe(1);
    expect(ScentAlias::query()->where('alias', 'sale candles')->where('scope', 'markets')->value('scent_id'))->toBe($second->id);
    expect(ScentAlias::query()->where('alias', 'sale candles')->where('scope', 'wholesale')->count())->toBe(1);
});

test('resolve scent match service finds account-scoped wholesale aliases', function () {
    $scent = Scent::query()->create([
        'name' => 'vintage amber',
        'display_name' => 'Vintage Amber',
        'is_wholesale_custom' => true,
        'is_active' => true,
    ]);

    ScentAlias::query()->create([
        'alias' => 'custom scent',
        'scope' => 'account:erin nutz',
        'scent_id' => $scent->id,
    ]);

    $resolver = app(ResolveScentMatchService::class);
    $match = $resolver->findExistingScent('Custom Scent', [
        'is_wholesale' => true,
        'account_name' => 'ERIN NUTZ',
        'store_key' => 'wholesale',
    ]);

    expect($match?->id)->toBe($scent->id);

    $candidates = $resolver->resolveCandidates('custom scent', [
        'is_wholesale' => true,
        'account_name' => 'ERIN NUTZ',
        'store_key' => 'wholesale',
    ]);

    expect($candidates->pluck('id')->all())->toContain($scent->id);
});

test('scent wizard save delegates canonical creation to create scent action', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $created = Scent::query()->create([
        'name' => 'delegated scent target',
        'display_name' => 'Delegated Scent Target',
        'is_active' => true,
    ]);

    $mock = \Mockery::mock(CreateScentAction::class);
    $mock->shouldReceive('execute')
        ->once()
        ->withArgs(function (array $payload, string $prefix): bool {
            return $prefix === 'form.'
                && (string) ($payload['name'] ?? '') === 'Delegated New Scent'
                && (string) ($payload['lifecycle_status'] ?? '') === 'draft';
        })
        ->andReturn($created);
    app()->instance(CreateScentAction::class, $mock);

    Livewire::test(ScentWizard::class)
        ->set('intent', ScentWizard::INTENT_NEW)
        ->set('form.name', 'Delegated New Scent')
        ->set('form.display_name', 'Delegated New Scent')
        ->set('form.lifecycle_status', 'draft')
        ->call('complete')
        ->assertSet('step', 5)
        ->assertSet('completion.mode', 'created')
        ->call('finish')
        ->assertRedirect(route('admin.index', ['tab' => 'master-data', 'resource' => 'scents']));
});

test('scent wizard can map to existing scent without creating a duplicate', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $existing = Scent::query()->create([
        'name' => 'vintage amber',
        'display_name' => 'Vintage Amber',
        'is_active' => true,
    ]);

    $mock = \Mockery::mock(CreateScentAction::class);
    $mock->shouldNotReceive('execute');
    app()->instance(CreateScentAction::class, $mock);

    Livewire::withQueryParams([
        'raw' => 'Vintage Amber',
        'return_to' => route('admin.index', ['tab' => 'scent-intake']),
    ])->test(ScentWizard::class)
        ->set('intent', ScentWizard::INTENT_MAP)
        ->set('selectedExistingScentId', $existing->id)
        ->set('alias.save_raw_as_alias', false)
        ->call('complete')
        ->assertSet('step', 5)
        ->assertSet('completion.mode', 'mapped')
        ->call('finish')
        ->assertRedirect(route('admin.index', ['tab' => 'scent-intake']));
});
