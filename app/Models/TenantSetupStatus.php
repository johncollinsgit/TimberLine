<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSetupStatus extends Model
{
    public const BUSINESS_PROFILE_STATUSES = ['not_started', 'in_progress', 'ready'];

    public const IMPORT_PATH_OPTIONS = ['shopify', 'square', 'csv', 'manual', 'other', 'undecided'];

    public const SHOPIFY_CONNECTION_STATUSES = ['not_connected', 'connected'];

    public const SQUARE_STATUSES = ['not_requested', 'requested', 'manual_setup', 'planned'];

    public const CSV_MANUAL_STATUSES = ['not_started', 'requested', 'in_progress', 'ready'];

    public const MOBILE_INTEREST_OPTIONS = ['none', 'android', 'ios', 'both', 'undecided'];

    public const LANDLORD_REVIEW_STATUSES = ['pending_review', 'reviewed', 'waiting_on_tenant', 'waiting_on_everbranch'];

    public const PLAN_INTEREST_OPTIONS = ['starter', 'growth', 'pro', 'custom', 'undecided'];

    public const BILLING_LANE_INTEREST_OPTIONS = ['shopify_app_store', 'stripe_direct', 'manual_invoice', 'free_internal_demo', 'undecided'];

    protected $fillable = [
        'tenant_id',
        'business_profile_status',
        'import_path',
        'shopify_connection_status',
        'square_status',
        'csv_manual_status',
        'module_interests',
        'mobile_interest',
        'plan_interest',
        'billing_lane_interest',
        'implementation_help_interest',
        'commercial_notes',
        'commercial_review_status',
        'commercial_next_action',
        'commercial_reviewed_by',
        'commercial_reviewed_at',
        'landlord_review_status',
        'next_recommended_action',
        'internal_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'module_interests' => 'array',
            'implementation_help_interest' => 'boolean',
            'commercial_reviewed_by' => 'integer',
            'commercial_reviewed_at' => 'datetime',
            'reviewed_by' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function commercialReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commercial_reviewed_by');
    }

    public function needsEverbranchReview(): bool
    {
        return in_array((string) $this->landlord_review_status, ['pending_review', 'waiting_on_everbranch'], true);
    }

    public function shopifySelectedButNotConnected(): bool
    {
        return (string) $this->import_path === 'shopify'
            && (string) $this->shopify_connection_status !== 'connected';
    }

    public function hasManualImportPath(): bool
    {
        return in_array((string) $this->import_path, ['square', 'csv', 'manual', 'other'], true);
    }

    public function hasMobileInterest(): bool
    {
        return in_array((string) $this->mobile_interest, ['android', 'ios', 'both'], true);
    }

    public function hasCommercialIntent(): bool
    {
        return (string) ($this->plan_interest ?: 'undecided') !== 'undecided'
            || (string) ($this->billing_lane_interest ?: 'undecided') !== 'undecided'
            || (bool) $this->implementation_help_interest
            || trim((string) $this->commercial_notes) !== '';
    }

    public function needsCommercialReview(): bool
    {
        return in_array((string) ($this->commercial_review_status ?: 'pending_review'), ['pending_review', 'waiting_on_everbranch'], true);
    }

    public function wantsImplementationHelp(): bool
    {
        return (bool) $this->implementation_help_interest;
    }

    public function importPathLabel(): string
    {
        return match ((string) $this->import_path) {
            'shopify' => 'Shopify',
            'square' => 'Square',
            'csv' => 'CSV import',
            'manual' => 'Manual entry',
            'other' => 'Other',
            default => 'Undecided',
        };
    }

    public function importPathGuidance(): string
    {
        return match ((string) $this->import_path) {
            'shopify' => (string) $this->shopify_connection_status === 'connected'
                ? 'Shopify is connected. Everbranch can use this as the primary supported integration path while setup is reviewed.'
                : 'Shopify is the primary supported integration path. Everbranch will guide connection from the existing Shopify setup flow; this page does not change OAuth or install behavior.',
            'square' => 'Square has been captured as your requested import path. Square setup is still planned/manual and requires Everbranch review before any connector automation is used.',
            'csv' => 'CSV import has been captured as your requested path. Everbranch will coordinate file format, mapping, and validation before any data import is run.',
            'manual' => 'Manual setup means Everbranch will review your business details and coordinate setup steps with you.',
            'other' => 'Everbranch will review the requested setup path and confirm what can be supported safely.',
            default => 'No import path has been chosen yet. You can choose Shopify, Square, CSV, manual, or wait for Everbranch review.',
        };
    }

    public function setupPhaseLabel(): string
    {
        if ((string) $this->landlord_review_status === 'reviewed') {
            return 'Reviewed by Everbranch';
        }

        if ((string) $this->import_path === 'shopify' && (string) $this->shopify_connection_status !== 'connected') {
            return 'Waiting for Shopify connection';
        }

        if ((string) $this->import_path === 'undecided') {
            return 'Choosing setup path';
        }

        if ($this->needsEverbranchReview()) {
            return 'Waiting on Everbranch review';
        }

        return 'Setup in progress';
    }

    public function mobileInterestLabel(): string
    {
        return match ((string) $this->mobile_interest) {
            'none' => 'No mobile interest',
            'android' => 'Android',
            'ios' => 'iOS',
            'both' => 'Android and iOS',
            default => 'Undecided',
        };
    }

    public function mobileInterestGuidance(): string
    {
        return match ((string) $this->mobile_interest) {
            'none' => 'No mobile companion has been requested for this workspace.',
            'android' => 'Android interest is captured for future companion app planning. This is not an active generic Everbranch mobile app.',
            'ios' => 'iPhone/iOS interest is captured for future companion app planning. This is not an active generic Everbranch mobile app.',
            'both' => 'Android and iOS interest is captured for future companion app planning. This is not an active generic Everbranch mobile app.',
            default => 'Mobile companion needs are undecided. Everbranch can review whether Android, iOS, both, or no mobile access is needed later.',
        };
    }

    public function nextActionLabel(): string
    {
        $action = trim((string) $this->next_recommended_action);

        return $action !== '' ? $action : 'Review setup status and choose the next operator action.';
    }

    public function planInterestLabel(): string
    {
        $plans = (array) config('commercial.plans', []);
        $key = (string) $this->plan_interest;

        return match ($key) {
            'custom' => 'Custom',
            'undecided', '' => 'Undecided',
            default => (string) data_get($plans, $key.'.name', str($key)->headline()->toString()),
        };
    }

    public function billingLaneInterestLabel(): string
    {
        return match ((string) $this->billing_lane_interest) {
            'shopify_app_store' => 'Shopify App Store Billing',
            'stripe_direct' => 'Stripe Direct Billing',
            'manual_invoice' => 'Manual invoice/service billing',
            'free_internal_demo' => 'Free/internal/demo',
            default => 'Undecided',
        };
    }

    public function billingLaneGuidance(): string
    {
        return match ((string) $this->billing_lane_interest) {
            'shopify_app_store' => 'Shopify App Store Billing is the future lane for merchants who install through the Shopify App Store. It is not active yet.',
            'stripe_direct' => 'Stripe Direct Billing is a future lane for direct SaaS, custom, service, and non-Shopify customers. Tenant self-service Stripe checkout is not active.',
            'manual_invoice' => 'Manual invoice/service billing is an early/custom work lane. This selection does not generate quotes, invoices, or payment links.',
            'free_internal_demo' => 'Free/internal/demo is for Modern Forestry, staging, demos, and explicit internal access. It does not create paid billing.',
            default => 'Billing lane is undecided. Everbranch will review whether Shopify App Store Billing, Stripe direct billing, manual invoice, or free/internal/demo fits later.',
        };
    }

    public function planSelectionGuidance(): string
    {
        return match ((string) $this->plan_interest) {
            'starter' => 'Starter interest is captured as a planning signal. It does not start checkout, billing, or entitlement changes.',
            'growth' => 'Growth interest is captured as a planning signal. It does not start checkout, billing, or entitlement changes.',
            'pro' => 'Pro interest is captured as a planning signal. It does not start checkout, billing, or entitlement changes.',
            'custom' => 'Custom plan interest means Everbranch should review needs manually before any commercial activation.',
            default => 'Plan interest is undecided. You can indicate a likely package now or leave it for Everbranch review.',
        };
    }

    public function commercialIntentSummary(): string
    {
        $parts = [
            'Plan: '.$this->planInterestLabel(),
            'Billing lane: '.$this->billingLaneInterestLabel(),
        ];

        if ((bool) $this->implementation_help_interest) {
            $parts[] = 'Implementation help requested';
        }

        return implode(' · ', $parts);
    }
}
