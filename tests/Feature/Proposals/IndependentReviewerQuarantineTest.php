<?php

namespace Tests\Feature\Proposals;

use App\Models\TeamOperationRecord;
use App\Proposals\ProposalService;
use App\Proposals\ReviewerWorkspace;
use App\Proposals\ReviewerWorkspaceAttachmentBlobs;
use App\Proposals\ReviewerWorkspaceAttachmentsAccess;
use App\Proposals\ReviewerWorkspaceAttachmentsStore;
use App\Proposals\ReviewerWorkspaceFiles;
use App\Proposals\ReviewerWorkspaceFilesController;
use App\Proposals\ReviewerWorkspaceSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ReviewerWorkspaceFixture;

/** Actual private fixture DB/filesystem, fictional image only; never a real upload, scan, hosted or provider acceptance. */
final class IndependentReviewerQuarantineTest extends ReviewerWorkspaceFixture
{
    protected function setUp(): void
    {
        parent::setUp();
        $comments = ['schema_version' => 1, 'comments_enabled' => true, 'uploads_enabled' => false, 'write_personas' => ['demo_manager', 'manager', 'worker', 'requester']];
        $files = ['schema_version' => 1, 'uploads_enabled' => true, 'metadata_read_enabled' => true, 'scan_enabled' => false, 'write_personas' => ['demo_manager', 'manager', 'worker', 'requester']];
        $ch = ReviewerWorkspaceSchema::digest($comments);
        $fh = ReviewerWorkspaceSchema::digest($files);
        config(['service_proposals.reviewer_workspace.collaboration' => ['enabled' => true, 'comments_write_enabled' => false, 'policies' => [$ch => $comments]],
            'service_proposals.reviewer_workspace.attachments' => ['enabled' => true, 'write_enabled' => true, 'policies' => [$fh => $files]]]);
        $draft = app(ProposalService::class)->leesMcRaeDraft($this->tenant, $this->owner->id);
        $payload = $draft->payload;
        $payload['review_workspace'] = $this->version->payload['review_workspace'];
        foreach (['collaboration' => $ch, 'attachments' => $fh] as $kind => $hash) {
            $payload['review_workspace_'.$kind] = ['schema_version' => 1, 'policy_sha256' => $hash, 'expires_at' => now()->addDays(5)->utc()->format('Y-m-d\TH:i:s\Z')];
        }
        $draft->payload = $payload;
        $draft->save();
        $draft->refresh();
        $published = app(ProposalService::class)->publish($this->tenant, $this->owner->id, $draft->id, $draft->revision,
            'FICTIONAL_RECIPIENT_PASSWORD', 7, (string) Str::uuid(), 'reviewer');
        $this->version = $published['version'];
        $this->token = $published['token'];
        $this->initialize();
        Storage::forgetDisk('team_private');
    }

    private function reader(): Request
    {
        $r = $this->recipient();
        $r->session()->put('service_proposal_name.'.$this->version->id, $this->version->payload['reviewer_names'][0]);

        return $r;
    }

    private function base(array $replace = []): array
    {
        return $replace + ['binding' => $this->binding(), 'selection' => $this->selection(), 'target' => ['kind' => 'ticket', 'id' => $this->ids['incoming']]];
    }

    private function png(): string
    {
        // Genuine locally decoded synthetic fixture; never labelled as a clean scan or a real reviewer upload.
        $image = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        unset($image);

        return $bytes;
    }

    private function beginBody(Request $r, string $bytes, array $replace = []): array
    {
        return $this->base($replace + ['request_id' => (string) Str::uuid(), 'expected_target_version' => 1,
            'expected_reviewer_name' => $r->session()->get('service_proposal_name.'.$this->version->id),
            'name' => 'FICTIONAL PHOTO.png', 'size' => strlen($bytes), 'mime' => 'image/png', 'sha256' => hash('sha256', $bytes)]);
    }

    private function upload(Request $r, string $bytes, array $replace = []): array
    {
        $svc = app(ReviewerWorkspaceFiles::class);
        $b = $this->beginBody($r, $bytes, $replace);
        $started = $svc->begin($r, $this->token, $b);
        $id = $started['file']['id'];
        foreach (str_split($bytes, ReviewerWorkspaceAttachmentsStore::CHUNK_BYTES) as $i => $part) {
            $svc->chunk($r, $this->token, $this->base(['upload_id' => $id, 'index' => $i, 'bytes' => base64_encode($part), 'sha256' => hash('sha256', $part)]));
        }

        return [$b, $id];
    }

    private function denied(callable $f, int $status): void
    {
        try {
            $f();
            $this->fail('Unsafe attachment operation admitted');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    private function current(Request $r): array
    {
        $b = $this->base();

        return app(ReviewerWorkspaceAttachmentsAccess::class)->resolve($r, $this->token, $b['binding'], $b['selection'], $b['target']);
    }


    public function test_independent_foreign_target_chunk_and_cancel_preserve_all_ciphertext_and_metadata(): void
    {
        $r=$this->reader(); [$b,$id]=$this->upload($r,$this->png());
        $before=TeamOperationRecord::find($id)->getRawOriginal('data');
        $files=[];foreach(Storage::disk('team_private')->allFiles() as $f){$files[$f]=Storage::disk('team_private')->get($f);}
        foreach(['chunk','cancel'] as $action){
            $q=$this->base(['target'=>['kind'=>'task','id'=>$this->ids['task']],'upload_id'=>$id]);
            if($action==='chunk')$q+=['index'=>0,'sha256'=>hash('sha256',$this->png()),'bytes'=>base64_encode($this->png())];
            $this->denied(fn()=>app(ReviewerWorkspaceFiles::class)->{$action}($r,$this->token,$q),404);
        }
        $this->assertSame($before,TeamOperationRecord::find($id)->getRawOriginal('data'));
        foreach($files as $f=>$bytes)$this->assertSame($bytes,Storage::disk('team_private')->get($f));
        Http::assertNothingSent();
    }

    public function test_independent_part_ciphertext_cannot_be_transplanted_and_retirement_does_not_touch_sibling(): void
    {
        $r=$this->reader();[, $one]=$this->upload($r,$this->png());[, $two]=$this->upload($r,$this->png().'trailing');
        $paths=Storage::disk('team_private')->allFiles();
        $first=collect($paths)->first(fn($f)=>str_contains($f,'/'.$one.'/')&&str_ends_with($f,'/chunk-0'));
        $second=collect($paths)->first(fn($f)=>str_contains($f,'/'.$two.'/')&&str_ends_with($f,'/chunk-0'));
        $cipher=Storage::disk('team_private')->get($second);
        Storage::disk('team_private')->put($second,Storage::disk('team_private')->get($first));
        $this->denied(fn()=>app(ReviewerWorkspaceAttachmentBlobs::class)->read(TeamOperationRecord::find($two),'chunk-0'),503);
        Storage::disk('team_private')->put($second,$cipher);
        $this->assertSame('cancelled',app(ReviewerWorkspaceFiles::class)->cancel($r,$this->token,$this->base(['upload_id'=>$one]))['status']);
        $this->assertSame($cipher,Storage::disk('team_private')->get($second));
        $this->assertSame($this->png().'trailing',app(ReviewerWorkspaceAttachmentBlobs::class)->read(TeamOperationRecord::find($two),'chunk-0'));
        $old=TeamOperationRecord::find($one);
        foreach(['chunk-0','original'] as $part)$this->denied(fn()=>app(ReviewerWorkspaceAttachmentBlobs::class)->put($old,$part,$this->png()),409);
        $this->assertSame($cipher,Storage::disk('team_private')->get($second));
    }

    public function test_independent_actual_version_expiry_cleanup_with_all_feature_flags_off_is_cursor_bounded(): void
    {
        $r=$this->reader();[, $one]=$this->upload($r,$this->png());[, $two]=$this->upload($r,$this->png().'other');
        $graph=TeamOperationRecord::find($this->version->payload['review_workspace']['workspace_id'])->getRawOriginal('data');
        $ledger=$this->ledger->getRawOriginal('data');
        $this->travel(8)->days();
        config(['service_proposals.reviewer_workspace.enabled'=>false,'service_proposals.reviewer_workspace.writes_enabled'=>false,
         'service_proposals.reviewer_workspace.collaboration.enabled'=>false,'service_proposals.reviewer_workspace.attachments.enabled'=>false,
         'service_proposals.reviewer_workspace.attachments.write_enabled'=>false]);
        $owner=$this->request(path:'/owner-workspaces/'.$this->tenant->slug.'/fixture',owner:true);$prune=app(\App\Proposals\ReviewerWorkspaceFilesPruner::class);
        $preview=$prune->prune($owner,limit:1);$this->assertSame(1,$preview['eligible']);$this->assertSame(0,$preview['erased']);
        $this->assertSame(2,TeamOperationRecord::where('kind',ReviewerWorkspaceAttachmentsStore::KIND)->where('status','uploading')->count());
        $a=$prune->prune($owner,limit:1,dryRun:false);$this->assertSame(1,$a['erased']);$this->assertNotNull($a['next_cursor']);
        $b=$prune->prune($owner,limit:1,after:$a['next_cursor'],dryRun:false);$this->assertSame(1,$b['erased']);$this->assertNull($b['next_cursor']);
        $this->assertSame(2,TeamOperationRecord::where('kind',ReviewerWorkspaceAttachmentsStore::KIND)->where('status','expired')->count());
        foreach([$one,$two] as $id){$row=TeamOperationRecord::find($id);$this->assertSame('Expired attachment',$row->data['payload']['name']);$this->assertSame([],$row->data['payload']['chunks']);$this->assertNull($row->data['payload']['claim']);}
        $this->assertTrue(collect(Storage::disk('team_private')->allFiles())->every(fn($f)=>str_ends_with($f,'/.retired')||str_ends_with($f,'/.quota.lock')));
        $this->assertSame($graph,TeamOperationRecord::find($this->version->payload['review_workspace']['workspace_id'])->getRawOriginal('data'));
        $this->assertSame($ledger,$this->ledger->fresh()->getRawOriginal('data'));$this->assertSame(0,DB::table('team_tasks')->count());Http::assertNothingSent();
    }

    public function test_independent_expiry_during_actual_assembly_lease_holds_until_lease_expires(): void
    {
        $this->travelTo(now()->addDays(5)->subSeconds(10));$r=$this->reader();[, $id]=$this->upload($r,'not valid png');
        $this->denied(fn()=>app(ReviewerWorkspaceFiles::class)->complete($r,$this->token,$this->base(['upload_id'=>$id])),422);
        $before=TeamOperationRecord::find($id)->getRawOriginal('data');$this->travel(11)->seconds();
        $owner=$this->request(path:'/owner-workspaces/'.$this->tenant->slug.'/fixture',owner:true);$prune=app(\App\Proposals\ReviewerWorkspaceFilesPruner::class);
        $this->denied(fn()=>$prune->prune($owner,dryRun:false),409);
        $this->assertSame($before,TeamOperationRecord::find($id)->getRawOriginal('data'));
        $this->assertSame('assembling',TeamOperationRecord::find($id)->status);
        $this->travel(80)->seconds();$done=$prune->prune($owner,dryRun:false);$this->assertSame(1,$done['erased']);$this->assertSame(0,$done['failed']);
        $this->assertSame('expired',TeamOperationRecord::find($id)->status);Http::assertNothingSent();
    }
}
