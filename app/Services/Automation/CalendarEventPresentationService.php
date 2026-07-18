<?php

namespace App\Services\Automation;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CalendarEventPresentationService
{
    public const COLOR_IDS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11'];

    public const DESCRIPTION_FIELDS = ['notes', 'items', 'total', 'status', 'customer_contact', 'source_link'];

    public const LOCATION_SOURCES = ['none', 'shipping_address', 'billing_address', 'pickup_location'];

    /** @return array<string,mixed> */
    public function defaults(string $sourceProvider): array
    {
        if ($sourceProvider === 'asana') {
            return [
                'title_template' => '{{task_name}}',
                'description_fields' => ['notes', 'source_link'],
                'location_source' => 'none',
                'color_id' => null,
                'availability' => 'busy',
                'visibility' => 'default',
                'reminders' => 'default',
                'cancelled_order_behavior' => 'mark_cancelled',
            ];
        }

        return [
            'title_template' => '{{source}} #{{order_number}} — {{customer_name}}',
            'description_fields' => ['items', 'total', 'status', 'source_link'],
            'location_source' => 'shipping_address',
            'color_id' => null,
            'availability' => 'busy',
            'visibility' => 'default',
            'reminders' => 'default',
            'cancelled_order_behavior' => 'mark_cancelled',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $existing
     * @return array<string,mixed>
     */
    public function fromPayload(array $payload, string $sourceProvider, array $existing = []): array
    {
        $defaults = array_merge($this->defaults($sourceProvider), $existing);
        $fields = array_values(array_intersect(
            self::DESCRIPTION_FIELDS,
            array_map('strval', Arr::wrap($payload['event_description_fields'] ?? $defaults['description_fields']))
        ));
        $location = (string) ($payload['event_location_source'] ?? $defaults['location_source']);
        $colorId = trim((string) ($payload['event_color_id'] ?? $defaults['color_id']));
        $availability = (string) ($payload['event_availability'] ?? $defaults['availability']);
        $visibility = (string) ($payload['event_visibility'] ?? $defaults['visibility']);
        $reminders = (string) ($payload['event_reminders'] ?? $defaults['reminders']);
        $cancelled = (string) ($payload['cancelled_order_behavior'] ?? $defaults['cancelled_order_behavior']);

        return [
            'title_template' => $this->cleanTemplate((string) ($payload['event_title_template'] ?? $defaults['title_template'])),
            'description_fields' => $fields,
            'location_source' => in_array($location, self::LOCATION_SOURCES, true) ? $location : 'none',
            'color_id' => in_array($colorId, self::COLOR_IDS, true) ? $colorId : null,
            'availability' => in_array($availability, ['busy', 'free'], true) ? $availability : 'busy',
            'visibility' => in_array($visibility, ['default', 'private'], true) ? $visibility : 'default',
            'reminders' => in_array($reminders, ['default', 'none'], true) ? $reminders : 'default',
            'cancelled_order_behavior' => in_array($cancelled, ['mark_cancelled', 'leave_unchanged'], true) ? $cancelled : 'mark_cancelled',
        ];
    }

    /**
     * Render only Google Calendar presentation fields. Start/end and private
     * idempotency properties remain the responsibility of the workflow driver.
     *
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $presentation
     * @return array<string,mixed>
     */
    public function render(array $source, array $presentation, string $sourceProvider): array
    {
        $presentation = $this->fromPayload([], $sourceProvider, $presentation);
        $summary = $this->renderTemplate((string) $presentation['title_template'], $source);
        $summary = $summary !== '' ? $summary : $this->fallbackTitle($source);
        if ($this->isCancelled($source) && $presentation['cancelled_order_behavior'] === 'mark_cancelled') {
            $summary = 'Cancelled — '.$summary;
        }

        $description = $this->description($source, (array) $presentation['description_fields']);
        $location = $this->location($source, (string) $presentation['location_source']);

        return array_filter([
            'summary' => Str::limit($summary, 255, ''),
            'description' => Str::limit($description, 8192, ''),
            'location' => $location !== '' ? Str::limit($location, 1024, '') : null,
            'colorId' => $presentation['color_id'],
            'transparency' => $presentation['availability'] === 'free' ? 'transparent' : 'opaque',
            'visibility' => $presentation['visibility'],
            'reminders' => ['useDefault' => $presentation['reminders'] === 'default'],
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array<string,mixed> */
    public function preview(string $sourceProvider, array $presentation): array
    {
        $sourceLabel = (string) data_get(config('automation_workflows.providers'), $sourceProvider.'.label', Str::headline($sourceProvider));

        return $this->render([
            'task_name' => 'Prepare launch order',
            'notes' => 'Confirm products and delivery details.',
            'source' => $sourceLabel,
            'order_number' => '1042',
            'customer_name' => 'Jamie Lee',
            'items' => '2 × Cedar Candle, 1 × Wick Trimmer',
            'total' => '$84.00',
            'status' => $sourceProvider === 'asana' ? 'In progress' : 'Ready to fulfill',
            'customer_email' => 'jamie@example.com',
            'customer_phone' => '(555) 010-1042',
            'source_url' => 'https://example.com/orders/1042',
            'shipping_address' => '128 Evergreen Way, Asheville, NC 28801',
            'billing_address' => '128 Evergreen Way, Asheville, NC 28801',
            'pickup_location' => 'Downtown shop',
        ], $presentation, $sourceProvider);
    }

    /** @param array<string,mixed> $source */
    protected function renderTemplate(string $template, array $source): string
    {
        $values = [];
        foreach ($source as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $values['{{'.$key.'}}'] = trim((string) $value);
            }
        }

        $rendered = strtr($template, $values);
        $rendered = preg_replace('/\{\{[a-z0-9_]+\}\}/i', '', $rendered) ?? '';

        return trim(preg_replace('/\s+/', ' ', $rendered) ?? '');
    }

    /** @param array<string,mixed> $source @param array<int,string> $fields */
    protected function description(array $source, array $fields): string
    {
        $rows = [];
        foreach ($fields as $field) {
            $value = match ($field) {
                'notes' => trim((string) ($source['notes'] ?? '')),
                'items' => $this->labeled('Items', $source['items'] ?? null),
                'total' => $this->labeled('Total', $source['total'] ?? null),
                'status' => $this->labeled('Status', $source['status'] ?? null),
                'customer_contact' => $this->customerContact($source),
                'source_link' => $this->sourceLink($source),
                default => '',
            };
            if ($value !== '') {
                $rows[] = $value;
            }
        }

        return implode("\n\n", $rows);
    }

    /** @param array<string,mixed> $source */
    protected function customerContact(array $source): string
    {
        $contact = array_values(array_filter([
            trim((string) ($source['customer_email'] ?? '')),
            trim((string) ($source['customer_phone'] ?? '')),
        ]));

        return $contact === [] ? '' : 'Customer: '.implode(' · ', $contact);
    }

    /** @param array<string,mixed> $source */
    protected function sourceLink(array $source): string
    {
        $url = trim((string) ($source['source_url'] ?? ''));
        if ($url === '') {
            return '';
        }

        return ((string) ($source['source'] ?? '') !== '' ? (string) $source['source'] : 'Source').' record: '.$url;
    }

    /** @param array<string,mixed> $source */
    protected function location(array $source, string $locationSource): string
    {
        return $locationSource === 'none' ? '' : trim((string) ($source[$locationSource] ?? ''));
    }

    protected function labeled(string $label, mixed $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '' : $label.': '.$value;
    }

    /** @param array<string,mixed> $source */
    protected function fallbackTitle(array $source): string
    {
        foreach (['task_name', 'order_number', 'customer_name', 'source'] as $key) {
            $value = trim((string) ($source[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'Everbranch event';
    }

    /** @param array<string,mixed> $source */
    protected function isCancelled(array $source): bool
    {
        return in_array(Str::lower(trim((string) ($source['status'] ?? ''))), ['cancelled', 'canceled', 'voided'], true);
    }

    protected function cleanTemplate(string $template): string
    {
        $template = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $template) ?? '');

        return $template !== '' ? Str::limit($template, 160, '') : '{{source}} #{{order_number}}';
    }
}
