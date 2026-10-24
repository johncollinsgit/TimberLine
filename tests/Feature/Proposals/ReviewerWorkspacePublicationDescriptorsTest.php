<?php

namespace Tests\Feature\Proposals;

use App\Models\TeamOperationRecord;
use App\Proposals\ProposalService;
use App\Proposals\ReviewerWorkspace;
use App\Proposals\ReviewerWorkspaceAttachmentsAccess;
use App\Proposals\ReviewerWorkspaceAttachmentsStore;
use App\Proposals\ReviewerWorkspaceFiles;
use App\Proposals\ReviewerWorkspaceImages;
use App\Proposals\ReviewerWorkspaceImagesAccess;
use App\Proposals\ReviewerWorkspaceImagesScan;
use App\Proposals\ReviewerWorkspaceImagesStore;
use App\Proposals\ReviewerWorkspaceSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ReviewerWorkspaceImagesFixture;

/** Actual isolated fictional publication/unlock; all optional scanner/evidence verdicts are FAKE, never hosted acceptance. */
final class ReviewerWorkspacePublicationDescriptorsTest extends ReviewerWorkspaceImagesFixture
{
    private function descriptors(Request $r, ?array $selection = null, ?array $binding = null): array
    {
        $selection ??= $this->selection();
        $binding ??= $this->binding();

        return [
            app(ReviewerWorkspaceAttachmentsAccess::class)->publicationDescriptor($r, $this->token, $binding, $selection),
            app(ReviewerWorkspaceImagesAccess::class)->publicationDescriptor($r, $this->token, $binding, $selection),
        ];
    }

    private function denied(callable $call, int $status): void
    {
        try {
            $call();
            $this->fail('Unadmitted publication descriptor or target operation accepted');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    private function emptyPublication(): void
    {
        $graph = $this->seed;
        foreach (['projects', 'tasks', 'tickets', 'plans', 'costs'] as $kind) {
            $graph[$kind] = [];
        }
        $this->seed = app(ReviewerWorkspaceSchema::class)->graph($graph, true);
        $payload = $this->version->payload;
        $payload['review_workspace']['seed_sha256'] = ReviewerWorkspaceSchema::digest($this->seed);
        $this->publishPayload($payload);
    }

    private function publishPayload(array $payload): void
    {
        $svc = app(ProposalService::class);
        $draft = $svc->leesMcRaeDraft($this->tenant, $this->owner->id);
        $payload['review_workspace']['workspace_id'] = (string) Str::uuid();
        $draft->payload = $payload;
        $draft->save();
        $draft->refresh();
        $published = $svc->publish($this->tenant, $this->owner->id, $draft->id, $draft->revision,
            'FICTIONAL_RECIPIENT_PASSWORD', 7, (string) Str::uuid(), 'reviewer');
        $this->version = $published['version'];
        $this->token = $published['token'];
        $this->initialize();
    }

    public function test_actual_empty_work_publication_boots_without_target_name_mutation_blob_or_scanner(): void
    {
        $this->emptyPublication();
        $r = $this->recipient(); // No reviewer name is fabricated by boot.
        $before = DB::table('team_operation_records')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $filesBefore = Storage::disk('team_private')->allFiles();
        [$files, $images] = $this->descriptors($r);
        foreach ([$files, $images] as $descriptor) {
            $this->assertSame(['enabled', 'schema_version', 'workspace_id', 'browser_context', 'binding', 'expires_at', 'csrf', 'endpoints'], array_keys($descriptor));
            $this->assertSame($this->version->payload['review_workspace']['workspace_id'], $descriptor['workspace_id']);
            $this->assertSame($this->binding(), $descriptor['binding']);
            $this->assertSame($r->session()->token(), $descriptor['csrf']);
            $this->assertArrayNotHasKey('target', $descriptor);
            $this->assertArrayNotHasKey('can_upload', $descriptor);
            $this->assertArrayNotHasKey('record', $descriptor);
            foreach ($descriptor['endpoints'] as $action => $endpoint) {
                $this->assertStringEndsWith('/'.$action, $endpoint);
                $this->assertStringContainsString('/service-proposals/'.$this->token.'/campus/workspace/', $endpoint);
            }
        }
        $this->assertCount(6, $files['endpoints']);
        $this->assertCount(3, $images['endpoints']);
        $scope = app(ReviewerWorkspaceAttachmentsAccess::class)->publicationContext($r, $this->token, $this->binding(), $this->selection());
        foreach (['projects', 'tasks', 'tickets'] as $kind) {
            $this->assertSame([], $scope['graph'][$kind]);
        }
        $this->assertArrayNotHasKey('target', $scope);
        $this->assertArrayNotHasKey('record', $scope);
        $this->assertArrayNotHasKey('reviewer_name', $scope);
        $this->assertArrayNotHasKey('can_upload', $scope);
        $this->denied(fn () => app(ReviewerWorkspaceFiles::class)->show($r, $this->token, $this->base()), 404);
        $this->denied(fn () => app(ReviewerWorkspaceImages::class)->metadata($r, $this->token, $this->base()), 404);
        $this->denied(fn () => app(ReviewerWorkspaceFiles::class)->show($r, $this->token, [...$this->base(), 'target' => []]), 422);
        $this->assertFalse($r->session()->has('service_proposal_name.'.$this->version->id));
        $this->assertSame($before, DB::table('team_operation_records')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($filesBefore, Storage::disk('team_private')->allFiles());
        $this->assertSame(0, $this->fakeEvidence->calls);
        $this->assertSame(0, $this->fakeScanner->calls);
        Http::assertNothingSent();
    }

    public function test_boot_descriptor_allows_actual_first_ticket_then_only_existing_exact_target_upload_and_fake_clean_read(): void
    {
        $this->emptyPublication();
        $r = $this->reader();
        [$files, $images] = $this->descriptors($r);
        $svc = app(ReviewerWorkspace::class);
        $result = $svc->command($r, $this->token, $this->command(['action' => 'ticket.create', 'record_id' => $this->ids['incoming'], 'expected_record_version' => 0,
            'payload' => ['area_id' => $this->ids['a'], 'title' => 'First fictional ticket', 'description' => '', 'priority' => 'normal', 'due_on' => null,
                'building_id' => $this->ids['building'], 'building_version' => 1, 'type' => 'general']]));
        $this->assertSame('applied', $result['status']);
        $this->assertSame($files['workspace_id'], $this->version->payload['review_workspace']['workspace_id']);
        $this->assertSame($images['binding'], $this->binding());
        $id = $this->upload($r);
        $this->assertSame('quarantined', TeamOperationRecord::find($id)->status);
        $held = app(ReviewerWorkspaceImages::class)->metadata($r, $this->token, $this->base());
        $this->assertSame('held', $held['files'][0]['state']);
        $this->assertSame(0, $this->fakeScanner->calls);
        $intent = [...$this->intent($id), 'expected_workspace_revision' => 2];
        app(ReviewerWorkspaceImagesScan::class)->scan($this->ownerRequest(), $this->version->id, $id, $intent, false);
        $file = app(ReviewerWorkspaceImages::class)->metadata($r, $this->token, $this->base())['files'][0];
        $binary = app(ReviewerWorkspaceImages::class)->binary($r, $this->token, $this->base() + ['upload_id' => $id,
            'attestation_id' => $file['attestation_id'], 'attestation_revision' => $file['attestation_revision']], 'preview');
        $this->assertSame($this->png(), $binary['bytes']); // Synthetic clean/evidence fixture only.
        $this->assertSame('quarantined', TeamOperationRecord::find($id)->status);
        $this->assertSame(0, DB::table('team_tasks')->count());
    }

    public function test_descriptors_never_replace_actual_password_unlock_version_binding_or_current_silo_authority(): void
    {
        $this->denied(fn () => $this->descriptors($this->request()), 403);
        $r = $this->reader();
        $this->denied(fn () => $this->descriptors($r, binding: [...$this->binding(), 'version_hash' => str_repeat('f', 64)]), 409);
        config(['service_proposals.owner_silo_id' => 'OTHER-SILO']);
        $this->denied(fn () => $this->descriptors($r), 503);
        $this->assertSame(0, $this->fakeEvidence->calls);
        $this->assertSame(0, $this->fakeScanner->calls);
    }

    public function test_disabled_publication_descriptors_fail_before_sql_and_no_scanner_or_blob_mutation(): void
    {
        $r = $this->reader();
        foreach ([ReviewerWorkspaceAttachmentsAccess::class => 'attachments', ReviewerWorkspaceImagesAccess::class => 'image_access'] as $class => $flag) {
            config(['service_proposals.reviewer_workspace.'.$flag.'.enabled' => false]);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->denied(fn () => app($class)->publicationDescriptor($r, $this->token, $this->binding(), $this->selection()), 404);
            $this->assertSame([], DB::getQueryLog());
            DB::disableQueryLog();
            config(['service_proposals.reviewer_workspace.'.$flag.'.enabled' => true]);
        }
        $this->assertSame([], Storage::disk('team_private')->allFiles());
        $this->assertSame(0, $this->fakeScanner->calls);
    }

    public function test_invalid_independent_policy_cannot_fall_back_to_comments_or_images_permissions(): void
    {
        $r = $this->reader();
        foreach (['collaboration' => 'comments_enabled', 'attachments' => 'scan_enabled', 'images' => 'images_enabled'] as $kind => $field) {
            $hash = $this->version->payload['review_workspace_'.$kind]['policy_sha256'];
            $config = $kind === 'images' ? 'image_access' : $kind;
            $key = 'service_proposals.reviewer_workspace.'.$config.'.policies.'.$hash.'.'.$field;
            $old = config($key);
            config([$key => ! $old]);
            $this->denied(fn () => $this->descriptors($r), 503);
            config([$key => $old]);
        }
        $this->assertSame(0, $this->fakeEvidence->calls);
    }

    public function test_revoked_and_expired_real_edition_cannot_advertise_endpoints(): void
    {
        $r = $this->reader();
        $this->version->revoked_at = now();
        $this->version->save();
        $this->denied(fn () => $this->descriptors($r), 410);
        $this->version->revoked_at = null;
        $this->version->save();
        $this->travel(5)->days();
        $this->denied(fn () => $this->descriptors($this->reader()), 410);
    }

    public function test_readonly_boot_cannot_upload_and_hidden_target_is_still_rejected_without_changing_quarantine(): void
    {
        $r = $this->reader();
        $executive = $this->selection('executive');
        $this->assertCount(2, $this->descriptors($r, $executive));
        $body = [...$this->base(), 'selection' => $executive, 'request_id' => (string) Str::uuid(), 'expected_target_version' => 1,
            'expected_reviewer_name' => $r->session()->get('service_proposal_name.'.$this->version->id), 'name' => 'FICTIONAL.png',
            'mime' => 'image/png', 'size' => strlen($this->png()), 'sha256' => hash('sha256', $this->png())];
        $this->denied(fn () => app(ReviewerWorkspaceFiles::class)->begin($r, $this->token, $body), 403);
        $foreign = [...$this->base(), 'target' => ['kind' => 'project', 'id' => $this->ids['other_project']]];
        $this->denied(fn () => app(ReviewerWorkspaceFiles::class)->show($r, $this->token, $foreign), 404);
        $this->denied(fn () => app(ReviewerWorkspaceImages::class)->metadata($r, $this->token, $foreign), 404);
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceAttachmentsStore::KIND)->count());
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceImagesStore::KIND)->count());
    }

    public function test_existing_target_descriptor_and_new_publication_descriptor_have_identical_closed_shape(): void
    {
        $r = $this->reader();
        [$files, $images] = $this->descriptors($r);
        $access = app(ReviewerWorkspaceAttachmentsAccess::class);
        $this->assertSame($files, $access->clientDescriptor($r, $this->token, $this->binding(), $this->selection(), $this->base()['target']));
        $imagesAccess = app(ReviewerWorkspaceImagesAccess::class);
        $current = $imagesAccess->recipient($r, $this->token, $this->binding(), $this->selection(), $this->base()['target']);
        $this->assertSame($images, $imagesAccess->clientDescriptor($current, $r, $this->token, $this->binding()));
    }

    public function test_last_fresh_publication_read_cannot_hide_newly_disabled_image_admission(): void
    {
        $r = $this->reader();
        $workspace = $this->version->payload['review_workspace']['workspace_id'];
        $seen = 0;
        DB::listen(function ($query) use ($workspace, &$seen) {
            if (str_contains($query->sql, 'team_operation_records') && str_starts_with(strtolower($query->sql), 'select')
                && in_array($workspace, $query->bindings, true) && ++$seen === 2) {
                config(['service_proposals.reviewer_workspace.image_access.enabled' => false]);
            }
        });
        $this->denied(fn () => app(ReviewerWorkspaceImagesAccess::class)->publicationDescriptor($r, $this->token, $this->binding(), $this->selection()), 404);
        $this->assertSame(2, $seen);
        $this->assertSame(0, $this->fakeEvidence->calls);
        $this->assertSame(0, $this->fakeScanner->calls);
    }

    public function test_absent_malformed_independent_descriptors_and_expiry_hierarchy_never_gain_boot_fallback(): void
    {
        $r = $this->reader();
        $original = $this->version->payload;
        foreach (['collaboration', 'attachments', 'images'] as $kind) {
            $payload = $original;
            unset($payload['review_workspace_'.$kind]);
            $this->publishPayload($payload);
            $r = $this->reader();
            $this->denied(fn () => $this->descriptors($r), 404);
            $payload = $original;
            $payload['review_workspace_'.$kind]['schema_version'] = 2;
            $this->publishPayload($payload);
            $r = $this->reader();
            $this->denied(fn () => $this->descriptors($r), 503);
            $payload = $original;
            $payload['review_workspace_'.$kind]['extra'] = 'UNADMITTED';
            $this->publishPayload($payload);
            $r = $this->reader();
            $this->denied(fn () => $this->descriptors($r), 422);
        }
        foreach (['collaboration' => 7, 'attachments' => 5, 'images' => 5] as $kind => $days) {
            $payload = $original;
            $payload['review_workspace_'.$kind]['expires_at'] = now()->addDays($days)->utc()->format('Y-m-d\TH:i:s\Z');
            $this->publishPayload($payload);
            $r = $this->reader();
            $this->denied(fn () => $this->descriptors($r), $kind === 'images' ? 410 : 503);
        }
        $this->assertSame(0, $this->fakeEvidence->calls);
        $this->assertSame(0, $this->fakeScanner->calls);
    }

    public function test_exact_source_build_and_current_persona_are_not_advertised_from_stale_or_native_context(): void
    {
        $r = $this->reader();
        foreach (['source_hash' => str_repeat('e', 64), 'build_id' => 'OBSOLETE-BUILD'] as $key => $value) {
            $this->denied(fn () => $this->descriptors($r, binding: [...$this->binding(), $key => $value]), 409);
        }
        $this->denied(fn () => $this->descriptors($r, [...$this->selection(), 'persona' => 'native_owner']), 422);
        $this->assertSame(0, $this->fakeScanner->calls);
    }

    public function test_last_fresh_attachment_read_rechecks_own_and_collaboration_gates_without_target_fallback(): void
    {
        $r = $this->reader();
        $workspace = $this->version->payload['review_workspace']['workspace_id'];
        foreach (['attachments', 'collaboration'] as $flag) {
            $counter = (object) ['seen' => 0];
            DB::listen(function ($query) use ($workspace, $counter, $flag) {
                if (str_contains($query->sql, 'team_operation_records') && str_starts_with(strtolower($query->sql), 'select')
                    && in_array($workspace, $query->bindings, true) && ++$counter->seen === 2) {
                    config(['service_proposals.reviewer_workspace.'.$flag.'.enabled' => false]);
                }
            });
            $this->denied(fn () => app(ReviewerWorkspaceAttachmentsAccess::class)->publicationDescriptor($r, $this->token, $this->binding(), $this->selection()), 404);
            $this->assertSame(2, $counter->seen);
            config(['service_proposals.reviewer_workspace.'.$flag.'.enabled' => true]);
        }
        $this->assertSame(0, $this->fakeEvidence->calls);
        $this->assertSame(0, $this->fakeScanner->calls);
    }
}
