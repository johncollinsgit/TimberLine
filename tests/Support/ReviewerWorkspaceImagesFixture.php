<?php

namespace Tests\Support;

use App\Proposals\ProposalService;
use App\Proposals\ReviewerWorkspaceAttachmentsStore;
use App\Proposals\ReviewerWorkspaceFiles;
use App\Proposals\ReviewerWorkspaceScannerEvidence;
use App\Proposals\ReviewerWorkspaceSchema;
use App\Services\Team\TeamFileScanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** ALL scanner/evidence results here are FAKE. No real daemon, provider or hosted clean-ready acceptance. */
abstract class ReviewerWorkspaceImagesFixture extends ReviewerWorkspaceFixture
{
    protected object $fakeScanner;

    protected object $fakeEvidence;

    protected function setUp(): void
    {
        parent::setUp();
        $scanner = ['schema_version' => 1, 'transport' => 'existing_team_clamd_version_v1', 'host' => '127.0.0.1', 'port' => 3310,
            'file_max_bytes' => ReviewerWorkspaceAttachmentsStore::MAX_BYTES, 'engine_version' => '9.9.9', 'minimum_database_serial' => 123456,
            'maximum_definition_age_seconds' => 172800, 'daemon_timezone' => 'UTC',
            'scanner_source_sha256' => hash_file('sha256', (new \ReflectionClass(TeamFileScanner::class))->getFileName()),
            'evidence_source_sha256' => hash_file('sha256', (new \ReflectionClass(ReviewerWorkspaceScannerEvidence::class))->getFileName())];
        $comments = ['schema_version' => 1, 'comments_enabled' => true, 'uploads_enabled' => false, 'write_personas' => ['demo_manager', 'manager', 'worker', 'requester']];
        $attachments = ['schema_version' => 1, 'uploads_enabled' => true, 'metadata_read_enabled' => true, 'scan_enabled' => false, 'write_personas' => $comments['write_personas']];
        $images = ['schema_version' => 1, 'images_enabled' => true, 'scan_config_sha256' => ReviewerWorkspaceSchema::digest($scanner),
            'max_scan_age_seconds' => 3600, 'thumbnail_width' => 320, 'thumbnail_height' => 240, 'thumbnail_max_bytes' => 262144];
        config(['team_work.scanner.enabled' => true, 'team_work.scanner.host' => '127.0.0.1', 'team_work.scanner.port' => 3310, 'team_work.file_max_bytes' => $scanner['file_max_bytes'],
            'service_proposals.reviewer_workspace.collaboration' => ['enabled' => true, 'comments_write_enabled' => false, 'policies' => [ReviewerWorkspaceSchema::digest($comments) => $comments]],
            'service_proposals.reviewer_workspace.attachments' => ['enabled' => true, 'write_enabled' => true, 'policies' => [ReviewerWorkspaceSchema::digest($attachments) => $attachments]],
            'service_proposals.reviewer_workspace.image_access' => ['enabled' => true, 'scan_enabled' => true, 'policies' => [ReviewerWorkspaceSchema::digest($images) => $images],
                'scanners' => [ReviewerWorkspaceSchema::digest($scanner) => $scanner]]]);
        $draft = app(ProposalService::class)->leesMcRaeDraft($this->tenant, $this->owner->id);
        $payload = $draft->payload;
        $payload['review_workspace'] = $this->version->payload['review_workspace'];
        // All three descriptors share one expiry, even across a wall-clock second.
        $featureExpiresAt = now()->addDays(4)->utc()->format('Y-m-d\TH:i:s\Z');
        foreach (['collaboration' => $comments, 'attachments' => $attachments, 'images' => $images] as $kind => $policy) {
            $payload['review_workspace_'.$kind] = ['schema_version' => 1, 'policy_sha256' => ReviewerWorkspaceSchema::digest($policy), 'expires_at' => $featureExpiresAt];
        }
        $draft->payload = $payload;
        $draft->save();
        $draft->refresh();
        $published = app(ProposalService::class)->publish($this->tenant, $this->owner->id, $draft->id, $draft->revision, 'FICTIONAL_RECIPIENT_PASSWORD', 7, (string) Str::uuid(), 'reviewer');
        $this->version = $published['version'];
        $this->token = $published['token'];
        $this->initialize();
        $this->fakeScanner = new class extends TeamFileScanner
        {
            public int $calls = 0;

            public string $result = 'clean';

            public mixed $callback = null;

            public array $observations = [];

            public function scan(string $path, string $mimeType, string $sha256): string
            {
                $this->calls++;
                $this->observations[] = ['transaction_level' => DB::connection()->transactionLevel(), 'permissions' => fileperms($path) & 0777,
                    'exact_bytes' => hash_file('sha256', $path) === $sha256];
                if ($this->callback) {
                    ($this->callback)();
                }

                return $this->result;
            }
        };
        $this->fakeEvidence = new class extends ReviewerWorkspaceScannerEvidence
        {
            public int $calls = 0;

            public mixed $callback = null;

            public mixed $replies = null;

            public string $defaultReply;

            public function version(array $config): ?string
            {
                $this->calls++;
                if ($this->callback) {
                    ($this->callback)();
                }
                if (is_array($this->replies)) {
                    return array_shift($this->replies);
                }

                return $this->defaultReply;
            }
        };
        $this->fakeEvidence->defaultReply = 'ClamAV 9.9.9/123456/'.now()->utc()->format('D M j H:i:s Y');
        app()->instance(TeamFileScanner::class, $this->fakeScanner);
        app()->instance(ReviewerWorkspaceScannerEvidence::class, $this->fakeEvidence);
    }

    protected function reader(): Request
    {
        $r = $this->recipient();
        $r->session()->put('service_proposal_name.'.$this->version->id, $this->version->payload['reviewer_names'][0]);

        return $r;
    }

    protected function base(): array
    {
        return ['binding' => $this->binding(), 'selection' => $this->selection(), 'target' => ['kind' => 'ticket', 'id' => $this->ids['incoming']]];
    }

    protected function png(int $width = 1, int $height = 1): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        unset($image);

        return $bytes;
    }

    protected function upload(Request $r, ?string $bytes = null, string $name = 'FAKE-SCANNER-FIXTURE.png'): string
    {
        $bytes ??= $this->png();
        $svc = app(ReviewerWorkspaceFiles::class);
        $b = $this->base() + ['request_id' => (string) Str::uuid(), 'expected_target_version' => 1,
            'expected_reviewer_name' => $r->session()->get('service_proposal_name.'.$this->version->id),
            'name' => $name, 'mime' => 'image/png', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        $id = $svc->begin($r, $this->token, $b)['file']['id'];
        foreach (str_split($bytes, 262144) as $i => $chunk) {
            $svc->chunk($r, $this->token, $this->base() + ['upload_id' => $id, 'index' => $i, 'sha256' => hash('sha256', $chunk), 'bytes' => base64_encode($chunk)]);
        }
        $svc->complete($r, $this->token, $this->base() + ['upload_id' => $id]);

        return $id;
    }

    protected function intent(string $uploadId): array
    {
        return ['request_id' => (string) Str::uuid(), 'expected_workspace_revision' => 1, 'expected_target_version' => 1,
            'expected_upload_revision' => \App\Models\TeamOperationRecord::findOrFail($uploadId)->version, 'expected_attestation_revision' => 0];
    }

    protected function ownerRequest(): Request
    {
        return $this->request(path: '/owner-workspaces/'.$this->tenant->slug.'/fixture', owner: true);
    }
}
