<?php

use App\Models\Tenant;
use App\Models\User;
use App\Proposals\PdfDocument;
use App\Proposals\ProposalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->pdfOriginalStoragePath = storage_path();
    $this->pdfOriginalCompiledViews = config('view.compiled');
    $this->pdfTestStoragePath = sys_get_temp_dir().'/proposal-pdf-test-'.Str::uuid();
    foreach (['app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
        (new \Illuminate\Filesystem\Filesystem)->makeDirectory($this->pdfTestStoragePath.'/'.$directory, 0700, true);
    }
    $this->app->useStoragePath($this->pdfTestStoragePath);
    config(['view.compiled' => $this->pdfTestStoragePath.'/framework/views']);
    $this->app->forgetInstance('blade.compiler');
    $this->app->make('view.engine.resolver')->forget('blade');
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    $this->tenant = Tenant::create(['name' => 'Independent fictional PDF', 'slug' => 'independent-pdf']);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    config(['service_proposals.enabled' => true, 'service_proposals.writes_enabled' => true, 'service_proposals.publish_enabled' => true,
        'service_proposals.owner_workspaces' => [$this->tenant->id => 'enterprise'], 'service_proposals.recipient_hosts' => ['evergrove.test']]);
    $this->draft = app(ProposalService::class)->leesMcRaeDraft($this->tenant, $this->owner->id);
    $this->url = '/owner-workspaces/'.$this->tenant->slug.'/proposals/'.$this->draft->id.'/pdf';
});

afterEach(function (): void {
    $this->app->useStoragePath($this->pdfOriginalStoragePath);
    config(['view.compiled' => $this->pdfOriginalCompiledViews]);
    $this->app->forgetInstance('blade.compiler');
    $this->app->make('view.engine.resolver')->forget('blade');
    (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->pdfTestStoragePath);
});

test('owner changes during actual PDF rendering refuse the final response', function (string $change): void {
    $ownerId = $this->owner->id;
    app()->instance(PdfDocument::class, new class($ownerId, $change) extends PdfDocument
    {
        public function __construct(private int $ownerId, private string $change) {}

        public function render(array $payload, string $edition, string $savedAt, ?string $contentHash = null): string
        {
            $bytes = parent::render($payload, $edition, $savedAt, $contentHash);
            match ($this->change) {
                'deactivate' => DB::table('users')->where('id', $this->ownerId)->update(['is_active' => false]),
                'unverify' => DB::table('users')->where('id', $this->ownerId)->update(['email_verified_at' => null]),
                'delete' => DB::table('users')->where('id', $this->ownerId)->delete(),
                'membership' => DB::table('tenant_user')->where('user_id', $this->ownerId)->delete(),
            };

            return $bytes;
        }
    });
    $this->actingAs($this->owner)->get($this->url)->assertForbidden()->assertDontSee('%PDF-');
    expect(\Illuminate\Support\Facades\File::directories(storage_path('app/private/proposal-pdf')))->toBe([]);
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with(['deactivate', 'unverify', 'delete', 'membership']);
