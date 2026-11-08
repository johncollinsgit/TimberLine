<?php

use App\Models\TeamArea;
use App\Services\Team\Studio\ModernForestryPolicy;
use Illuminate\Support\Str;

require_once __DIR__.'/TeamFixtures.php';

function studioPolicy(): array
{
    return array_replace(app(ModernForestryPolicy::class)->draft(), ['requires_confirmation' => [], 'required_categories' => []]);
}
function studioInputs(array $changes = []): array
{
    return array_replace(['starts_on' => '2026-11-06', 'ends_on' => '2026-11-08', 'selling_days' => 3, 'crew' => [], 'fees' => [], 'hotels' => [], 'trips' => [], 'expenses' => []], $changes);
}
function studioArea($tenant): TeamArea
{
    return TeamArea::create(['id' => Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Fictional shows', 'slug' => 'shows', 'profile' => 'events', 'settings' => ['source_authority' => 'teams']]);
}
function studioDefinition($t, $u, $a, array $overrides = []): array
{
    $i = array_replace(['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'kind' => 'cost_policy', 'publish' => true, 'definition' => studioPolicy()], $overrides);
    test()->actingAs($u)->postJson('/team/studio/definition?tenant='.$t->slug.'&area='.$a->id, $i)->assertOk();

    return $i;
}
