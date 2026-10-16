<?php

namespace App\Console\Commands;

use App\Models\AiBranchAction;
use App\Models\AiBranchConversation;
use App\Models\AiBranchMemory;
use Illuminate\Console\Command;

class AiBranchPrune extends Command
{
    protected $signature = 'ai-branch:prune {--apply : Delete expired chat, memory and reports and redact old action text}';

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

        $reversed = \App\Models\FieldServiceJobNote::withoutGlobalScope('active_ai_note')
            ->whereNotNull('metadata->ai_branch_reversed_at')->where('metadata->ai_branch_reversed_at', '<=', now()->subDays(30)->toIso8601String())
            ->where('body', '!=', '[Reversed AI note text expired]');
        $this->line('Expired reversed-note text: '.$reversed->count());
        if ($this->option('apply')) {
            $reversed->chunkById(100, fn ($notes) => $notes->each(fn ($note) => $note->forceFill(['body' => '[Reversed AI note text expired]'])->save()));
        }
        $reports = app(\App\Services\AiBranch\AiWeeklyReport::class)->prune((bool) $this->option('apply'));
        $this->line('Private reports: '.$reports['expired'].' expired; '.$reports['unreadable'].' unreadable records require review');

        return $this->option('apply') && $reports['unreadable'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
