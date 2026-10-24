<?php

namespace Tests\Feature\Proposals;

use App\Proposals\ReviewerWorkspaceScheduledAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReviewerWorkspaceFixture;

/** Only refusal paths on SQLite. No fake principal, fake advisory lock or scheduled erasure acceptance. */
final class ReviewerWorkspaceScheduledCleanupRefusalTest extends ReviewerWorkspaceFixture
{
    public function test_default_off_and_malformed_digest_refuse_before_own_database_work_and_command_output_is_sanitized(): void
    {
        $before = DB::table('team_operation_records')->orderBy('id')->get()->toJson();
        foreach ([['enabled' => false, 'pins_sha256' => null], ['enabled' => true, 'pins_sha256' => []]] as $gate) {
            config(['service_proposals.reviewer_workspace.scheduled_cleanup' => $gate]);
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                ReviewerWorkspaceScheduledAuthority::admit();
                $this->fail('Unapproved schedule admitted.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertContains($e->getStatusCode(), [404, 503]);
            }
            $this->assertSame([], DB::getQueryLog());
            DB::disableQueryLog();
            $kernel = app(Kernel::class);
            $this->assertSame(1, $kernel->call('proposals:prune-scheduled-workspace'));
            $this->assertSame('{"status":"unavailable_or_unknown","settled":false}', trim($kernel->output()));
        }
        $this->assertSame($before, DB::table('team_operation_records')->orderBy('id')->get()->toJson());
    }
}
