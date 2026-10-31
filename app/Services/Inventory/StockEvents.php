<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Event;

/** Stable Everbranch event identity with reviewed Asana/Teams references; never production evidence. */
final class StockEvents
{
    public function __construct(private StockLedger $stock) {}

    public function review(int $tenant, string $request, int $actor, array $input): array
    {
        $provider = $input['provider'] ?? 'manual';
        $external = $input['external_id'] ?? $request;
        if (! in_array($provider, ['manual', 'asana', 'teams'], true) || ! preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $external)
            || ($provider === 'asana' && ! ctype_digit($external)) || trim($input['name'] ?? '') === '' || strlen($input['name']) > 150) {
            throw new StockException('stock_event_invalid', 422);
        }
        foreach (['start_on', 'end_on'] as $field) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $input[$field] ?? '');
            if (! $date || $date->format('Y-m-d') !== ($input[$field] ?? '')) {
                throw new StockException('stock_event_dates_required', 422);
            }
        }
        if ($input['end_on'] < $input['start_on']) {
            throw new StockException('stock_event_dates_required', 422);
        }
        $metadata = array_intersect_key($input, array_flip(['name', 'start_on', 'end_on', 'venue', 'address', 'city', 'state', 'postal_code']));

        return $this->stock->operation($tenant, $request, 'event_reference', $actor, [$provider, $external, $metadata, $input['event_id'] ?? null], function () use ($tenant, $actor, $provider, $external, $metadata, $input): array {
            $old = $this->stock->rows('event_links', $tenant)->where('provider', $provider)->where('external_id', $external)->first();
            if ($old) {
                if (isset($input['event_id']) && (int) $old->event_id !== (int) $input['event_id']) {
                    throw new StockException('stock_event_reference_conflict');
                }

                return ['event_id' => (int) $old->event_id];
            }
            if (isset($input['event_id'])) {
                $id = (int) $input['event_id'];
                if (! $this->owns($tenant, $id)) {
                    throw new StockException('stock_event_unavailable', 404);
                }
            } else {
                $event = Event::create(['name' => $metadata['name'], 'display_name' => $metadata['name'], 'year' => (int) substr($metadata['start_on'], 0, 4),
                    'starts_at' => $metadata['start_on'].' 00:00:00', 'ends_at' => $metadata['end_on'].' 23:59:59', 'venue' => $metadata['venue'] ?? null,
                    'city' => $metadata['city'] ?? null, 'state' => $metadata['state'] ?? null,
                    'source' => 'everbranch_stock', 'source_ref' => $tenant.':'.$provider.':'.$external, 'status' => 'planned']);
                $id = (int) $event->id;
            }
            $this->stock->rows('event_links', $tenant)->insert(['tenant_id' => $tenant, 'event_id' => $id, 'provider' => $provider, 'external_id' => $external,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'reviewed_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);

            return ['event_id' => $id];
        });
    }

    public function owns(int $tenant, int $event): bool
    {
        return $this->stock->rows('event_links', $tenant)->where('event_id', $event)->exists()
            || $this->stock->db->table('orders')->where('tenant_id', $tenant)->where('source', 'internal')->where('order_type', 'event')->where('event_id', $event)->exists()
            || $this->stock->db->table('commerce_stock_production_sources as s')->join('orders as o', 'o.id', '=', 's.order_id')->where('s.tenant_id', $tenant)->where('o.event_id', $event)->exists();
    }

    public function listing(int $tenant): array
    {
        $links = $this->stock->rows('event_links', $tenant)->orderByDesc('id')->get();
        $events = [];
        foreach ($links as $link) {
            if (! isset($events[$link->event_id])) {
                $events[$link->event_id] = ['event_id' => (int) $link->event_id, ...json_decode($link->metadata, true, 64, JSON_THROW_ON_ERROR)];
            }
        }
        foreach (app(StockEventProduction::class)->demand($tenant) as $line) {
            if (! isset($events[$line['event_id']])) {
                $events[$line['event_id']] = ['event_id' => $line['event_id'], 'name' => $line['event_name'] ?: $line['order_number']];
            }
        }

        return array_values($events);
    }
}
