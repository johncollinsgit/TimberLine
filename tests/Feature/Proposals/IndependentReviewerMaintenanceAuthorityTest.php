<?php

namespace Tests\Feature\Proposals;

use App\Enterprise\RecordStore;
use App\Models\Tenant;
use App\Proposals\ReviewerWorkspace;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ReviewerWorkspaceFixture;

final class IndependentReviewerMaintenanceAuthorityTest extends ReviewerWorkspaceFixture
{
    public function test_membership_revoked_after_admission_before_tenant_lock_denies_erasure(): void
    {
        $this->initialize();
        $this->version->revoked_at = now();
        $this->version->save();
        $owner = $this->request(path: '/owner-workspaces/'.$this->tenant->slug.'/fixture', owner: true);
        $before = DB::table('team_operation_records')->orderBy('id')->get()->toJson();
        $this->app->instance(RecordStore::class, new class extends RecordStore
        {
            public function locked(Tenant $tenant, callable $callback): mixed
            {
                DB::table('tenant_user')->where('tenant_id', $tenant->id)->update(['membership_active' => false]);

                return parent::locked($tenant, $callback);
            }
        });
        $status = null;
        try {
            app(ReviewerWorkspace::class)->pruneExpired($owner, dryRun: false);
        } catch (HttpExceptionInterface $error) {
            $status = $error->getStatusCode();
        }
        $this->assertSame(403, $status, 'Revoked real-owner membership must be checked again inside the tenant critical section.');
        $this->assertSame($before, DB::table('team_operation_records')->orderBy('id')->get()->toJson());
    }
}
