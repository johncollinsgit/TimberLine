<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantAiUsageEvent;
use App\Models\User;
use App\Services\AiBranch\AiBranchChat;
use App\Services\AiBranch\AiBranchProvider;
use App\Services\AiBranch\AiFictionalPilot;
use Illuminate\Console\Command;
use Ramsey\Uuid\Uuid;

class AiVerifyFictionalConversation extends Command
{
    protected $signature = 'ai-branch:verify-fictional-conversation {--owner-id=} {--acceptance-id=} {--apply} {--authorization=} {--force-production}';

    protected $description = 'Preview or ask two fixed synthetic pilot questions under the existing approved grant; stable replay IDs, no business actions';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', AiFictionalPilot::SLUG)->firstOrFail();
        $owner = User::whereKey((int) $this->option('owner-id'))->firstOrFail();
        $grant = app(AiFictionalPilot::class)->grant($tenant, $owner);
        abort_unless($grant && data_get($grant->result, 'provider') === 'openai' && data_get($grant->result, 'provider_budget_cents') <= 500, 403, 'An active approved real-model pilot with at most a $5 total cap is required.');
        $id = (string) $this->option('acceptance-id');
        abort_unless(preg_match('/^[a-zA-Z0-9-]{6,80}$/', $id), 422, 'Supply a stable acceptance identifier for safe replay.');
        if (! $this->option('apply')) {
            $this->info('Preview: two fixed synthetic workspace/history questions; at most $0.02 reserved provider cost. No model dispatched.');

            return self::SUCCESS;
        }
        abort_unless(filled($this->option('authorization')) && (! app()->isProduction() || $this->option('force-production')), 422, 'Explicit authorization and production acknowledgement are required.');
        $resolver = request()->getUserResolver();
        request()->setUserResolver(fn () => $owner);
        try {
            $result = app(AiFictionalPilot::class)->during($tenant, $owner, function () use ($tenant, $owner, $id): array {
                $provider = app(AiBranchProvider::class);
                $settings = $provider->settings();
                abort_unless(2 * $provider->cost($settings['max_input'], $settings['max_output'], $settings) <= 20000, 429, 'The bounded acceptance cost requires review.');
                $questions = ['Which workspace is this, what kind of business is it, and which sample job is available? Be brief and cite the job source.',
                    'Which job did you just name? Reply in one sentence.'];
                $uuids = array_map(fn ($turn) => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'everbranch-fictional-acceptance/'.$tenant->id.'/'.$id.'/'.$turn), [1, 2]);
                $first = TenantAiUsageEvent::where('tenant_id', $tenant->id)->where('client_uuid', $uuids[0])->first();
                abort_unless(! $first || $first->model === $settings['model'], 409, 'The earlier acceptance belongs to another model; review it before proceeding.');
                $chat = app(AiBranchChat::class);
                $conversation = $first ? $chat->conversation($tenant, $owner, (int) data_get($first->metadata, 'conversation_id')) : $chat->start($tenant, $owner);
                $replies = [];
                foreach ($questions as $turn => $question) {
                    $replies[] = $chat->ask($tenant, $owner, $conversation->id, $question, $uuids[$turn])['text'];
                }
                $events = TenantAiUsageEvent::where('tenant_id', $tenant->id)->whereIn('client_uuid', $uuids)->orderBy('id')->get();

                return ['tenant' => $tenant->slug, 'conversation_id' => $conversation->id, 'acceptance_id' => $id,
                    'model' => $settings['model'], 'replies' => $replies, 'receipts' => $events->map(fn ($event) => [
                        ...$event->only(['id', 'status', 'provider_request_id', 'provider_cost_micros', 'buyer_charge_micros']),
                        'input_tokens' => data_get($event->metadata, 'input_tokens'), 'output_tokens' => data_get($event->metadata, 'output_tokens'),
                    ])->all(), 'business_actions' => 0];
            });
        } finally {
            request()->setUserResolver($resolver);
        }
        app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenant->id, $owner->id, 'ai_voice.pilot.acceptance',
            confirmation: ['explicit' => true, 'reference' => $this->option('authorization')], result: array_diff_key($result, ['replies' => true]));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
