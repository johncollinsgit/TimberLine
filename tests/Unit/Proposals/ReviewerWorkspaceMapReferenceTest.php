<?php

namespace Tests\Unit\Proposals;

use App\Proposals\ReviewerWorkspaceMapReference as Map;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/app/Proposals/ReviewerWorkspaceMapReference.php';
require_once dirname(__DIR__, 3).'/app/Proposals/ReviewerWorkspaceSchema.php';

final class ReviewerWorkspaceMapReferenceTest extends TestCase
{
    private function reference(string $number = '2'): array
    {
        return ['schema_version' => 1, 'catalog' => Map::CATALOG, 'catalog_sha256' => Map::CATALOG_SHA256,
            'map_number' => $number, 'source_url' => Map::SOURCE_URL, 'source_page' => 2];
    }

    public function test_every_closed_catalog_number_resolves_against_exact_current_pinned_files(): void
    {
        for ($number = 1; $number <= 68; $number++) {
            $reference = $this->reference((string) $number);
            self::assertSame($reference, Map::validate($reference));
        }
    }

    public function test_present_invalid_reference_never_becomes_an_absent_fallback(): void
    {
        $cases = [null, [], '2', [...$this->reference(), 'x' => 5], [...$this->reference(), 'latitude' => 36.1]];
        foreach (['schema_version' => 2, 'catalog' => 'foreign', 'catalog_sha256' => str_repeat('a', 64),
            'source_url' => Map::SOURCE_URL.'?redirect=1', 'source_page' => 1, 'map_number' => "2\n"] as $key => $value) {
            $cases[] = [...$this->reference(), $key => $value];
        }
        foreach ([2, '02', '0', '69', '-1', '2.0', '2e0', ' 2', '', null] as $number) {
            $cases[] = [...$this->reference(), 'map_number' => $number];
        }
        foreach ($cases as $invalid) {
            try {
                Map::validate($invalid);
                self::fail('Invalid official reference was accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Official building map reference is unavailable.', $error->getMessage());
            }
        }
    }

    public function test_changed_catalog_or_plan_bytes_refuse_without_mutating_the_test_checkout(): void
    {
        foreach (['lees-mcrae-locations.json', 'campus-map-points.json'] as $changed) {
            $root = sys_get_temp_dir().'/workspace-map-'.bin2hex(random_bytes(8));
            mkdir($root.'/app/Proposals', 0700, true);
            mkdir($root.'/resources/data/enterprise', 0700, true);
            $source = dirname(__DIR__, 3);
            copy($source.'/app/Proposals/ReviewerWorkspaceMapReference.php', $root.'/app/Proposals/ReviewerWorkspaceMapReference.php');
            foreach (['lees-mcrae-locations.json', 'campus-map-points.json'] as $file) {
                copy($source.'/resources/data/enterprise/'.$file, $root.'/resources/data/enterprise/'.$file);
            }
            try {
                file_put_contents($root.'/resources/data/enterprise/'.$changed, "\n", FILE_APPEND);
                $code = 'require '.$this->literal($root.'/app/Proposals/ReviewerWorkspaceMapReference.php').';'
                    .'try{App\\Proposals\\ReviewerWorkspaceMapReference::validate('.var_export($this->reference(), true).');exit(9);}'
                    .'catch(InvalidArgumentException $e){exit($e->getMessage()==="Official building map reference is unavailable."?0:8);}';
                $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                self::assertSame('', stream_get_contents($pipes[1]));
                self::assertSame('', stream_get_contents($pipes[2]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process));
            } finally {
                unlink($root.'/app/Proposals/ReviewerWorkspaceMapReference.php');
                foreach (['lees-mcrae-locations.json', 'campus-map-points.json'] as $file) {
                    unlink($root.'/resources/data/enterprise/'.$file);
                }
                foreach (['app/Proposals', 'app', 'resources/data/enterprise', 'resources/data', 'resources', ''] as $dir) {
                    rmdir($root.($dir === '' ? '' : '/'.$dir));
                }
            }
        }
    }

    public function test_actual_schema_hook_preserves_absence_and_rejects_present_invalid_refs(): void
    {
        new \Illuminate\Foundation\Application(dirname(__DIR__, 3)); // No Kernel/ENV/DB boot.
        $seed = json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/proposals/reviewer-workspace-map-seed.json'), true, 32, JSON_THROW_ON_ERROR);
        $schema = new \App\Proposals\ReviewerWorkspaceSchema;
        self::assertSame($seed['graph_sha256'], $schema::digest($schema->graph($seed['graph'], true)));
        $original = $seed['graph'];
        foreach ($original['buildings'] as &$building) {
            unset($building['map_reference']);
        }
        unset($building);
        self::assertSame($seed['parent_seed_sha256'], $schema::digest($schema->graph($original, true)));
        $invalid = $seed['graph'];
        $invalid['buildings'][0]['map_reference'] = null;
        try {
            $schema->graph($invalid, true);
            self::fail('Present invalid map reference was accepted by graph schema.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            self::assertSame(422, $error->getStatusCode());
        }
    }

    private function literal(string $value): string
    {
        return var_export($value, true);
    }
}
