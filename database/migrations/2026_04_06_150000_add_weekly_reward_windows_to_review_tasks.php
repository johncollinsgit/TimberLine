<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateTask('google-review', 1);
        $this->updateTask('product-review', 1);
    }

    public function down(): void
    {
        $this->removeWindowRule('google-review', 1);
        $this->removeWindowRule('product-review', 999999);
    }

    protected function updateTask(string $handle, int $maxCompletionsPerCustomer): void
    {
        $task = DB::table('candle_cash_tasks')
            ->where('handle', $handle)
            ->first(['id', 'completion_rule', 'metadata']);

        if (! $task) {
            return;
        }

        $completionRule = $this->decodeJson($task->completion_rule);
        $metadata = $this->decodeJson($task->metadata);

        $completionRule['reward_window_days'] = 7;
        $metadata['reward_window_days'] = 7;
        $metadata['reward_window_label'] = 'first_review_each_week';

        DB::table('candle_cash_tasks')
            ->where('id', $task->id)
            ->update([
                'max_completions_per_customer' => $maxCompletionsPerCustomer,
                'completion_rule' => json_encode($completionRule, JSON_UNESCAPED_SLASHES),
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    protected function removeWindowRule(string $handle, int $maxCompletionsPerCustomer): void
    {
        $task = DB::table('candle_cash_tasks')
            ->where('handle', $handle)
            ->first(['id', 'completion_rule', 'metadata']);

        if (! $task) {
            return;
        }

        $completionRule = $this->decodeJson($task->completion_rule);
        $metadata = $this->decodeJson($task->metadata);

        unset($completionRule['reward_window_days']);
        unset($metadata['reward_window_days'], $metadata['reward_window_label']);

        DB::table('candle_cash_tasks')
            ->where('id', $task->id)
            ->update([
                'max_completions_per_customer' => $maxCompletionsPerCustomer,
                'completion_rule' => json_encode($completionRule, JSON_UNESCAPED_SLASHES),
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string,mixed>
     */
    protected function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
};
