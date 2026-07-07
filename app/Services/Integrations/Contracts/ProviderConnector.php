<?php

namespace App\Services\Integrations\Contracts;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * The uniform contract every external-provider integration implements, so the
 * platform speaks one language to Shopify, Square, Google Business, QuickBooks,
 * Etsy, Meta, etc. Concrete connectors use Laravel Socialite where a community
 * provider exists, and a thin bespoke driver where it doesn't (Shopify HMAC,
 * Square, Meta).
 *
 * A connector NEVER assumes a single global account — every method is tenant-aware,
 * and every persisted credential lands in integration_connections scoped to a tenant.
 * See docs/architecture/module-standardization-and-readiness-2026-07-07.md.
 */
interface ProviderConnector
{
    /**
     * The stable provider key (e.g. "shopify", "square", "google_business").
     * Matches integration_connections.provider and config keys.
     */
    public function key(): string;

    /**
     * Human-facing label for UI ("Shopify", "Square", ...).
     */
    public function label(): string;

    /**
     * Build the provider authorization URL to send this tenant's operator to.
     * $options may carry provider-specific hints (shop domain, requested scopes).
     */
    public function buildAuthorizationUrl(Tenant $tenant, array $options = []): string;

    /**
     * Handle the OAuth/redirect callback for a tenant, persisting (or updating)
     * the IntegrationConnection with encrypted tokens, and return it.
     */
    public function handleCallback(Tenant $tenant, Request $request): IntegrationConnection;

    /**
     * Refresh an access token using the stored refresh token; returns the updated
     * connection. Implementations should be idempotent and set last_error_* on failure.
     */
    public function refresh(IntegrationConnection $connection): IntegrationConnection;

    /**
     * Return a ready-to-use, authenticated API client for this connection
     * (provider-specific type). Callers type-hint their own provider's client.
     */
    public function client(IntegrationConnection $connection): mixed;
}
