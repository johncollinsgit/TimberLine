<?php

namespace App\Services\Retail;

use App\Models\WebsiteCustomer;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RetailVoiceDemoContext
{
    public const DEVICE_CUSTOMERS = ['sample-1' => 'Avery Morgan', 'sample-2' => 'Jordan Lee'];

    public function __construct(private RetailShowroomService $showroom) {}

    public static function rules(mixed $merchant): array
    {
        $id = ['required', 'string', 'regex:/\ADEMO-[A-Z0-9]{8}\z/'];
        $cents = ['required', 'integer', 'min:0', 'max:100000000'];

        return [
            'focus_customer' => ['nullable', 'string', 'max:50', 'regex:/\A(?:sample-[12]|[0-9]{1,18})\z/'],
            'sample_activity' => ['sometimes', 'array:merchant,customers,orders', 'required_array_keys:merchant,customers,orders'],
            'sample_activity.merchant' => ['required_with:sample_activity', 'string', Rule::in(is_string($merchant) ? [$merchant] : [])],
            'sample_activity.customers' => ['array', 'max:2'],
            'sample_activity.customers.*' => ['array:id,points,notes,reward_entries', 'required_array_keys:id,points,notes,reward_entries'],
            'sample_activity.customers.*.id' => ['required', 'string', 'distinct', Rule::in(array_keys(self::DEVICE_CUSTOMERS))],
            'sample_activity.customers.*.points' => ['required', 'integer', 'min:0', 'max:1000000'],
            'sample_activity.customers.*.notes' => ['array', 'max:6'],
            'sample_activity.customers.*.notes.*' => ['string', 'max:1000'],
            'sample_activity.customers.*.reward_entries' => ['array', 'max:20'],
            'sample_activity.customers.*.reward_entries.*' => ['array:order_id,earned,redeemed,is_demo'],
            'sample_activity.customers.*.reward_entries.*.order_id' => $id,
            'sample_activity.customers.*.reward_entries.*.earned' => ['required', 'integer', 'min:0', 'max:1000000'],
            'sample_activity.customers.*.reward_entries.*.redeemed' => ['required', 'integer', Rule::in([0, 100])],
            'sample_activity.customers.*.reward_entries.*.is_demo' => ['required', 'accepted'],
            'sample_activity.orders' => ['array', 'max:30'],
            'sample_activity.orders.*' => ['array:id,is_demo,status,customer_id,lines,subtotal_cents,shipping_cents,reward_credit_cents,total_cents,service,created_at'],
            'sample_activity.orders.*.id' => [...$id, 'distinct'],
            'sample_activity.orders.*.is_demo' => ['required', 'accepted'],
            'sample_activity.orders.*.status' => ['required', Rule::in(['simulated'])],
            'sample_activity.orders.*.customer_id' => ['nullable', 'string', Rule::in(array_keys(self::DEVICE_CUSTOMERS))],
            'sample_activity.orders.*.lines' => ['required', 'array', 'min:1', 'max:40'],
            'sample_activity.orders.*.lines.*' => ['array:name,variant,quantity,line_cents'],
            'sample_activity.orders.*.lines.*.name' => ['required', 'string', 'max:190'],
            'sample_activity.orders.*.lines.*.variant' => ['required', 'string', 'max:160'],
            'sample_activity.orders.*.lines.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'sample_activity.orders.*.lines.*.line_cents' => $cents,
            'sample_activity.orders.*.subtotal_cents' => $cents,
            'sample_activity.orders.*.shipping_cents' => $cents,
            'sample_activity.orders.*.reward_credit_cents' => $cents,
            'sample_activity.orders.*.total_cents' => $cents,
            'sample_activity.orders.*.service' => ['required', 'string', 'max:160'],
            'sample_activity.orders.*.created_at' => ['required', 'date', 'max:50'],
        ];
    }

    public function records(?array $merchant, ?array $sample, ?string $focus): array
    {
        $result = ['source' => 'none', 'as_of' => now()->toIso8601String(), 'customers' => [], 'orders' => [],
            'focused_customer' => null, 'customers_truncated' => false, 'orders_truncated' => false,
            'rewards_rule' => 'Illustrative: earn 1 point per merchandise dollar after credit; redeem 100 points for up to $5 off merchandise. No real money or rewards.'];
        if (! $merchant) {
            if ($sample !== null || $focus !== null) {
                throw ValidationException::withMessages(['merchant' => 'Select a demo business before using customer context.']);
            }

            return $result;
        }
        if ($sample !== null) {
            $result['source'] = 'tablet_sample';
            $customers = array_map(fn ($c) => ['id' => $c['id'], 'name' => self::DEVICE_CUSTOMERS[$c['id']],
                'points' => $c['points'], 'notes' => $c['notes'], 'reward_entries' => $c['reward_entries']], $sample['customers']);
            $orders = $sample['orders'];
            $result['orders_truncated'] = count($orders) >= 30;
            $this->validateSampleOrders($merchant, $orders, array_column($customers, 'id'));
        } elseif (($merchant['slug'] ?? '') === 'sawyer-naturals') {
            // Sawyer's source workspace and customers are never queried by the voice demo.
            if ($focus !== null) {
                throw ValidationException::withMessages(['focus_customer' => 'Open a fictional tablet customer to discuss this profile.']);
            }

            return $result;
        } else {
            $site = $this->showroom->site($merchant['slug']);
            $result['source'] = 'connected_demo';
            $query = WebsiteCustomer::where('tenant_id', $site->tenant_id)->where('email', 'like', '%.invalid')->where('notes->is_demo', true);
            $result['customers_truncated'] = (clone $query)->count() > 50;
            $rows = $query->orderBy('id')->limit(50)->get(['id', 'first_name', 'last_name', 'notes'])
                ->filter(fn ($c) => data_get($c->notes, 'is_demo') === true)->values();
            $customers = $rows->take(50)->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->first_name.' '.$c->last_name,
                'points' => data_get($c->notes, 'reward_points', 0), 'notes' => array_column(data_get($c->notes, 'private_notes', []), 'body'),
                'reward_entries' => data_get($c->notes, 'reward_entries', [])])->all();
            $ids = array_map('strval', array_column($customers, 'id'));
            $orders = array_values(array_filter(array_reverse(data_get($site->settings, 'retail_demo.orders', [])),
                fn ($o) => is_array($o) && ($o['is_demo'] ?? false) === true && ($o['status'] ?? '') === 'simulated'
                    && preg_match('/\ADEMO-[A-Z0-9]{8}\z/', (string) ($o['id'] ?? ''))
                    && (empty($o['customer_id']) || in_array((string) $o['customer_id'], $ids, true))));
            $result['orders_truncated'] = count($orders) > 30;
            $orders = array_slice($orders, 0, 30);
        }
        $result['customers'] = array_map(fn ($c) => [
            'id' => (string) $c['id'], 'name' => $this->text($c['name'], 160),
            'reward_points' => max(0, min(1000000, (int) $c['points'])),
            'sample_staff_notes' => array_map(fn ($note) => $this->text($note, 1000), array_slice($c['notes'], -6)),
            'reward_activity' => array_values(array_map(fn ($entry) => ['order_id' => $this->text($entry['order_id'] ?? '', 40),
                'earned' => max(0, (int) ($entry['earned'] ?? 0)), 'redeemed' => max(0, (int) ($entry['redeemed'] ?? 0))],
                array_slice(array_filter($c['reward_entries'], fn ($entry) => ($entry['is_demo'] ?? false) === true), -20))),
        ], $customers);
        $result['orders'] = array_map(fn ($o) => [
            'id' => $this->text($o['id'], 40), 'customer_id' => isset($o['customer_id']) ? (string) $o['customer_id'] : null,
            'lines' => array_map(fn ($l) => ['name' => $this->text($l['name'] ?? '', 190), 'variant' => $this->text($l['variant'] ?? '', 160),
                'quantity' => max(0, min(20, (int) ($l['quantity'] ?? 0))), 'line_cents' => max(0, (int) ($l['line_cents'] ?? 0))], array_slice($o['lines'] ?? [], 0, 40)),
            'total_cents' => max(0, (int) ($o['total_cents'] ?? 0)), 'shipping_cents' => max(0, (int) ($o['shipping_cents'] ?? 0)),
            'reward_credit_cents' => max(0, (int) ($o['reward_credit_cents'] ?? 0)), 'service' => $this->text($o['service'] ?? '', 160),
            'status' => 'simulated', 'created_at' => $this->text($o['created_at'] ?? '', 50),
        ], $orders);
        $result['focused_customer'] = collect($result['customers'])->firstWhere('id', $focus);
        if ($focus !== null && $result['focused_customer'] === null) {
            throw ValidationException::withMessages(['focus_customer' => 'That sample customer is not available in this business.']);
        }

        return $result;
    }

    private function validateSampleOrders(array $merchant, array $orders, array $ids): void
    {
        $variants = [];
        foreach ($merchant['products'] as $product) {
            foreach ($product['variants'] as $variant) {
                $variants[$product['name']."\0".$variant['title']] = true;
            }
        }
        foreach ($orders as $order) {
            $subtotal = 0;
            foreach ($order['lines'] as $line) {
                if (! isset($variants[$line['name']."\0".$line['variant']])) {
                    throw ValidationException::withMessages(['sample_activity' => 'A sample order contains an item outside this business catalog.']);
                }
                $subtotal += $line['line_cents'];
            }
            if ((! empty($order['customer_id']) && ! in_array($order['customer_id'], $ids, true))
                || $subtotal !== $order['subtotal_cents'] || $order['reward_credit_cents'] > min(500, $subtotal)
                || $order['total_cents'] !== $subtotal + $order['shipping_cents'] - $order['reward_credit_cents']) {
                throw ValidationException::withMessages(['sample_activity' => 'Check the selected sample customer and order totals.']);
            }
        }
    }

    private function text(mixed $text, int $limit): string
    {
        return is_string($text) ? mb_substr(trim(strip_tags($text)), 0, $limit) : '';
    }
}
