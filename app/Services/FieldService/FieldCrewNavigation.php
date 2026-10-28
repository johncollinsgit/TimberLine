<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;

/** Explicit navigation only. Model prose and record text never become a route or grant. */
class FieldCrewNavigation
{
    public function resolve(Tenant $tenant, User $user, string $question, ?int $contextJob = null): ?array
    {
        $authority = app(FieldCrewAssistant::class)->authorize($tenant, $user);
        $access = app(FieldServiceAccessService::class);
        $admin = in_array($authority['role'], ['admin', 'owner', 'tenant_owner'], true)
            && $access->canViewCustomerContact($user, $tenant);
        $text = trim(preg_replace('/[^\pL\pN\s-]/u', '', mb_strtolower($question)) ?? '');
        if (! $admin && preg_match('/\b(customers?|clients?|contact details|phone numbers?|email addresses?)\b/u', $text)) {
            return $this->reply('Customer records and contact details are admin-only.');
        }
        if (! preg_match('/^(?:(?:please|can you|could you)\s+)*(?:open|show|take me to|go to|bring up|pull up)\s+(?:me\s+)?(?:the\s+)?(.+)$/u', $text, $match)) {
            return null;
        }
        $target = trim(preg_replace('/\s+please$/u', '', $match[1]) ?? '');
        $pages = ['home' => 'home', 'jobs' => 'work', 'work' => 'work', 'clock' => 'clock', 'time clock' => 'clock',
            'my hours' => 'hours', 'hours' => 'hours', 'messages' => 'messages', 'team messages' => 'messages', 'account' => 'account'];
        if (isset($pages[$target])) {
            return $this->reply('Opening '.$target.'.', ['kind' => 'page', 'page' => $pages[$target], 'label' => ucwords($target)]);
        }
        if (in_array($target, ['customers', 'clients', 'customer contacts'], true)) {
            return $admin ? $this->reply('Opening customers.', ['kind' => 'page', 'page' => 'customers', 'label' => 'Customers'])
                : $this->reply('Customer records and contact details are admin-only.');
        }
        if (in_array($target, ['fleet', 'fleet tracker', 'crew map', 'documents', 'files'], true)) {
            $module = in_array($target, ['documents', 'files'], true) ? 'documents' : 'fleet_tracking';
            $label = $module === 'documents' ? 'Documents' : 'Fleet Tracker';

            return app(TenantModuleAccessResolver::class)->canAccess($tenant->id, $module)
                ? $this->reply('Opening '.$label.'.', ['kind' => 'page', 'page' => $module, 'label' => $label])
                : $this->reply($label.' is unavailable with your current access.');
        }

        $tab = null;
        if (preg_match('/^(materials|tasks|notes|updates|photos)(?:\s+(?:for|on|in)\s+(.+))?$/u', $target, $parts)) {
            $tab = $parts[1] === 'notes' ? 'updates' : $parts[1];
            $target = $parts[2] ?? 'this job';
        }
        $query = $access->scopeVisibleJobs(FieldServiceJob::where('tenant_id', $tenant->id)
            ->notGeneratedQuickBooksInvoice()->whereNull('archived_at'), $user, $tenant);
        if (in_array($target, ['it', 'that', 'this job', 'that job', 'the job'], true)) {
            if (! $contextJob) {
                return $this->reply('Which job would you like to open? Tell me its name.');
            }
            $query->whereKey($contextJob);
        } elseif (preg_match('/^job\s+(\d+)$/u', $target, $id)) {
            $query->whereKey((int) $id[1]);
        } else {
            $target = trim(preg_replace('/^(?:job called\s+|job\s+)|\s+job$/u', '', $target) ?? '');
            $terms = array_values(array_filter(preg_split('/\s+/u', $target) ?: []));
            if ($terms === [] || count($terms) > 12) {
                return $this->reply('Tell me the job name or the page you want to open.');
            }
            foreach ($terms as $term) {
                $query->where('title', 'like', '%'.$term.'%');
            }
        }
        $jobs = $query->orderBy('title')->limit(6)->get(['id', 'title']);
        $sources = $jobs->map(fn ($job) => ['id' => $job->id, 'title' => $job->title, 'ref' => 'job:'.$job->id])->all();
        if ($jobs->count() !== 1) {
            return $this->reply($jobs->isEmpty() ? 'I could not find that job with your current access. Try its job name.'
                : 'Which job? Choose one below or say its full name.', null, $sources);
        }
        $job = $jobs->first();

        return $this->reply('Opening '.$job->title.($tab ? ' · '.$tab : '').'.',
            ['kind' => 'job', 'id' => $job->id, 'label' => $job->title, 'tab' => $tab], $sources);
    }

    private function reply(string $text, ?array $navigation = null, array $sources = []): array
    {
        return ['text' => $text, 'sources' => $sources, 'navigation' => $navigation, 'captured_at' => now()->toIso8601String()];
    }
}
