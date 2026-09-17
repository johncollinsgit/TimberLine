<?php

namespace App\Console\Commands;

use App\Models\MarketingProfile;
use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EverbranchPrepareCarolinaBarrelLaunchDemo extends Command
{
    private const TENANT_SLUG = 'carolina-barrel-co';

    private const ORDER_PREFIX = 'CBC-DEMO-';

    private const SESSION_PREFIX = 'session_started:carolina-barrel-demo-';

    private const PROFILE_DOMAIN = '@demo.carolinabarrel.invalid';

    private const CONFIRMATION = 'CAROLINA-BARREL-DEMO';

    protected $signature = 'everbranch:prepare-carolina-barrel-launch-demo
        {tenant=carolina-barrel-co : The Carolina Barrel Co. tenant slug}
        {--mode=seed : seed, refresh, or remove the launch-partner demo records}
        {--apply : Perform the requested write; otherwise report only}
        {--confirm= : Required with --apply; enter CAROLINA-BARREL-DEMO}
        {--json : Emit a machine-readable result}';

    protected $description = 'Prepare or remove the clearly marked, fictional Carolina Barrel Co. launch-partner dashboard demo.';

    public function handle(): int
    {
        $tenantSlug = strtolower(trim((string) $this->argument('tenant')));
        if ($tenantSlug !== self::TENANT_SLUG) {
            $this->error('This maintenance command is intentionally restricted to the Carolina Barrel Co. workspace.');

            return self::FAILURE;
        }

        $mode = strtolower(trim((string) $this->option('mode')));
        if (! in_array($mode, ['seed', 'refresh', 'remove'], true)) {
            $this->error('The --mode option must be seed, refresh, or remove.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('slug', self::TENANT_SLUG)->first();
        if (! $tenant instanceof Tenant) {
            $this->error('Carolina Barrel Co. was not found. No records were changed.');

            return self::FAILURE;
        }

        $before = $this->demoCounts((int) $tenant->id);
        if (! $this->option('apply')) {
            return $this->respond([
                'status' => 'dry_run',
                'mode' => $mode,
                'tenant_slug' => self::TENANT_SLUG,
                'existing_demo_records' => $before,
                'message' => 'No records were changed. Re-run with --apply --confirm='.self::CONFIRMATION.'.',
            ]);
        }

        if ((string) $this->option('confirm') !== self::CONFIRMATION) {
            $this->error('Refusing to write demo data without the exact --confirm value.');

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($tenant, $mode, $before): array {
            $removed = ['profiles' => 0, 'orders' => 0, 'sessions' => 0];

            if (in_array($mode, ['refresh', 'remove'], true)) {
                $removed = $this->removeDemoRecords((int) $tenant->id);
            }

            if ($mode !== 'remove') {
                $this->seedDemoRecords($tenant);
            }

            return [
                'status' => 'complete',
                'mode' => $mode,
                'tenant_slug' => self::TENANT_SLUG,
                'existing_demo_records' => $before,
                'removed' => $removed,
                'current_demo_records' => $this->demoCounts((int) $tenant->id),
                'message' => $mode === 'remove'
                    ? 'Only records carrying the Carolina Barrel demo markers were removed.'
                    : 'Fictional, non-deliverable launch-partner demo records are ready. No customer messages, payments, or website publishing were triggered.',
            ];
        });

        return $this->respond($result);
    }

    /** @return array{profiles:int,orders:int,sessions:int} */
    private function demoCounts(int $tenantId): array
    {
        return [
            'profiles' => MarketingProfile::query()
                ->forTenantId($tenantId)
                ->where('normalized_email', 'like', '%'.self::PROFILE_DOMAIN)
                ->where('notes', 'like', '%Carolina Barrel launch-partner demo%')
                ->count(),
            'orders' => Order::query()
                ->forTenantId($tenantId)
                ->where('order_number', 'like', self::ORDER_PREFIX.'%')
                ->count(),
            'sessions' => MarketingStorefrontEvent::query()
                ->forTenantId($tenantId)
                ->where('source_id', 'like', self::SESSION_PREFIX.'%')
                ->count(),
        ];
    }

    /** @return array{profiles:int,orders:int,sessions:int} */
    private function removeDemoRecords(int $tenantId): array
    {
        // Every predicate below is tenant-scoped and tied to a stable fixture
        // marker. Never use this as a broad tenant cleanup.
        $sessions = MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->where('source_id', 'like', self::SESSION_PREFIX.'%')
            ->delete();
        $orders = Order::query()
            ->forTenantId($tenantId)
            ->where('order_number', 'like', self::ORDER_PREFIX.'%')
            ->delete();
        $profiles = MarketingProfile::query()
            ->forTenantId($tenantId)
            ->where('normalized_email', 'like', '%'.self::PROFILE_DOMAIN)
            ->where('notes', 'like', '%Carolina Barrel launch-partner demo%')
            ->delete();

        return compact('profiles', 'orders', 'sessions');
    }

    private function seedDemoRecords(Tenant $tenant): void
    {
        $tenantId = (int) $tenant->id;
        $profileIds = [];

        foreach ($this->profiles() as $index => $profile) {
            $email = strtolower($profile['key'].self::PROFILE_DOMAIN);
            $record = MarketingProfile::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'normalized_email' => $email],
                [
                    'first_name' => 'DEMO '.$profile['first_name'],
                    'last_name' => $profile['last_name'],
                    'email' => $email,
                    'normalized_email' => $email,
                    'accepts_email_marketing' => false,
                    'accepts_sms_marketing' => false,
                    'email_opted_out_at' => now(),
                    'sms_opted_out_at' => now(),
                    'source_channels' => ['carolina_barrel_launch_partner_demo'],
                    'notes' => 'Fictional Carolina Barrel launch-partner demo profile. Not a real person; do not contact or export.',
                    'created_at' => now()->subDays(24 - $index * 3),
                    'updated_at' => now(),
                ]
            );
            $profileIds[] = (int) $record->id;
        }

        foreach ($this->orders() as $order) {
            $orderedAt = now()->subDays($order['days_ago'])->setTime(10, 30);
            Order::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'order_number' => self::ORDER_PREFIX.$order['number']],
                [
                    'source' => $order['source'],
                    'container_name' => 'DEMO · '.$order['channel'],
                    'customer_name' => 'DEMO · '.$order['customer'],
                    'ordered_at' => $orderedAt,
                    'due_date' => $orderedAt->addDays(14)->toDateString(),
                    'status' => 'complete',
                    'internal_notes' => 'Fictional launch-partner demo order. No payment, fulfillment, shipment, or customer action is associated with this record.',
                    'currency_code' => 'USD',
                    'subtotal_price' => $order['total'],
                    'total_price' => $order['total'],
                    'created_at' => $orderedAt,
                    'updated_at' => now(),
                ]
            );
        }

        $sessionNumber = 1;
        foreach (range(0, 29) as $daysAgo) {
            $sessionsForDay = $daysAgo % 5 === 0 ? 4 : 2;
            foreach (range(1, $sessionsForDay) as $session) {
                $occurredAt = now()->subDays($daysAgo)->setTime(8 + $session, 15);
                $sourceId = self::SESSION_PREFIX.$occurredAt->format('Ymd').'-'.str_pad((string) $sessionNumber, 3, '0', STR_PAD_LEFT);
                MarketingStorefrontEvent::query()->updateOrCreate(
                    ['tenant_id' => $tenantId, 'source_id' => $sourceId],
                    [
                        'event_type' => 'session_started',
                        'status' => 'ok',
                        'source_surface' => 'launch_partner_demo',
                        'source_type' => 'carolina_barrel_demo',
                        'marketing_profile_id' => $profileIds[$sessionNumber % count($profileIds)],
                        'meta' => [
                            'fixture' => 'carolina_barrel_launch_partner_demo',
                            'fictional' => true,
                            'notice' => 'Demo-only storefront activity. No visitor data was collected.',
                        ],
                        'occurred_at' => $occurredAt,
                        'resolution_status' => 'resolved',
                        'resolution_notes' => 'Fictional launch-partner dashboard demo activity.',
                        'created_at' => $occurredAt,
                        'updated_at' => now(),
                    ]
                );
                $sessionNumber++;
            }
        }
    }

    /** @return array<int,array{key:string,first_name:string,last_name:string}> */
    private function profiles(): array
    {
        return [
            ['key' => 'jordan-reed', 'first_name' => 'Jordan', 'last_name' => 'Reed'],
            ['key' => 'casey-brooks', 'first_name' => 'Casey', 'last_name' => 'Brooks'],
            ['key' => 'morgan-lee', 'first_name' => 'Morgan', 'last_name' => 'Lee'],
            ['key' => 'taylor-ellis', 'first_name' => 'Taylor', 'last_name' => 'Ellis'],
            ['key' => 'avery-bennett', 'first_name' => 'Avery', 'last_name' => 'Bennett'],
            ['key' => 'riley-hayes', 'first_name' => 'Riley', 'last_name' => 'Hayes'],
        ];
    }

    /** @return array<int,array{number:string,days_ago:int,source:string,channel:string,customer:string,total:float}> */
    private function orders(): array
    {
        return [
            ['number' => '1001', 'days_ago' => 28, 'source' => 'retail', 'channel' => 'Retail website order', 'customer' => 'Jordan Reed', 'total' => 329.00],
            ['number' => '1002', 'days_ago' => 25, 'source' => 'wholesale', 'channel' => 'Wholesale inquiry', 'customer' => 'Upstate Smokehouse', 'total' => 840.00],
            ['number' => '1003', 'days_ago' => 21, 'source' => 'affiliate', 'channel' => 'Affiliate referral', 'customer' => 'Casey Brooks', 'total' => 289.00],
            ['number' => '1004', 'days_ago' => 18, 'source' => 'retail', 'channel' => 'Retail website order', 'customer' => 'Morgan Lee', 'total' => 359.00],
            ['number' => '1005', 'days_ago' => 15, 'source' => 'wholesale', 'channel' => 'Wholesale inquiry', 'customer' => 'Carolina Event Supply', 'total' => 1260.00],
            ['number' => '1006', 'days_ago' => 12, 'source' => 'custom_quote', 'channel' => 'Custom quote', 'customer' => 'Taylor Ellis', 'total' => 475.00],
            ['number' => '1007', 'days_ago' => 9, 'source' => 'retail', 'channel' => 'Retail website order', 'customer' => 'Avery Bennett', 'total' => 399.00],
            ['number' => '1008', 'days_ago' => 6, 'source' => 'affiliate', 'channel' => 'Affiliate referral', 'customer' => 'Riley Hayes', 'total' => 315.00],
            ['number' => '1009', 'days_ago' => 3, 'source' => 'wholesale', 'channel' => 'Wholesale inquiry', 'customer' => 'Blue Ridge Barbecue', 'total' => 980.00],
            ['number' => '1010', 'days_ago' => 1, 'source' => 'retail', 'channel' => 'Retail website order', 'customer' => 'Jordan Reed', 'total' => 349.00],
        ];
    }

    /** @param array<string,mixed> $result */
    private function respond(array $result): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info((string) $result['message']);
            $this->line('Mode: '.$result['mode']);
            $this->line('Profiles: '.$result['current_demo_records']['profiles'].' | Orders: '.$result['current_demo_records']['orders'].' | Sessions: '.$result['current_demo_records']['sessions']);
        }

        return self::SUCCESS;
    }
}
