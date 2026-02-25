<?php

use App\Support\Markets\SheetNameParser;

it('parses full range with VMD name', function () {
    $parser = new SheetNameParser();

    $result = $parser->parse('VMD Asheville 03.14.25-03.16.25', 2025);

    expect($result['ignored'])->toBeFalse()
        ->and($result['market_name'])->toBe('VMD Asheville')
        ->and($result['starts_at'])->toBe('2025-03-14')
        ->and($result['ends_at'])->toBe('2025-03-16')
        ->and($result['city'])->toBeNull()
        ->and($result['state'])->toBeNull();
});

it('parses city state plus single date with workbook year fallback', function () {
    $parser = new SheetNameParser();

    $result = $parser->parse('VMD Midlands Columbia, SC 02.02', 2024);

    expect($result['market_name'])->toBe('VMD Midlands')
        ->and($result['city'])->toBe('Columbia')
        ->and($result['state'])->toBe('SC')
        ->and($result['starts_at'])->toBe('2024-02-02')
        ->and($result['ends_at'])->toBe('2024-02-02');
});

it('handles truncated end date range as medium confidence', function () {
    $parser = new SheetNameParser();

    $result = $parser->parse('Florida State Fair 02.08.24-02.', 2024);

    expect($result['market_name'])->toBe('Florida State Fair')
        ->and($result['starts_at'])->toBe('2024-02-08')
        ->and($result['ends_at'])->toBeNull()
        ->and($result['confidence'])->toBe('medium')
        ->and($result['notes'])->toContain('end date truncated');
});

it('marks truncated gilmore sheet as needs review with parsed location only', function () {
    $parser = new SheetNameParser();

    $result = $parser->parse('Gilmore Classics Columbia, SC 0', 2024);

    expect($result['market_name'])->toBe('Gilmore Classics')
        ->and($result['city'])->toBe('Columbia')
        ->and($result['state'])->toBe('SC')
        ->and($result['starts_at'])->toBeNull()
        ->and($result['needs_review'])->toBeTrue();
});

it('handles state only and missing dates as needs review', function () {
    $parser = new SheetNameParser();

    $result = $parser->parse('Palmetto Sportsman Classic, SC', 2024);

    expect($result['market_name'])->toBe('Palmetto Sportsman Classic')
        ->and($result['city'])->toBeNull()
        ->and($result['state'])->toBe('SC')
        ->and($result['starts_at'])->toBeNull()
        ->and($result['needs_review'])->toBeTrue();
});

it('ignores meta sheets like Sheet4', function () {
    $parser = new SheetNameParser();

    $result = $parser->parse('Sheet4', 2024);

    expect($result['ignored'])->toBeTrue()
        ->and($result['confidence'])->toBe('none');
});

it('uses location as fallback market name when sheet is mostly location plus date', function () {
    $parser = new SheetNameParser();

    $result = $parser->parse('Drayton Mills, SC 12.2.23', 2023);

    expect($result['market_name'])->toBe('Drayton Mills')
        ->and($result['city'])->toBe('Drayton Mills')
        ->and($result['state'])->toBe('SC')
        ->and($result['starts_at'])->toBe('2023-12-02')
        ->and($result['ends_at'])->toBe('2023-12-02');
});

it('infers workbook year for month-dot-day fragments without year', function () {
    $parser = new SheetNameParser();

    $result = $parser->parse('Pickens Azalea Festival SC 04.2', 2023);

    expect($result['starts_at'])->toBe('2023-04-02')
        ->and($result['ends_at'])->toBe('2023-04-02')
        ->and($result['date_confidence'])->toBe('medium');
});
