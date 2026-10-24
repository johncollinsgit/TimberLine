<?php

namespace Tests\Feature\Proposals;

use App\Proposals\CampusReviewController;
use App\Proposals\CampusReviewManifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ReviewerWorkspaceDownloadsFixture;

/** Rendered admission only. No scanner/provider/hosted download acceptance. */
final class ReviewerWorkspaceDownloadPresentationTest extends ReviewerWorkspaceDownloadsFixture
{
    private function render(): \Symfony\Component\HttpFoundation\Response
    {
        config(['enterprise.reviewer_demo_enabled' => true]);
        $request = $this->reader();
        app()->instance('request', $request);

        return app(CampusReviewController::class)->show($request, $this->token, 'requests');
    }

    private function boot(string $html): array
    {
        $this->assertSame(1, preg_match('/const presentation = (\{[^\n]+\}),\s*baseTransport/', $html, $matches));

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_pdf_boot_is_target_free_independent_of_images_and_uses_current_private_publication_without_writes(): void
    {
        $before = DB::table('team_operation_records')->orderBy('id')->get()->toJson();
        $response = $this->render();
        $this->assertSame(200, $response->getStatusCode());
        $html = $response->getContent();
        $boot = $this->boot($html);
        $this->assertArrayHasKey('reviewerAttachments', $boot);
        $this->assertArrayNotHasKey('reviewerImages', $boot);
        $this->assertArrayHasKey('reviewerDownloads', $boot);
        $this->assertArrayNotHasKey('target', $boot['reviewerDownloads']);
        $this->assertArrayNotHasKey('can_upload', $boot['reviewerDownloads']);
        $this->assertSame($boot['reviewerWorkspace']['binding'], $boot['reviewerDownloads']['binding']);
        $this->assertSame($boot['reviewerWorkspace']['browser_context'], $boot['reviewerDownloads']['browser_context']);
        foreach (['metadata', 'download'] as $action) {
            $this->assertStringEndsWith('/service-proposals/'.$this->token.'/campus/workspace/downloads/'.$action, $boot['reviewerDownloads']['endpoints'][$action]);
        }
        $this->assertSame(1, substr_count($html, 'root.EverbranchWorkspaceDownloads = Object.freeze({create})'));
        $this->assertSame(1, substr_count($html, '<template id="shared-work-files-template">'));
        $this->assertSame(1, substr_count($html, '<dialog id="attachment-gallery"'));
        $this->assertStringContainsString('img-src data: blob:', $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("frame-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertSame($before, DB::table('team_operation_records')->orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::table('team_tasks')->count());
        $this->assertSame(0, $this->fakeEvidence->calls);
        $this->assertSame(0, $this->fakeScanner->calls);
    }

    public function test_disabled_pdf_does_not_load_client_and_invalid_present_admission_never_falls_back(): void
    {
        config(['service_proposals.reviewer_workspace.downloads.enabled' => false]);
        $html = $this->render()->getContent();
        $boot = $this->boot($html);
        $this->assertArrayNotHasKey('reviewerDownloads', $boot);
        $this->assertStringNotContainsString('root.EverbranchWorkspaceDownloads = Object.freeze({create})', $html);
        config(['service_proposals.reviewer_workspace.downloads.enabled' => true,
            'service_proposals.reviewer_workspace.attachments.enabled' => false]);
        try {
            $this->render();
            $this->fail('PDF advertised without attachment admission.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        config(['service_proposals.reviewer_workspace.attachments.enabled' => true,
            'service_proposals.reviewer_workspace.downloads.policies' => []]);
        try {
            $this->render();
            $this->fail('Invalid immutable PDF policy fell back to a browser sample.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        $this->assertSame(0, $this->fakeEvidence->calls);
        $this->assertSame(0, $this->fakeScanner->calls);
    }

    public function test_pdf_route_and_clients_remain_in_the_fixed_source_closure(): void
    {
        $original = base_path();
        $temporary = sys_get_temp_dir().'/campus-pdf-closure-'.bin2hex(random_bytes(12));
        $paths = ['routes/service-proposals-reviewer-downloads.php', 'resources/js/enterprise/reviewer/workspace-download-client.js',
            'app/Proposals/ReviewerWorkspaceDownloadEvidence.php', 'app/Proposals/ReviewerWorkspaceFileScanLeases.php'];
        config(['service_proposals.campus_review.enabled' => true, 'service_proposals.campus_review.build_id' => 'pdf-closure']);
        try {
            foreach ($paths as $path) {
                File::ensureDirectoryExists(dirname($temporary.'/'.$path), 0700);
                file_put_contents($temporary.'/'.$path, 'original dependency');
            }
            app()->setBasePath($temporary);
            $manifest = new CampusReviewManifest;
            $before = $manifest->current()['source_hash'];
            foreach ($paths as $path) {
                file_put_contents($temporary.'/'.$path, 'changed dependency');
                $this->assertNotSame($before, $manifest->current()['source_hash'], $path);
                file_put_contents($temporary.'/'.$path, 'original dependency');
                $this->assertSame($before, $manifest->current()['source_hash']);
            }
        } finally {
            app()->setBasePath($original);
            File::deleteDirectory($temporary);
        }
    }
}
