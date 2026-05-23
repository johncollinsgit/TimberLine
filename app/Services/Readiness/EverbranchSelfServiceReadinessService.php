<?php

namespace App\Services\Readiness;

use App\Models\CustomModuleRequest;
use App\Models\ShopifyPrivacyWebhookEvent;
use App\Models\TenantSetupStatus;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class EverbranchSelfServiceReadinessService
{
    /**
     * @return array{overall:array<string,mixed>,summary:array<string,int>,sections:array<int,array<string,mixed>>}
     */
    public function evaluate(): array
    {
        $sections = [
            $this->tenantOnboarding(),
            $this->intakeQueue(),
            $this->moduleAppStore(),
            $this->customModuleRequests(),
            $this->commercialIntent(),
            $this->billing(),
            $this->shopifyApp(),
            $this->privacyWebhooks(),
            $this->shopifyExternalEvidence(),
            $this->mobile(),
        ];

        $overallBlockers = [
            'Shopify Partner Dashboard, CLI deploy/release, dev-store install/reinstall, app proxy, and live privacy webhook evidence remain pending.',
            'Shopify scope reduction/justification and app name/handle decision remain pending.',
            'Billing and checkout remain disabled by design and require a future activation PR.',
            'Generic Everbranch Android/iOS mobile app work has not started.',
        ];

        $sections[] = [
            'key' => 'launch_readiness',
            'title' => 'Launch Readiness Summary',
            'status' => 'blocked',
            'explanation' => 'Everbranch is not ready for public self-service launch yet. Readiness surfaces exist, but external Shopify evidence, scope/branding decisions, billing activation policy, and launch smoke evidence are still incomplete.',
            'blockers' => $overallBlockers,
            'next_action' => 'Capture Shopify external evidence and approve scope/branding decisions before considering launch or billing activation.',
            'href' => null,
            'doc' => 'docs/operations/everbranch-master-readiness-plan.md',
        ];

        return [
            'overall' => [
                'status' => 'blocked',
                'answer' => 'No. Everbranch cannot safely onboard fully self-service tenants today.',
                'explanation' => 'The platform has strong alpha readiness surfaces, but launch remains blocked until external Shopify evidence, scope/branding decisions, billing lane activation evidence, and launch smoke checks are complete.',
                'next_action' => 'Finish Shopify external evidence capture, then decide scope/branding before any billing or public onboarding activation.',
                'blockers' => $overallBlockers,
            ],
            'summary' => $this->summary($sections),
            'sections' => $sections,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sections
     * @return array<string,int>
     */
    protected function summary(array $sections): array
    {
        $summary = [
            'ready' => 0,
            'partial' => 0,
            'blocked' => 0,
            'pending_external' => 0,
            'disabled' => 0,
            'not_started' => 0,
        ];

        foreach ($sections as $section) {
            $status = (string) ($section['status'] ?? 'blocked');
            $summary[$status] = (int) ($summary[$status] ?? 0) + 1;
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    protected function tenantOnboarding(): array
    {
        return [
            'key' => 'tenant_onboarding',
            'title' => 'Tenant Onboarding Readiness',
            'status' => Schema::hasTable('tenant_setup_statuses') && Route::has('app.start') ? 'partial' : 'blocked',
            'explanation' => 'Tenant setup status and `/start` guidance exist, including import path, module interest, mobile interest, and commercial intent capture.',
            'blockers' => [
                'Full self-service account-to-tenant creation is not complete.',
                'Square, CSV, and manual import setup remain coordination/planning signals rather than automated flows.',
            ],
            'next_action' => 'Keep setup status as guided intake until tenant creation and import execution have separate readiness evidence.',
            'href' => null,
            'doc' => 'docs/operations/everbranch-client-intake-readiness.md',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function intakeQueue(): array
    {
        return [
            'key' => 'intake_queue',
            'title' => 'Intake Queue Readiness',
            'status' => Route::has('landlord.onboarding.intake') ? 'ready' : 'blocked',
            'explanation' => 'Landlord intake queue can triage setup statuses by review, Shopify connection, import path, mobile interest, and next action.',
            'blockers' => [],
            'next_action' => 'Use the queue for operator review; do not treat it as connector automation.',
            'href' => Route::has('landlord.onboarding.intake') ? route('landlord.onboarding.intake') : null,
            'doc' => 'docs/operations/everbranch-client-intake-readiness.md',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function moduleAppStore(): array
    {
        $visibleModules = collect((array) config('module_catalog.modules', []))
            ->filter(static function (mixed $definition): bool {
                if (! is_array($definition)) {
                    return false;
                }

                return (bool) data_get($definition, 'visibility.app_store', false)
                    && strtoupper((string) ($definition['market_state'] ?? '')) === 'SAFE_TO_MARKET'
                    && in_array(strtolower((string) ($definition['status'] ?? '')), ['live', 'beta'], true);
            })
            ->count();

        return [
            'key' => 'module_app_store',
            'title' => 'Module App Store Readiness',
            'status' => $visibleModules > 0 ? 'partial' : 'blocked',
            'explanation' => 'Module catalog metadata, safe tenant filtering, and display-only module cards exist.',
            'blockers' => [
                'Module pricing labels are display-only.',
                'Module installs and access changes are not part of self-service readiness.',
            ],
            'next_action' => 'Keep fail-closed visibility and add no new modules until framework evidence is stable.',
            'href' => Route::has('marketing.modules') ? route('marketing.modules') : null,
            'doc' => 'docs/operations/everbranch-module-app-store-readiness.md',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function customModuleRequests(): array
    {
        return [
            'key' => 'custom_module_requests',
            'title' => 'Custom Module Request Readiness',
            'status' => Schema::hasTable('custom_module_requests') && Route::has('landlord.custom-module-requests.index') ? 'ready' : 'blocked',
            'explanation' => 'Tenants can submit custom module requests and landlords can triage them as intake records.',
            'blockers' => [
                'Requests do not create modules, quotes, invoices, billing, or access changes.',
            ],
            'next_action' => 'Use custom requests for discovery and keep conversion to reusable modules manual.',
            'href' => Route::has('landlord.custom-module-requests.index') ? route('landlord.custom-module-requests.index') : null,
            'doc' => 'docs/operations/everbranch-custom-module-request-readiness.md',
            'metric' => Schema::hasTable('custom_module_requests') ? CustomModuleRequest::query()->count() : 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function commercialIntent(): array
    {
        return [
            'key' => 'commercial_intent',
            'title' => 'Commercial Intent Readiness',
            'status' => Schema::hasTable('tenant_setup_statuses') && Route::has('landlord.commercial-intent.index') ? 'partial' : 'blocked',
            'explanation' => 'Plan interest, billing lane interest, implementation help, commercial notes, and landlord commercial review exist as intent only.',
            'blockers' => [
                'Plan selection does not activate paid plans.',
                'Billing lane decisions do not create checkout, charges, subscriptions, invoices, or access changes.',
            ],
            'next_action' => 'Use the commercial gate for operator follow-up before any future billing activation PR.',
            'href' => Route::has('landlord.commercial-intent.index') ? route('landlord.commercial-intent.index') : null,
            'doc' => 'docs/operations/everbranch-billing-readiness-audit.md',
            'metric' => Schema::hasTable('tenant_setup_statuses')
                ? TenantSetupStatus::query()->where(function ($query): void {
                    $query->where('plan_interest', '!=', 'undecided')
                        ->orWhere('billing_lane_interest', '!=', 'undecided')
                        ->orWhere('implementation_help_interest', true);
                })->count()
                : 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function billing(): array
    {
        $checkoutActive = (bool) config('commercial.billing_readiness.checkout_active', false);
        $lifecycleActive = (bool) config('commercial.billing_readiness.lifecycle_mutations_enabled', false);

        return [
            'key' => 'billing',
            'title' => 'Billing Readiness',
            'status' => (! $checkoutActive && ! $lifecycleActive) ? 'disabled' : 'blocked',
            'explanation' => 'Billing and checkout remain intentionally disabled. Stripe direct billing and Shopify Billing are future lanes.',
            'blockers' => [
                'Shopify App Store merchant billing requires future Shopify Billing/App Pricing work.',
                'Stripe direct billing requires a future direct/custom/non-Shopify activation PR.',
            ],
            'next_action' => 'Keep checkout disabled until provider lane, support, tax/refund, and launch evidence are approved.',
            'href' => Route::has('landlord.commercial-intent.index') ? route('landlord.commercial-intent.index') : null,
            'doc' => 'docs/operations/everbranch-billing-readiness-audit.md',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function shopifyApp(): array
    {
        return [
            'key' => 'shopify_app',
            'title' => 'Shopify App Readiness',
            'status' => 'partial',
            'explanation' => 'Canonical TOML URLs, OAuth callback host, embedded app surfaces, app proxy expectation, and privacy webhook routes exist locally.',
            'blockers' => [
                'Partner Dashboard values are not externally verified.',
                'Dev-store install/reinstall and app proxy evidence remain pending.',
                'Scope review and app name/handle decision remain pending.',
            ],
            'next_action' => 'Execute the Shopify evidence packet and decision record before App Store submission.',
            'href' => null,
            'doc' => 'docs/operations/everbranch-shopify-readiness-audit.md',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function privacyWebhooks(): array
    {
        $routesReady = Route::has('shopify.webhooks.customers.data-request')
            && Route::has('shopify.webhooks.customers.redact')
            && Route::has('shopify.webhooks.shop.redact');
        $eventCount = Schema::hasTable('shopify_privacy_webhook_events')
            ? ShopifyPrivacyWebhookEvent::query()->count()
            : 0;

        return [
            'key' => 'privacy_webhooks',
            'title' => 'Privacy Webhook Readiness',
            'status' => $routesReady ? 'partial' : 'blocked',
            'explanation' => 'Privacy webhook endpoints and HMAC verification exist, and valid events are recorded for manual review.',
            'blockers' => [
                'Live Shopify delivery evidence remains pending.',
                'Automated deletion/anonymization policy is not implemented.',
            ],
            'next_action' => 'Trigger privacy webhooks from Shopify tooling and capture `shopify_privacy_webhook_events` evidence rows.',
            'href' => null,
            'doc' => 'docs/operations/shopify-partner-dashboard-evidence-runbook.md',
            'metric' => $eventCount,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function shopifyExternalEvidence(): array
    {
        $packetPath = 'docs/operations/evidence/shopify/2026-05-21/README.md';
        $decisionPath = 'docs/operations/shopify-scope-branding-decision-record.md';
        $packet = $this->readDoc($packetPath);
        $decision = $this->readDoc($decisionPath);
        $complete = str_contains($packet, 'External verification complete')
            && ! str_contains($packet, 'Pending external verification')
            && ! str_contains($decision, 'Decision: pending');

        return [
            'key' => 'shopify_external_evidence',
            'title' => 'Shopify External Evidence Readiness',
            'status' => $complete ? 'ready' : 'pending_external',
            'explanation' => 'A dated evidence packet and scope/branding decision record exist, but external verification is not complete.',
            'blockers' => $complete ? [] : [
                'Partner Dashboard screenshots are pending.',
                'Shopify CLI app deploy/release evidence is pending.',
                'Dev-store install/reinstall evidence is pending.',
                'App proxy proof is pending.',
                'Live privacy webhook delivery evidence remains pending.',
                'Scope and app name/handle decisions are pending.',
            ],
            'next_action' => 'Run the PR 12 evidence runbook with authenticated Shopify access, store artifacts in the dated packet, and resolve docs/operations/shopify-scope-branding-decision-record.md.',
            'href' => null,
            'doc' => $packetPath,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function mobile(): array
    {
        return [
            'key' => 'mobile',
            'title' => 'Mobile Readiness',
            'status' => 'not_started',
            'explanation' => 'Modern Forestry mobile catalog support is tenant-specific. A generic Everbranch Android/iOS app and API are not built.',
            'blockers' => [
                'No generic Everbranch mobile app exists.',
                'No generic mobile API should be inferred from Modern Forestry catalog routes.',
            ],
            'next_action' => 'Keep mobile as planning/intent until a separate companion app and access-checked API design is approved.',
            'href' => null,
            'doc' => 'docs/operations/everbranch-app-surface-inventory.md',
        ];
    }

    protected function readDoc(string $relativePath): string
    {
        $path = base_path($relativePath);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
