<?php

namespace App\Console\Commands;

use App\Models\SubscriptionBillingAgreement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionAmendmentProposal;
use App\Services\Subscriptions\SubscriptionBillingAmendmentService;
use App\Services\Subscriptions\SubscriptionReplacementAccess;
use Illuminate\Console\Command;

class SubscriptionAmendmentCommand extends Command
{
    protected $signature = 'subscriptions:amend {--tenant=} {--actor=} {--agreement=} {--file=} {--reviewed-hash=} {--revision=} {--request-id=} {--apply : Accept separately reviewed future terms without collecting or changing past orders}';

    protected $description = 'Preview or accept a reviewed Braintree delivery amendment while retaining original payment authorization.';

    public function handle(SubscriptionBillingAmendmentService $service): int
    {
        try {
            $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->firstOrFail();
            $actor = User::findOrFail((int) $this->option('actor'));
            $row = SubscriptionBillingAgreement::query()->forTenantId($tenant->id)->findOrFail((int) $this->option('agreement'));
            app(SubscriptionReplacementAccess::class)->owner($tenant->id, $actor, $row->member_id, 'servicing');
            $path = (string) $this->option('file');
            abort_unless(str_starts_with($path, '/') && ! is_link($path) && is_file($path) && (fileperms($path) & 0777) === 0600
                && filesize($path) <= 65536, 422, 'Use a private regular 0600 JSON file at most 64 KiB.');
            $input = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            abort_unless(is_array($input) && is_array($input['change'] ?? null) && is_array($input['approval'] ?? null), 422, 'Provide the exact delivery change and its separate approval review.');
            if ($this->option('apply')) {
                $action = $service->apply($tenant->id, $actor, $row->id, $input['change'], $input['approval'],
                    (string) $this->option('reviewed-hash'), (string) $this->option('request-id'), (int) $this->option('revision'));
                $result = ['status' => 'future_terms_saved', 'action_id' => $action->id, 'accepted_revision' => $action->after_state['revision'],
                    'effective_from' => $action->after_state['effective_from'], 'amount_cents' => $action->after_state['terms']['amount_cents']];
                $result['effective_after_resume'] = $action->after_state['effective_after_resume'];
            } else {
                $proposal = app(SubscriptionAmendmentProposal::class)->review($row, $input['change']);
                $result = ['status' => 'offline_proposal', 'subject' => $proposal['subject'], 'quote_required' => true];
            }
            $this->line(json_encode($result + ['charges_customer' => false, 'creates_order' => false, 'original_authorization_replaced' => false], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $error->getMessage()
                : 'The reviewed delivery amendment needs attention. No charge or past order change was attempted.');

            return self::FAILURE;
        }
    }
}
