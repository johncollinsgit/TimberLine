<?php

namespace Tests\Unit\Proposals;

use App\Proposals\CampusReviewManifest;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class CampusReviewSourceBoundTest extends TestCase
{
    private function isolated(callable $test): void
    {
        $original = base_path();
        $temporary = sys_get_temp_dir().'/campus-source-bound-'.bin2hex(random_bytes(12));
        mkdir($temporary.'/resources/js/enterprise', 0700, true);
        config(['service_proposals.campus_review.enabled' => true, 'service_proposals.campus_review.build_id' => 'source-bound-fixture']);
        try {
            app()->setBasePath($temporary);
            $test($temporary);
        } finally {
            app()->setBasePath($original);
            File::deleteDirectory($temporary);
        }
    }

    public function test_expanded_fixed_source_closure_remains_hashed_without_omitting_files(): void
    {
        $this->isolated(function (string $root): void {
            // Fixed dependencies grew; 170 fixture members still exceed the old 200-entry closure
            // together with those dependencies, while retaining the reviewed 256-member cap.
            for ($i = 0; $i < 170; $i++) {
                file_put_contents($root.'/resources/js/enterprise/bounded-'.$i.'.js', '// fixture '.$i);
            }
            $manifest = new CampusReviewManifest;
            $first = $manifest->current();
            $this->assertSame($first, $manifest->current());
            file_put_contents($root.'/resources/js/enterprise/bounded-169.js', '// changed final dependency');
            $this->assertNotSame($first['source_hash'], $manifest->current()['source_hash']);
        });
    }

    public function test_unreviewed_file_growth_and_oversized_source_still_fail_closed(): void
    {
        $this->isolated(function (string $root): void {
            for ($i = 0; $i < 257; $i++) {
                file_put_contents($root.'/resources/js/enterprise/excess-'.$i.'.js', '// fixture');
            }
            try {
                (new CampusReviewManifest)->current();
                $this->fail('An unbounded source closure was admitted.');
            } catch (HttpExceptionInterface $failure) {
                $this->assertSame(503, $failure->getStatusCode());
            }
            File::cleanDirectory($root.'/resources/js/enterprise');
            $handle = fopen($root.'/resources/js/enterprise/oversize.js', 'w');
            ftruncate($handle, 20000001);
            fclose($handle);
            try {
                (new CampusReviewManifest)->current();
                $this->fail('Oversized source was admitted.');
            } catch (HttpExceptionInterface $failure) {
                $this->assertSame(503, $failure->getStatusCode());
            }
        });
    }

    public function test_runtime_nested_partials_and_inline_tour_asset_are_hash_bound(): void
    {
        $this->isolated(function (string $root): void {
            $paths = [
                'resources/views/enterprise/partials/usage-overview.blade.php',
                'resources/views/enterprise/partials/reviewer-workspace-files.blade.php',
                'resources/views/enterprise/partials/future-reviewed-partial.blade.php',
                'resources/js/enterprise/reviewer/assets/lees-mcrae-official-wordmark.png',
                'routes/service-proposals-reviewer-images.php',
                'app/Services/Team/TeamFileScanner.php',
                'config/team_work.php',
            ];
            foreach ($paths as $path) {
                if (! is_dir(dirname($root.'/'.$path))) {
                    mkdir(dirname($root.'/'.$path), 0700, true);
                }
                file_put_contents($root.'/'.$path, 'original dependency bytes');
            }
            $manifest = new CampusReviewManifest;
            $original = $manifest->current()['source_hash'];
            foreach ($paths as $path) {
                file_put_contents($root.'/'.$path, 'changed dependency bytes');
                $this->assertNotSame($original, $manifest->current()['source_hash'], $path);
                file_put_contents($root.'/'.$path, 'original dependency bytes');
                $this->assertSame($original, $manifest->current()['source_hash']);
            }
        });
    }

    public function test_actual_enterprise_blade_literal_inputs_are_all_hash_bound(): void
    {
        $paths = [];
        foreach (File::allFiles(resource_path('views/enterprise')) as $file) {
            $text = file_get_contents($file->getPathname());
            preg_match_all('/@include(?:If|When|Unless)?\(\s*[\'\"]([^\'\"]+)[\'\"]/u', $text, $includes);
            foreach ($includes[1] as $view) {
                $paths[] = 'resources/views/'.str_replace('.', '/', $view).'.blade.php';
            }
            preg_match_all('/file_get_contents\(resource_path\(\s*[\'\"]([^\'\"]+)[\'\"]/u', $text, $reads);
            foreach ($reads[1] as $resource) {
                $paths[] = 'resources/'.$resource;
            }
        }
        $paths = array_values(array_unique($paths));
        $this->assertNotEmpty($paths);
        $this->isolated(function (string $root) use ($paths): void {
            foreach ($paths as $path) {
                if (! is_dir(dirname($root.'/'.$path))) {
                    mkdir(dirname($root.'/'.$path), 0700, true);
                }
                file_put_contents($root.'/'.$path, 'original runtime input');
            }
            $manifest = new CampusReviewManifest;
            $original = $manifest->current()['source_hash'];
            foreach ($paths as $path) {
                file_put_contents($root.'/'.$path, 'changed runtime input');
                $this->assertNotSame($original, $manifest->current()['source_hash'], $path);
                file_put_contents($root.'/'.$path, 'original runtime input');
                $this->assertSame($original, $manifest->current()['source_hash']);
            }
        });
    }
}
