<?php

namespace App\Services\Commerce;

use App\Models\CommerceExternalRecord;
use App\Models\CommerceImportEvent;
use App\Models\CommerceImportRun;
use App\Models\CommerceSource;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The provider-neutral, read-only import lane.
 *
 * This service deliberately writes only commerce_sources, import audit data,
 * and encrypted source snapshots. It cannot write Website Commerce products,
 * native orders, marketing recipients, legacy Shopify data, or tenant sites.
 */
class CommerceImportService
{
    public function __construct(private readonly ExternalCommerceAdapterRegistry $adapters) {}

    /** @var array<string,array<string,array{label:string,available:bool,reason:string}>> */
    private const CAPABILITIES = [
        'shopify' => [
            'catalog' => ['label' => 'Products and variants', 'available' => true, 'reason' => 'Imported as read-only catalog context.'],
            'inventory' => ['label' => 'Inventory', 'available' => true, 'reason' => 'Source inventory is displayed, never written back.'],
            'customers' => ['label' => 'Customers and addresses', 'available' => true, 'reason' => 'Imported only as source evidence.'],
            'orders' => ['label' => 'Orders, refunds, and discounts', 'available' => true, 'reason' => 'Retained in a source-backed lane.'],
            'fulfillment' => ['label' => 'Fulfillments and tracking', 'available' => true, 'reason' => 'Read-only shipment context.'],
            'content' => ['label' => 'Pages, navigation, blog, and media', 'available' => true, 'reason' => 'Imported where the connection has content access.'],
            'consent' => ['label' => 'Email and SMS consent evidence', 'available' => true, 'reason' => 'Evidence only; never enrolls marketing recipients.'],
        ],
        'woocommerce' => [
            'catalog' => ['label' => 'Products and variations', 'available' => true, 'reason' => 'Imported as read-only catalog context.'],
            'inventory' => ['label' => 'Inventory', 'available' => true, 'reason' => 'Source inventory is displayed, never written back.'],
            'customers' => ['label' => 'Customers and addresses', 'available' => true, 'reason' => 'Imported only as source evidence.'],
            'orders' => ['label' => 'Orders, refunds, coupons, and taxes', 'available' => true, 'reason' => 'Retained in a source-backed lane.'],
            'fulfillment' => ['label' => 'Fulfillment notes and tracking', 'available' => true, 'reason' => 'Availability depends on the installed shipping extension.'],
            'content' => ['label' => 'Pages, navigation, posts, and media', 'available' => true, 'reason' => 'Imported where WordPress REST access permits it.'],
            'consent' => ['label' => 'Email and SMS consent evidence', 'available' => true, 'reason' => 'Evidence only; never enrolls marketing recipients.'],
        ],
        'squarespace' => [
            'catalog' => ['label' => 'Products and variants', 'available' => true, 'reason' => 'Imported as read-only catalog context.'],
            'inventory' => ['label' => 'Inventory', 'available' => true, 'reason' => 'Only the source inventory exposed by its API is retained.'],
            'customers' => ['label' => 'Customers and addresses', 'available' => true, 'reason' => 'Imported only as source evidence.'],
            'orders' => ['label' => 'Orders and discounts', 'available' => true, 'reason' => 'Retained in a source-backed lane.'],
            'fulfillment' => ['label' => 'Fulfillment and tracking', 'available' => true, 'reason' => 'Availability follows Squarespace Commerce API access.'],
            'content' => ['label' => 'Store pages, navigation, posts, and media', 'available' => true, 'reason' => 'A report flags templates or content unavailable through the API.'],
            'consent' => ['label' => 'Email and SMS consent evidence', 'available' => false, 'reason' => 'No automatic marketing enrollment is supported.'],
        ],
        'wix' => [
            'catalog' => ['label' => 'Products and variants', 'available' => true, 'reason' => 'Imported as read-only catalog context.'],
            'inventory' => ['label' => 'Inventory', 'available' => true, 'reason' => 'Source inventory is displayed, never written back.'],
            'customers' => ['label' => 'Customers and addresses', 'available' => true, 'reason' => 'Imported only as source evidence.'],
            'orders' => ['label' => 'Orders, refunds, and discounts', 'available' => true, 'reason' => 'Retained in a source-backed lane.'],
            'fulfillment' => ['label' => 'Fulfillments and tracking', 'available' => true, 'reason' => 'Read-only shipment context.'],
            'content' => ['label' => 'Pages, navigation, blog, and media', 'available' => true, 'reason' => 'A report flags app-owned or API-unavailable content.'],
            'consent' => ['label' => 'Email and SMS consent evidence', 'available' => true, 'reason' => 'Evidence only; never enrolls marketing recipients.'],
        ],
    ];

    public function enabledFor(Tenant $tenant): bool
    {
        return (bool) config('managed_website.commerce_imports_enabled', false)
            && in_array((int) $tenant->id, (array) config('managed_website.commerce_imports_tenant_ids', []), true);
    }

    /** @return array<string,array{label:string,available:bool,reason:string}> */
    public function capabilities(string $provider): array
    {
        abort_unless(in_array($provider, CommerceSource::PROVIDERS, true), 422, 'Unsupported commerce source.');

        return self::CAPABILITIES[$provider];
    }

    /** @param array<int,string> $resources */
    public function createDryRun(Tenant $tenant, User $actor, string $provider, array $resources, ?IntegrationConnection $connection = null): CommerceImportRun
    {
        abort_unless($this->enabledFor($tenant), 423, 'Connected commerce imports are not enabled for this workspace.');
        abort_unless(in_array($provider, CommerceSource::PROVIDERS, true), 422, 'Unsupported commerce source.');
        abort_if($connection && ((int) $connection->tenant_id !== (int) $tenant->id || $connection->provider !== $provider), 404);

        $capabilities = $this->capabilities($provider);
        $requested = collect($resources)->filter(fn ($resource) => isset($capabilities[$resource]))->unique()->values()->all();
        abort_if($requested === [], 422, 'Choose at least one supported import category.');

        return DB::transaction(function () use ($tenant, $actor, $provider, $connection, $capabilities, $requested): CommerceImportRun {
            $source = CommerceSource::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'provider' => $provider, 'external_account_id' => $connection?->external_account_id],
                [
                    'integration_connection_id' => $connection?->id,
                    'external_account_label' => $connection?->external_account_label,
                    'mode' => 'connected_operations',
                    'status' => $connection?->isConnected() ? 'ready_for_dry_run' : 'connection_required',
                    'capabilities' => $capabilities,
                ]
            );

            $unsupported = collect($requested)->filter(fn ($resource) => ! $capabilities[$resource]['available'])->values()->all();
            $available = collect($requested)->filter(fn ($resource) => $capabilities[$resource]['available'])->values()->all();
            $run = CommerceImportRun::query()->create([
                'tenant_id' => $tenant->id,
                'commerce_source_id' => $source->id,
                'initiated_by_user_id' => $actor->id,
                'mode' => 'dry_run',
                'status' => $connection?->isConnected() ? 'completed' : 'connection_required',
                'requested_resources' => $requested,
                'counts' => collect($requested)->mapWithKeys(fn ($resource) => [$resource => 0])->all(),
                'report' => [
                    'mode' => 'connected_operations',
                    'write_back' => false,
                    'native_website_tables' => false,
                    'marketing_enrollment' => false,
                    'available_resources' => $available,
                    'unsupported_resources' => $unsupported,
                    'connection_required' => ! $connection?->isConnected(),
                    'cutover' => 'Owner-approved later, after reconciliation and readiness checks.',
                ],
                'started_at' => now(),
                'completed_at' => $connection?->isConnected() ? now() : null,
            ]);
            CommerceImportEvent::query()->create([
                'tenant_id' => $tenant->id,
                'commerce_import_run_id' => $run->id,
                'event_type' => 'dry_run_created',
                'status' => $run->status,
                'message' => 'Created a read-only mapping report. No source or native commerce record was changed.',
                'context' => ['provider' => $provider, 'resources' => $requested],
            ]);

            return $run->load('source', 'events');
        });
    }

    /**
     * Stores a normalized provider record after an adapter fetches it. This is
     * idempotent and intentionally unavailable to the HTTP wizard itself.
     *
     * @param  array<string,mixed>  $payload
     */
    public function storeSourceRecord(CommerceSource $source, string $resource, array $payload): CommerceExternalRecord
    {
        abort_unless(isset($this->capabilities($source->provider)[$resource]), 422, 'Unsupported source resource.');
        $record = $this->adapters->for($source->provider)->normalize($resource, $payload);

        return CommerceExternalRecord::query()->updateOrCreate(
            ['commerce_source_id' => $source->id, 'resource_type' => $resource, 'external_id' => $record['external_id']],
            [
                'tenant_id' => $source->tenant_id,
                'external_parent_id' => $record['external_parent_id'],
                'fingerprint' => hash('sha256', json_encode($record['snapshot'], JSON_THROW_ON_ERROR)),
                'source_updated_at' => $record['source_updated_at'],
                'snapshot' => $record['snapshot'],
                'imported_at' => now(),
                'archived_at' => null,
            ]
        );
    }
}
