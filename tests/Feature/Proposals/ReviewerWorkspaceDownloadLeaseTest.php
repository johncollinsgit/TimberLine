<?php

namespace Tests\Feature\Proposals;

use App\Models\TeamOperationRecord;
use App\Proposals\ReviewerWorkspaceFiles;
use App\Proposals\ReviewerWorkspaceFilesPruner;
use App\Proposals\ReviewerWorkspaceImagesScan;
use App\Proposals\ReviewerWorkspaceImagesStore;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ReviewerWorkspaceImagesFixture;

/** Real isolated SQLite/private image bytes, fake scanner. Shared lease hook must preserve existing image authority. */
final class ReviewerWorkspaceDownloadLeaseTest extends ReviewerWorkspaceImagesFixture
{
    public function test_existing_image_child_lease_blocks_original_cancel_and_expiry_erasure_while_new_pdf_flags_are_off(): void
    {
        config(['service_proposals.reviewer_workspace.downloads.enabled' => false, 'service_proposals.reviewer_workspace.downloads.scan_enabled' => false]);
        $r = $this->reader();
        $id = $this->upload($r);
        $blocked = false;
        $this->fakeScanner->callback = function () use ($r, $id, &$blocked) {
            try {
                app(ReviewerWorkspaceFiles::class)->cancel($r, $this->token, $this->base() + ['upload_id' => $id]);
                $this->fail('Live image claim erased');
            } catch (HttpExceptionInterface $e) {
                $this->assertSame(409, $e->getStatusCode());
                $blocked = true;
            }
            $this->version->revoked_at = now();
            $this->version->save();
            $this->assertSame(0, app(ReviewerWorkspaceFilesPruner::class)->prune($this->ownerRequest(), dryRun: false)['eligible']);
        };
        try {
            app(ReviewerWorkspaceImagesScan::class)->scan($this->ownerRequest(), $this->version->id, $id, $this->intent($id), false);
            $this->fail('Late revoked image accepted');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(410, $e->getStatusCode());
        }
        $this->assertTrue($blocked);
        $this->assertSame('quarantined', TeamOperationRecord::find($id)->status);
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceImagesStore::KIND)->where('status', 'clean')->count());
    }
}
