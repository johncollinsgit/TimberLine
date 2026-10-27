<?php

namespace Tests\Feature\Proposals;

use App\Proposals\CampusReviewController;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ReviewerWorkspaceImagesFixture;

/** Real source rendering/admission only; no scanner, file IO dispatch, provider or hosted acceptance. */
final class ReviewerWorkspaceFilePresentationTest extends ReviewerWorkspaceImagesFixture
{
    public function test_admitted_current_publication_renders_separate_file_descriptors_and_one_stable_template_without_writes(): void
    {
        config(['enterprise.reviewer_demo_enabled' => true]);
        $request = $this->reader();
        app()->instance('request', $request);
        $before = DB::table('team_operation_records')->orderBy('id')->get()->toJson();
        $response = app(CampusReviewController::class)->show($request, $this->token, 'requests');
        $this->assertSame(200, $response->getStatusCode());
        $html = $response->getContent();
        foreach (['reviewerAttachments', 'reviewerImages', 'EverbranchWorkspaceFileUi', 'EverbranchWorkspaceImages'] as $needle) {
            // The scripts are source-inlined; their factories, never a native identity, are rendered.
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertSame(1, substr_count($html, '<template id="shared-work-files-template">'));
        $this->assertSame(1, substr_count($html, '<dialog id="attachment-gallery"'));
        $this->assertStringContainsString("connect-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('unsafe-eval', $response->headers->get('Content-Security-Policy'));
        $this->assertSame($before, DB::table('team_operation_records')->orderBy('id')->get()->toJson());
        $this->assertSame(0, $this->fakeEvidence->calls);
        $this->assertSame(0, $this->fakeScanner->calls);
        $this->assertSame(0, DB::table('team_tasks')->count());
    }

    public function test_disabled_optional_files_are_not_booted_and_image_gate_cannot_advertise_without_attachment_admission(): void
    {
        config(['enterprise.reviewer_demo_enabled' => true, 'service_proposals.reviewer_workspace.attachments.enabled' => false,
            'service_proposals.reviewer_workspace.image_access.enabled' => false]);
        $request = $this->reader();
        app()->instance('request', $request);
        $response = app(CampusReviewController::class)->show($request, $this->token, 'requests');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('<template id="shared-work-files-template">', $response->getContent());
        $this->assertStringNotContainsString('"reviewerAttachments":{', $response->getContent());
        $this->assertStringNotContainsString('"reviewerImages":{', $response->getContent());
        config(['service_proposals.reviewer_workspace.image_access.enabled' => true]);
        try {
            app(CampusReviewController::class)->show($request, $this->token, 'requests');
            $this->fail('Images advertised without admitted attachments.');
        } catch (HttpExceptionInterface $failure) {
            $this->assertSame(503, $failure->getStatusCode());
        }
        $this->assertSame(0, $this->fakeEvidence->calls);
        $this->assertSame(0, $this->fakeScanner->calls);
    }
}
