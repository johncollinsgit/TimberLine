<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\CollinsClientAccessService;
use App\Services\FieldService\FieldServiceAccessService;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

/** Metadata and authorized destinations only. Never searches stored setting values. */
class WorkspaceSettingsService
{
    public function authorize(Tenant $tenant, User $user): void
    {
        abort_unless($user->is_active !== false && $user->email_verified_at
            && $user->tenants()->whereKey($tenant->id)->wherePivot('membership_active', true)->exists(), 403);
    }

    public function canManage(Tenant $tenant, User $user): bool
    {
        return app(TenantFinancialAccess::class)->allows($user, $tenant);
    }

    public function categories(): array
    {
        return ['account' => 'My account', 'organization' => 'Organization', 'team' => 'Team & permissions',
            'billing' => 'Billing & plan', 'integrations' => 'Integrations', 'branches' => 'Branches'];
    }

    public function catalog(Tenant $tenant, User $user): array
    {
        $this->authorize($tenant, $user);
        $owner = $this->canManage($tenant, $user);
        $marketing = $user->canAccessMarketing() && app(CollinsClientAccessService::class)->allows($user, $tenant);
        $modules = app(TenantModuleAccessResolver::class);
        $enabled = fn (string $key): bool => $modules->canAccess($tenant->id, $key);
        $rows = [];
        $add = function (string $id, string $category, string $label, string $description, string $keywords, string $route, array $params = [], string $scope = 'Organization') use (&$rows, $tenant): void {
            if (Route::has($route)) {
                $rows[] = compact('id', 'category', 'label', 'description', 'keywords', 'scope')
                    + ['url' => route($route, ['tenant' => $tenant->slug, ...$params], false)];
            }
        };
        $add('personal', 'account', 'Personal profile', 'Your name and sign-in email.', 'name email identity', 'profile.edit', [], 'Personal');
        $add('password', 'account', 'Password', 'Change the password you use to sign in.', 'security login sign in', 'user-password.edit', [], 'Personal');
        $add('appearance', 'account', 'Appearance', 'Choose your display preferences.', 'theme dark light', 'appearance.edit', [], 'Personal');
        if (Features::canManageTwoFactorAuthentication()) {
            $add('2fa', 'account', 'Two-factor authentication', 'Add a second step to protect your account.', 'security authenticator recovery', 'two-factor.show', [], 'Personal');
        }
        if ($owner) {
            $add('business', 'organization', 'Business profile', 'Your industry, operating model and first goal.', 'company organization electrical plumbing retail maker specialty', 'workspace-settings.business');
        }
        if ($user->isAdmin() && app(TenantBrandProfileService::class)->userCanCustomize($user, $tenant)) {
            $add('brand', 'organization', 'Brand & appearance', 'Workspace logo, colors and display name.', 'logo branding colour', 'tenant.brand.edit');
        }
        if ($owner && $user->isAdmin()) {
            $add('members', 'team', 'People & permissions', 'Review members, invitations and roles.', 'invite employees staff team access', 'admin.users');
        }
        $add('plan', 'billing', 'Your plan & branches', 'See what is included, assigned and available to discuss.', 'subscription cost price upgrade package', 'marketing.modules', ['tab' => 'plan']);
        if ($owner && $marketing) {
            $add('agreements', 'billing', 'Agreements & billing', 'Review your recorded terms and existing billing options.', 'invoices payment method receipts charges', 'agreements.index');
        }
        if ($marketing && $enabled('integrations')) {
            $add('connections', 'integrations', 'Store & provider connections', 'Check connection status and available reconnect steps.', 'shopify square store connection email disconnected', 'marketing.providers-integrations', [], 'Branch');
        }
        if ($enabled('field_service') && app(FieldServiceAccessService::class)->canManageJobs($user, $tenant)
            && app(CollinsClientAccessService::class)->allows($user, $tenant)) {
            $add('field', 'branches', 'Field service', 'Customers, jobs, scheduling and workforce settings.', 'electrical electrician crew services calendar', 'field-service.index', [], 'Branch');
        }
        if ($enabled('class_scheduling') && $marketing) {
            $add('education', 'branches', 'Classes & activities', 'Class, enrollment and signup settings.', 'school co-op term education', 'class-scheduling.index', [], 'Branch');
        }
        $add('branches', 'branches', 'Your branches', 'Review assigned software and its setup state.', 'modules tools capabilities owned', 'marketing.modules', ['tab' => 'branches'], 'Branch');

        return $rows;
    }

    public function search(array $catalog, string $query, string $category): array
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($catalog, function (array $row) use ($words, $category): bool {
            if ($words === [] && $category !== '' && $row['category'] !== $category) {
                return false;
            }
            $text = mb_strtolower($row['label'].' '.$row['description'].' '.$row['keywords']);

            return collect($words)->every(fn (string $word): bool => str_contains($text, $word));
        }));
    }
}
