<?php

namespace App\Console\Commands;

use App\Proposals\ReviewerAiRecovery;
use Illuminate\Console\Command;
use Throwable;

/** Operator supplies the reviewed NONSECRET storage pins; this command cannot create a campaign. */
final class PruneProposalReviewerAi extends Command
{
    protected $signature = 'proposals:prune-reviewer-ai {--pins= : Absolute local nonsecret pins JSON file} {--sha256= : Approved file SHA256} {--limit=100} {--deactivated : Also erase unexpired content when both AI gates are off}';

    protected $description = 'Erase bounded expired private reviewer AI content while retaining all receipts, ledger and cost evidence';

    public function handle(ReviewerAiRecovery $recovery): int
    {
        try {
            $path = $this->option('pins');
            $digest = $this->option('sha256');
            $limit = $this->option('limit');
            abort_unless(is_string($path) && str_starts_with($path, '/') && is_file($path) && ! is_link($path)
                && filesize($path) <= 8192 && is_string($digest) && preg_match('/^[a-f0-9]{64}$/D', $digest)
                && is_string($limit) && preg_match('/^(?:[1-9]|[1-9][0-9]|100)$/D', $limit), 422);
            $bytes = file_get_contents($path);
            abort_unless(hash_equals($digest, hash('sha256', $bytes)), 409);
            $pins = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
            abort_unless(is_array($pins), 422);
            $result = $recovery->scrub($pins, (int) $limit, $this->option('deactivated') === true);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('No verified cleanup completed. Check the exact owner-runtime pins; retained cost evidence was not reset.');

            return self::FAILURE;
        }
    }
}
