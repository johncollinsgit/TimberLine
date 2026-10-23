<?php

namespace Tests\Unit\Proposals;

use App\Proposals\CommercialSections;
use App\Proposals\CommercialSnapshot;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CommercialSnapshotTest extends TestCase
{
    private function fixture(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/proposals/lees-mcrae-v9-commercial.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function render(array $details): string
    {
        $files = new Filesystem;
        $compiled = sys_get_temp_dir().'/proposal-commercial-'.bin2hex(random_bytes(8));
        mkdir($compiled, 0700);
        try {
            $engines = new EngineResolver;
            $compiler = new BladeCompiler($files, $compiled);
            $engines->register('blade', fn () => new CompilerEngine($compiler, $files));
            $views = new Factory($engines, new FileViewFinder($files, [dirname(__DIR__, 3).'/resources/views']), new Dispatcher(new Container));

            return $views->make('service-proposals.commercial-terms', compact('details'))->render();
        } finally {
            $files->deleteDirectory($compiled);
        }
    }

    private function stored(): array
    {
        $stored = ['client' => ['id' => 'synthetic-client'], 'items' => [['id' => 'synthetic-item', 'revision' => 1, 'terms_months' => [12], 'charges' => [['amount_cents' => 100000]]]],
            'client_details' => $this->fixture()['client_details'], 'unresolved' => $this->fixture()['unresolved'], 'usage_plan' => 'unconfirmed', 'template' => null, 'scope_notes' => 'Synthetic agreed scope.', 'journey' => [['gate' => 'Stored reviewed discovery gate.']], 'approach_key' => 'evergrove_department_journey'];
        $stored['commercial_snapshot'] = ['schema_version' => 1, 'source_reference' => 'Synthetic reviewed PDF v9', 'source_copy_sha256' => str_repeat('a', 64),
            'catalog_origins' => ['synthetic-item' => ['revision' => 1, 'payload_sha256' => str_repeat('b', 64), 'terms_months' => []]],
            'item_overrides' => ['synthetic-item' => ['terms_months' => [12]]]];
        $stored['commercial_snapshot']['protected_sha256'] = CommercialSnapshot::hash($stored);

        return $stored;
    }

    public function test_stored_pdf_copy_is_complete_plain_text_and_old_versions_do_not_acquire_it(): void
    {
        $details = $this->fixture()['client_details'];
        $html = $this->render($details);
        $this->assertCount(8, CommercialSections::from($details));
        foreach ($details['commercial_sections'] as $section) {
            $this->assertStringContainsString(htmlspecialchars($section['heading'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
            foreach ([...($section['paragraphs'] ?? []), ...($section['bullets'] ?? [])] as $text) {
                $this->assertStringContainsString(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
            }
        }
        $this->assertStringContainsString('scope="col"', $html);
        $this->assertStringContainsString('scope="row"', $html);
        $this->assertStringContainsString('aria-label="Staged fees"', $html);
        $legacy = ['rollout' => 'Old rollout.', 'activation' => 'Old acceptance.', 'support' => 'Old support.', 'travel' => 'Old travel.', 'usage' => 'Old usage.'];
        $old = $this->render($legacy);
        $this->assertStringContainsString('Old acceptance.', $old);
        $this->assertStringNotContainsString('Contract term and renewal', $old);
        $this->assertStringNotContainsString('commercial-copy', $old);
        $details['commercial_sections'][0]['paragraphs'][0] = '<script>never executable</script>';
        $this->assertStringContainsString('&lt;script&gt;never executable&lt;/script&gt;', $this->render($details));
        $this->assertStringNotContainsString('<script>', $this->render($details));
    }

    public function test_ordinary_save_retains_reviewed_snapshot_instead_of_rebuilding_from_current_catalog(): void
    {
        $stored = $this->stored();
        $proposed = [...$stored, 'title' => 'Updated noncommercial title.', 'client_details' => [], 'unresolved' => ['Generated stale terms.'], 'journey' => [['gate' => 'Generated stale discovery gate.']]];
        $proposed['items'][0] = [...$proposed['items'][0], 'revision' => 2, 'terms_months' => [36], 'charges' => [['amount_cents' => 999999]]];
        $result = CommercialSnapshot::preserve($stored, $proposed);
        $this->assertSame('Updated noncommercial title.', $result['title']);
        $this->assertSame($stored['items'], $result['items']);
        $this->assertSame($stored['client_details'], $result['client_details']);
        $this->assertSame($stored['unresolved'], $result['unresolved']);
        $this->assertSame($stored['journey'], $result['journey']);
        $this->assertSame($stored['approach_key'], $result['approach_key']);
        $this->assertSame($stored['commercial_snapshot'], $result['commercial_snapshot']);
        $this->assertSame($stored['commercial_snapshot']['protected_sha256'], CommercialSnapshot::hash($result));
        $this->assertSame($proposed, CommercialSnapshot::preserve(['client_details' => []], $proposed));
    }

    public function test_scope_changes_and_incomplete_or_drifted_provenance_refuse_instead_of_silently_losing_terms(): void
    {
        $stored = $this->stored();
        $cases = [];
        foreach (['client' => ['id' => 'other-client'], 'items' => [], 'scope_notes' => 'Different scope.', 'usage_plan' => 'workspace_allowance', 'template' => ['id' => 'different-template'], 'approach_key' => null] as $key => $value) {
            $cases[] = [$stored, [...$stored, $key => $value]];
        }
        $drift = $stored;
        $drift['items'][0]['terms_months'] = [36];
        $cases[] = [$drift, $stored];
        $incomplete = $stored;
        unset($incomplete['commercial_snapshot']);
        $cases[] = [$incomplete, $stored];
        $incomplete = $stored;
        $incomplete['commercial_snapshot']['catalog_origins'] = [];
        $cases[] = [$incomplete, $stored];
        $originDrift = $stored;
        $originDrift['commercial_snapshot']['catalog_origins']['synthetic-item']['payload_sha256'] = str_repeat('c', 64);
        $cases[] = [$originDrift, $stored];
        foreach (['journey' => [['gate' => 'Changed stored gate.']], 'approach_key' => null] as $key => $value) {
            $this->assertNotSame(CommercialSnapshot::hash($stored), CommercialSnapshot::hash([...$stored, $key => $value]));
            $cases[] = [[...$stored, $key => $value], $stored];
        }
        foreach ($cases as [$before, $after]) {
            try {
                CommercialSnapshot::preserve($before, $after);
                $this->fail('A scope/provenance change must require a new review.');
            } catch (ConflictHttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        $malformed = $this->fixture()['client_details'];
        $malformed['commercial_sections'][0]['tables'][0]['rows'][0] = ['Wrong column count'];
        $this->assertNull(CommercialSections::from($malformed));
    }
}
