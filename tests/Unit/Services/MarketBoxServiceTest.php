<?php

use App\Services\MarketBoxService;

it('expands full and half market boxes into fixed pour quantities', function () {
    $service = new MarketBoxService();

    expect($service->expand('full', 3))->toBe([
        '16oz' => 12,
        '8oz' => 24,
        'wax_melt' => 24,
    ]);

    expect($service->expand('half', 2))->toBe([
        '16oz' => 4,
        '8oz' => 8,
        'wax_melt' => 8,
    ]);
});

it('expands top shelf boxes using the admin-defined per-box recipe', function () {
    $service = new MarketBoxService();

    $totals = $service->expand('top_shelf', 4, [
        '16oz' => 1,
        '8oz' => 3,
        'wax_melt' => 2,
    ]);

    expect($totals)->toBe([
        '16oz' => 4,
        '8oz' => 12,
        'wax_melt' => 8,
    ]);
});

it('normalizes top shelf definition aliases and merges totals safely', function () {
    $service = new MarketBoxService();

    $normalized = $service->normalizeTopShelfDefinition([
        '16_oz' => '2',
        '8_oz' => 5,
        'wax_melts' => 7,
    ]);

    expect($normalized)->toBe([
        '16oz' => 2,
        '8oz' => 5,
        'wax_melt' => 7,
    ]);

    expect($service->mergeTotals($service->emptyTotals(), $normalized))->toBe($normalized);
});

