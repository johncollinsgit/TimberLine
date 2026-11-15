<?php

namespace App\Http\Middleware;

use App\Models\FieldServiceJob;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\WorkspaceAsset;
use App\Services\Billing\SelfServicePurchaseEligibility;
use App\Services\Onboarding\SelfServiceTrialService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Default-deny trial actions: adding a new route never grants free provider use. */
class EnforceSelfServiceTrial
{
    private const READ = ['workspace.guide', 'self-service.purchase', 'self-service.purchase.status',
        'documents.index', 'documents.preview', 'documents.download',
        'marketing.customers', 'marketing.customers.data', 'marketing.customers.create', 'marketing.customers.show',
        'field-service.index', 'field-service.jobs.data', 'field-service.jobs.show', 'field-service.jobs.updates', 'field-service.calendar',
        'managed-website.index', 'managed-website.editor', 'managed-website.editor.preview', 'managed-website.editor.preview.site',
        'managed-website.media.index', 'managed-website.thumbnails.show', 'managed-website.history.index',
        'account-help.index', 'account-help.show'];

    private const WRITE = ['documents.store', 'documents.links', 'documents.destroy',
        'marketing.customers.store-create', 'marketing.customers.update',
        'field-service.jobs.store', 'field-service.jobs.update', 'field-service.jobs.details.update',
        'managed-website.create', 'managed-website.setup.save', 'managed-website.themes.apply',
        'managed-website.editor.theme.save', 'managed-website.editor.save', 'managed-website.media.store',
        'managed-website.history.page.restore', 'managed-website.history.site.restore',
        'managed-website.pages.store', 'managed-website.pages.update', 'managed-website.pages.reorder', 'managed-website.pages.destroy'];

    public function __construct(private readonly SelfServiceTrialService $trials, private readonly SelfServicePurchaseEligibility $purchases) {}

    public static function allowsReadRoute(string $name): bool
    {
        return in_array($name, self::READ, true);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->attributes->get('current_tenant');
        if (! $tenant instanceof Tenant || ! $this->trials->unpaidTrialForTenant($tenant)) {
            return $next($request);
        }

        return $this->guard($request, $next, $tenant);
    }

    public function guard(Request $request, Closure $next, Tenant $tenant, bool $lockWrites = true): Response
    {
        $user = $request->user();
        abort_unless($user?->is_active && $user->hasVerifiedEmail()
            && $user->tenants()->whereKey($tenant->id)->wherePivot('role', 'owner')->wherePivot('membership_active', true)->exists(), 403);
        $name = (string) $request->route()?->getName();
        if ($name === 'dashboard') {
            return redirect()->route('workspace.guide', ['tenant' => $tenant->slug]);
        }
        // Explicit upgrade is the normal full-priced source checkout, never a trial charge.
        if ($name === 'self-service.purchase.checkout' && $request->isMethod('POST')) {
            return $next($request);
        }
        if ($name === 'self-service.purchase.portal' && $request->isMethod('POST') && $this->purchases->hasVerifiedPurchase($tenant)) {
            return $next($request);
        }
        // Durable help remains available; its service suppresses paid alerts for trials.
        if (in_array($name, ['account-help.store', 'account-help.reply', 'account-help.bud.ask'], true) && $request->isMethod('POST')) {
            return $next($request);
        }
        if (in_array($request->method(), ['GET', 'HEAD'], true)) {
            abort_unless(self::allowsReadRoute($name), 423, 'This feature is available after you activate your plan.');
            abort_if($name === 'marketing.customers.create' && ! $this->trials->activeForTenant($tenant), 423, 'Activate your plan to add customers. Your private work is saved.');

            return $next($request);
        }
        $trial = $this->trials->status($tenant);
        abort_unless($trial['can_edit_private'] && in_array($name, self::WRITE, true), 423, 'Activate your plan to continue. Your private work is saved.');
        foreach (['sync_shopify', 'create_shopify_customer', 'issue_reward_now', 'send_email', 'send_sms', 'notify_customer'] as $field) {
            abort_if($request->boolean($field), 423, 'Connected services require an active plan.');
        }
        if (str_starts_with($name, 'field-service.')) {
            foreach (['assigned_user_id', 'participant_ids', 'participant_user_ids', 'first_task'] as $field) {
                abort_if($request->filled($field), 423, 'Assignments and notifications require an active plan.');
            }
        }

        if (! $lockWrites) {
            return $next($request); // TenantAccess will resolve/authorize and lock the actual context.
        }

        return DB::transaction(function () use ($tenant, $request, $next, $name, $trial): Response {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->trials->activeForTenant($tenant), 423, 'Your trial has ended. Your private work is saved.');
            if ($name === 'documents.store') {
                $files = $request->file('files', []);
                abort_unless(is_array($files), 422, 'Choose files as a list.');
                $count = WorkspaceAsset::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
                abort_if($count + count($files) > $trial['limits']['documents'], 423, 'Your trial file limit is reached.');
            }
            foreach (['marketing.customers.store-create' => [MarketingProfile::class, 'customers'], 'field-service.jobs.store' => [FieldServiceJob::class, 'jobs']] as $route => [$model, $key]) {
                if ($name === $route) {
                    abort_if($model::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count() >= $trial['limits'][$key], 423, 'Your trial '.$key.' limit is reached.');
                }
            }
            if ($name === 'field-service.jobs.store' && $request->boolean('create_customer')) {
                abort_if(MarketingProfile::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count() >= $trial['limits']['customers'], 423, 'Your trial customer limit is reached.');
            }
            $response = $next($request);
            // The routing pipeline can render a controller exception into an
            // error response before it reaches this middleware. Do not commit
            // earlier writes from a rejected multi-step/batch action.
            if ($response->getStatusCode() >= 400) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
            }
            foreach ([MarketingProfile::class => 'customers', FieldServiceJob::class => 'jobs', WorkspaceAsset::class => 'documents'] as $model => $key) {
                abort_if($model::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count() > $trial['limits'][$key], 423, 'Your trial '.$key.' limit is reached.');
            }

            return $response;
        });
    }
}
