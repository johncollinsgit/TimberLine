<?php

namespace Tests\Unit\Proposals;

use App\Proposals\LeesMcRaeDetails;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\TestCase;

final class ImplementationTimelineTest extends TestCase
{
    private function render(array $details): string
    {
        $files = new Filesystem;
        $compiled = sys_get_temp_dir().'/proposal-timeline-'.bin2hex(random_bytes(8));
        mkdir($compiled, 0700);
        try {
            $engines = new EngineResolver;
            $compiler = new BladeCompiler($files, $compiled);
            $engines->register('blade', fn () => new CompilerEngine($compiler, $files));
            $views = new Factory($engines, new FileViewFinder($files, [dirname(__DIR__, 3).'/resources/views']), new Dispatcher(new Container));

            return $views->make('service-proposals.implementation-timeline', compact('details'))->render();
        } finally {
            $files->deleteDirectory($compiled);
        }
    }

    public function test_new_draft_copy_matches_the_exact_approved_clause_and_preserves_access_acceptance(): void
    {
        $approved = json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/proposals/implementation-timeline.json'), true, 16, JSON_THROW_ON_ERROR);
        $details = LeesMcRaeDetails::content();
        $this->assertSame($approved['heading'], $details['implementation_timeline_heading']);
        $this->assertSame($approved['text'], $details['implementation_timeline']);
        $this->assertStringContainsString('Staff access begins only after explicit acceptance.', $details['activation']);
        $this->assertStringNotContainsString('Staff access and billing begin only after explicit acceptance.', $details['activation']);
        $this->assertStringNotContainsString('Billing start, phased billing and rollout duration remain to be agreed.', $details['rollout']);
        $this->assertStringContainsString('Phased billing amounts and rollout duration remain to be agreed.', $details['rollout']);
        $this->assertStringContainsString('$2,500/month bundle price is the TOTAL when all three are fully active.', $details['rollout']);
        $this->assertStringContainsString('without markup', $details['usage']);
        $this->assertStringContainsString('No unlimited allowance, $150 fee or automatic unapproved overage is included.', $details['usage']);
        $html = $this->render($details);
        $this->assertStringContainsString('<h2>'.$approved['heading'].'</h2>', $html);
        $this->assertStringContainsString($approved['text'], $html);
    }

    public function test_old_snapshot_without_the_new_clause_does_not_gain_changed_terms_from_source(): void
    {
        $old = ['activation' => 'Previously published acceptance text.', 'rollout' => 'Previously published rollout text.'];
        $before = $old;
        $this->assertSame('', trim($this->render($old)));
        $this->assertSame($before, $old);
        $this->assertSame('', trim($this->render(['implementation_timeline_heading' => LeesMcRaeDetails::IMPLEMENTATION_TIMELINE_HEADING])));
    }

    public function test_payload_copy_is_plain_text_and_never_executable_markup(): void
    {
        $html = $this->render(['implementation_timeline_heading' => '<img src=x onerror=alert(1)>', 'implementation_timeline' => '<script>not executable</script>']);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
