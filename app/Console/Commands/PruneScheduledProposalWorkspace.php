<?php

namespace App\Console\Commands;

use App\Proposals\ReviewerWorkspaceScheduledCleanup;
use Illuminate\Console\Command;
use Throwable;

/** Normal Artisan bootstrap precedes handle. This performs no own DB/content work without approved admission. */
final class PruneScheduledProposalWorkspace extends Command
{
    protected $signature = 'proposals:prune-scheduled-workspace';

    protected $description = 'Run the explicitly approved finite owner-workspace graph/comments cleanup policy';

    public function handle(ReviewerWorkspaceScheduledCleanup $cleanup): int
    {
        try {
            $out = $cleanup->run();
            $this->line(json_encode($out, JSON_THROW_ON_ERROR));

            return $out['settled'] === true ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->line('{"status":"unavailable_or_unknown","settled":false}');

            return self::FAILURE;
        }
    }
}
