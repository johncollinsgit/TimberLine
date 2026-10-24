<?php

namespace Tests\Feature\Proposals;

use App\Models\TeamOperationRecord;
use App\Proposals\ReviewerWorkspaceImages;
use App\Proposals\ReviewerWorkspaceImagesScan;
use App\Proposals\ReviewerWorkspaceImagesStore;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ReviewerWorkspaceImagesFixture;

/** Independent source-only adversarial proof. Every scanner/evidence result is FAKE. */
final class IndependentReviewerImagesTest extends ReviewerWorkspaceImagesFixture
{
    public function test_attestation_cannot_be_swapped_between_distinct_uploads_with_identical_bytes(): void
    {
        $reader = $this->reader();
        $bytes = $this->png();
        $one = $this->upload($reader, $bytes, 'one.png');
        $two = $this->upload($this->reader(), $bytes, 'two.png'); // Distinct legitimate browser capability.
        foreach ([$one, $two] as $id) {
            app(ReviewerWorkspaceImagesScan::class)->scan($this->ownerRequest(), $this->version->id, $id, $this->intent($id), false);
        }
        $files = collect(app(ReviewerWorkspaceImages::class)->metadata($reader, $this->token, $this->base())['files'])->keyBy('id');
        $this->assertSame('ready', $files[$one]['state']);
        $this->assertSame('ready', $files[$two]['state']);
        $input = $this->base() + ['upload_id' => $two, 'attestation_id' => $files[$one]['attestation_id'], 'attestation_revision' => $files[$one]['attestation_revision']];
        try {
            app(ReviewerWorkspaceImages::class)->binary($reader, $this->token, $input, 'preview');
            $this->fail('Foreign upload attestation was admitted.');
        } catch (HttpExceptionInterface $failure) {
            $this->assertSame(404, $failure->getStatusCode());
        }
        $this->assertSame(2, $this->fakeScanner->calls);
        $this->assertSame(2, TeamOperationRecord::where('kind', ReviewerWorkspaceImagesStore::KIND)->count());
    }

    public function test_scan_claim_expiring_during_final_fake_evidence_cannot_commit_ready_or_leave_plaintext(): void
    {
        $id = $this->upload($this->reader());
        $original = TeamOperationRecord::findOrFail($id)->getRawOriginal('data');
        $ledger = $this->ledger->getRawOriginal('data');
        $this->fakeEvidence->callback = function (): void {
            if ($this->fakeEvidence->calls === 2) {
                $this->travel(91)->seconds();
            }
        };
        try {
            app(ReviewerWorkspaceImagesScan::class)->scan($this->ownerRequest(), $this->version->id, $id, $this->intent($id), false);
            $this->fail('Expired scan claim became readable.');
        } catch (HttpExceptionInterface $failure) {
            $this->assertSame(410, $failure->getStatusCode());
        }
        $scan = TeamOperationRecord::where('kind', ReviewerWorkspaceImagesStore::KIND)->sole();
        $this->assertSame('scanning', $scan->status);
        $this->assertNull($scan->data['payload']['evidence']);
        $this->assertNull($scan->data['payload']['thumbnail']);
        $this->assertSame($original, TeamOperationRecord::findOrFail($id)->getRawOriginal('data'));
        $this->assertSame($ledger, $this->ledger->fresh()->getRawOriginal('data'));
        $this->assertFalse(collect(Storage::disk('team_private')->allFiles())->contains(fn ($path) => str_contains($path, '/scan-')));
        $this->assertSame(1, $this->fakeScanner->calls);
    }
}
