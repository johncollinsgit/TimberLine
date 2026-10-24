<?php

namespace Tests\Unit\Enterprise;

use App\Enterprise\GroundedEvidence;
use PHPUnit\Framework\TestCase;

final class GroundedEvidenceTest extends TestCase
{
    public function test_selection_is_bounded_deduplicated_and_math_uses_only_matching_selected_record_inputs(): void
    {
        $sources = [];
        foreach ([['a', 0, 'estimate', 'USD'], ['b', 700, 'recorded_cost', 'USD'], ['c', 900, 'recorded_cost', 'EUR']] as [$id, $amount, $basis, $currency]) {
            $sources[] = ['ref' => 'cost:'.$id, 'title' => $id, 'facts' => ['project_id' => 'p', 'amount_minor' => $amount, 'basis' => $basis, 'currency' => $currency]];
        }
        for ($i = 0; $i < 10; $i++) {
            $sources[] = ['ref' => 'request:'.$i, 'title' => 'Ticket', 'facts' => ['status' => 'new']];
        }
        $group = ['project_id' => 'p', 'project_title' => 'Project', 'currency' => 'USD',
            'estimate_refs' => ['cost:a', 'cost:a'], 'recorded_cost_refs' => ['cost:b', 'cost:b', 'cost:c', 'cost:unselected', 'request:0']];
        $records = ['sources' => [...$sources, $sources[0]], 'comparisons' => [$group], 'building' => ['id' => 'hall']];
        $selected = GroundedEvidence::selected($records);
        $this->assertCount(8, $selected['sources']);
        $this->assertCount(8, array_unique(array_column($selected['sources'], 'ref')));
        $this->assertSame(0, $selected['calculations'][0]['estimate_minor']);
        $this->assertSame(700, $selected['calculations'][0]['recorded_cost_minor']);
        $this->assertSame(700, $selected['calculations'][0]['difference_minor']);
        $this->assertSame(['cost:a', 'cost:b'], $selected['calculations'][0]['input_refs']);
        $records['sources'] = [$sources[1]];
        $missing = GroundedEvidence::selected($records)['calculations'][0];
        $this->assertNull($missing['estimate_minor']);
        $this->assertNull($missing['difference_minor']);
        $records['comparisons'][0]['project_id'] = 'unrelated';
        $this->assertSame([], GroundedEvidence::selected($records)['calculations']);
    }

    public function test_full_bounded_stamp_keeps_unselected_sources_and_excludes_only_ephemeral_envelope(): void
    {
        $records = ['sources' => array_map(fn ($id) => ['ref' => 'request:'.$id, 'facts' => ['version' => 1]], range(1, 10)),
            'comparisons' => [], 'jobs' => [], 'building' => null, 'as_of' => 'one', 'reply' => 'Do not forward aggregate text'];
        $first = GroundedEvidence::stamp($records);
        $records['as_of'] = 'two';
        $records['reply'] = 'Changed envelope';
        $this->assertSame($first, GroundedEvidence::stamp($records));
        $records['sources'][9]['facts']['version'] = 2;
        $this->assertNotSame($first, GroundedEvidence::stamp($records));
        $this->assertCount(10, GroundedEvidence::stamp($records)['sources']);
        $this->assertCount(8, GroundedEvidence::selected($records)['sources']);
    }
}
