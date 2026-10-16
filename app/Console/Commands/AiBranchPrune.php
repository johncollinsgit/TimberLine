<?php

namespace App\Console\Commands;

use App\Models\AiBranchAction;
use App\Models\AiBranchConversation;
use App\Models\AiBranchMemory;
use Illuminate\Console\Command;

class AiBranchPrune extends Command
{
    protected $signature = 'ai-branch:prune {--apply : Delete expired chat and memory and redact old action text}';

    protected $description = 'Preview or apply AI Branch retention without removing usage or approval evidence';

    public function handle(): int
    {
        foreach ([AiBranchConversation::class, AiBranchMemory::class] as $model) {
            $query = $model::query()->where('expires_at', '<=', now());
            $this->line(class_basename($model).': '.$query->count().' expired records');
            if ($this->option('apply')) {
                $query->delete();
            }
        }
        $query = AiBranchAction::query()->where('expires_at', '<=', now()->subDays(30));
        $this->line('Action text older than 30 days: '.$query->count().' records');
        if ($this->option('apply')) {
            $query->chunkById(100, function ($actions): void {
                foreach ($actions as $action) {
                    if (! ($action->payload['redacted'] ?? false)) {
                        $action->forceFill(['payload' => ['redacted' => true]])->save();
                    }
                }
            });
        }

        return self::SUCCESS;
    }
}
