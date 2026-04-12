<?php

use App\Services\Navigation\UnifiedAppNavigationService;
use App\Services\Search\Providers\NavigationSearchProvider;
use Illuminate\Http\Request;

test('navigation search indexes a parent Production item and its direct children, but not grandchildren', function (): void {
    $nav = [
        'items' => [
            [
                'key' => 'production',
                'label' => 'Production',
                'href' => '/production',
                'icon' => 'beaker',
                'children' => [
                    [
                        'key' => 'shipping',
                        'label' => 'Shipping',
                        'href' => '/shipping/orders',
                        'icon' => 'truck',
                    ],
                    [
                        'key' => 'pouring',
                        'label' => 'Pouring',
                        'href' => '/pouring',
                        // Grandchildren should never be indexed.
                        'children' => [
                            [
                                'key' => 'deep',
                                'label' => 'Deep Nested',
                                'href' => '/pouring/deep',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'key' => 'marketing',
                'label' => 'Customer Hub',
                'href' => '/marketing',
                'icon' => 'megaphone',
            ],
        ],
    ];

    $fakeNavService = Mockery::mock(UnifiedAppNavigationService::class);
    $fakeNavService->shouldReceive('build')->andReturn($nav);

    $provider = new NavigationSearchProvider($fakeNavService);

    $request = Request::create('/dashboard');

    $productionResults = $provider->search('Production', ['request' => $request]);
    expect(collect($productionResults)->contains(
        fn (array $row): bool => ($row['title'] ?? null) === 'Production'
    ))->toBeTrue();

    $shippingResults = $provider->search('Shipping', ['request' => $request]);
    $shipping = collect($shippingResults)->firstWhere('title', 'Shipping');
    expect($shipping)->not->toBeNull()
        ->and((string) ($shipping['subtitle'] ?? ''))->toContain('Production');

    $deepResults = $provider->search('Deep Nested', ['request' => $request]);
    expect($deepResults)->toBe([]);
});

test('navigation search preserves top-level items and includes parent context for child results', function (): void {
    $nav = [
        'items' => [
            [
                'key' => 'production',
                'label' => 'Production',
                'href' => '/production',
                'icon' => 'beaker',
                'children' => [
                    [
                        'key' => 'markets',
                        'label' => 'Markets',
                        'href' => '/markets',
                    ],
                    [
                        'key' => 'inventory',
                        'label' => 'Inventory',
                        'href' => '/inventory',
                        // No icon -> should inherit parent icon.
                    ],
                ],
            ],
            [
                'key' => 'marketing',
                'label' => 'Customer Hub',
                'href' => '/marketing',
                'icon' => 'megaphone',
            ],
        ],
    ];

    $fakeNavService = Mockery::mock(UnifiedAppNavigationService::class);
    $fakeNavService->shouldReceive('build')->andReturn($nav);

    $provider = new NavigationSearchProvider($fakeNavService);
    $request = Request::create('/dashboard');

    $customerHub = collect($provider->search('Customer', ['request' => $request]))
        ->firstWhere('title', 'Customer Hub');
    expect($customerHub)->not->toBeNull()
        ->and((string) ($customerHub['url'] ?? ''))->toBe('/marketing');

    $inventory = collect($provider->search('Inventory', ['request' => $request]))
        ->firstWhere('title', 'Inventory');
    expect($inventory)->not->toBeNull()
        ->and((string) ($inventory['subtitle'] ?? ''))->toContain('Production')
        ->and((string) ($inventory['badge'] ?? ''))->toBe('Production')
        ->and((string) ($inventory['icon'] ?? ''))->toBe('beaker');
});

test('navigation search supports legacy workflow aliases without changing visible titles', function (): void {
    $nav = [
        'items' => [
            [
                'key' => 'production',
                'label' => 'Production',
                'href' => '/production',
                'icon' => 'beaker',
                'children' => [
                    [
                        'key' => 'shipping',
                        'label' => 'Shipping',
                        'href' => '/shipping/orders',
                    ],
                    [
                        'key' => 'pouring',
                        'label' => 'Pouring',
                        'href' => '/pouring',
                    ],
                    [
                        'key' => 'retail-plan',
                        'label' => 'Pour Lists',
                        'href' => '/retail/plan',
                    ],
                ],
            ],
        ],
    ];

    $fakeNavService = Mockery::mock(UnifiedAppNavigationService::class);
    $fakeNavService->shouldReceive('build')->andReturn($nav);

    $provider = new NavigationSearchProvider($fakeNavService);
    $request = Request::create('/dashboard');

    $shipping = collect($provider->search('Shipping Room', ['request' => $request]))
        ->firstWhere('title', 'Shipping');
    expect($shipping)->not->toBeNull()
        ->and((string) ($shipping['title'] ?? ''))->toBe('Shipping');

    $pouring = collect($provider->search('Pouring Room', ['request' => $request]))
        ->firstWhere('title', 'Pouring');
    expect($pouring)->not->toBeNull()
        ->and((string) ($pouring['title'] ?? ''))->toBe('Pouring');

    $pourLists = collect($provider->search('Retail Plan', ['request' => $request]))
        ->firstWhere('title', 'Pour Lists');
    expect($pourLists)->not->toBeNull()
        ->and((string) ($pourLists['title'] ?? ''))->toBe('Pour Lists')
        ->and((string) ($pourLists['subtitle'] ?? ''))->toContain('Production');
});
