<?php

namespace App\Console\Commands;

use App\Models\ClassEnrollment;
use App\Models\ClassReminder;
use App\Models\ClassSchedulingSetting;
use App\Models\FieldServiceJob;
use App\Models\MarketingProfile;
use App\Models\ScheduledClass;
use App\Models\Tenant;
use App\Models\TenantDiscoveryProfile;
use App\Models\User;
use App\Services\Agreements\AgreementManagementService;
use App\Services\Onboarding\TenantSetupStatusService;
use App\Services\Tenancy\LandlordCommercialConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class EverbranchPrepareFrontYardFoods extends Command
{
    protected $signature = 'everbranch:prepare-front-yard-foods
        {--john-email=johncollinsemail@gmail.com : Admin user to attach for the customer demonstration}
        {--implementation-fee= : Agreed Shopify migration and implementation amount in dollars}
        {--implementation-due-on-acceptance= : Implementation amount due on acceptance in dollars}
        {--implementation-due-before-launch= : Implementation amount due before launch in dollars}
        {--send-agreement : Rotate proposal access and print the one-time password}
        {--agreement-password= : Optional 10+ character proposal password used only when sending}';

    protected $description = 'Create or refresh the Front Yard Foods demonstration workspace, preferring tenant ID 4.';

    private const LOGO_URL = 'https://images.squarespace-cdn.com/content/v1/63c03400f2062a1549dffcf0/064a00df-90a9-4286-8602-95bb053a1199/frontyardfoods.png';

    private const HERO_URL = 'https://images.squarespace-cdn.com/content/v1/63c03400f2062a1549dffcf0/dbf72ff0-19fc-4c9a-b2b1-abab6330e618/processed_20230122_174853.jpg';

    /** @var array<int,string> */
    private const SERVICE_PHOTOS = [
        'https://images.squarespace-cdn.com/content/v1/63c03400f2062a1549dffcf0/10b99b8e-1eff-4290-80d5-be98eeb67060/processed_20221217_110428.jpg',
        'https://images.squarespace-cdn.com/content/v1/63c03400f2062a1549dffcf0/a131dd93-0cb3-4438-82ee-72619da00eb8/processed_20220720_072530.jpg',
        'https://images.squarespace-cdn.com/content/v1/63c03400f2062a1549dffcf0/25d95f4c-672a-4b40-92a0-13270299644c/processed_20220916_161730.jpg',
        'https://images.squarespace-cdn.com/content/v1/63c03400f2062a1549dffcf0/fc26df7a-430d-4097-8425-24ad3870d6fc/processed_20220615_072332.jpg',
    ];

    public function handle(
        LandlordCommercialConfigService $commercial,
        TenantSetupStatusService $setupStatuses,
        AgreementManagementService $agreements,
    ): int {
        $email = strtolower(trim((string) $this->option('john-email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid --john-email value.');

            return self::FAILURE;
        }
        try {
            $implementationFee = $this->dollarsToCents($this->option('implementation-fee'));
            $implementationOnAcceptance = $this->dollarsToCents($this->option('implementation-due-on-acceptance'));
            $implementationBeforeLaunch = $this->dollarsToCents($this->option('implementation-due-before-launch'));
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(function () use ($commercial, $setupStatuses, $email): array {
                $tenant = $this->frontYardTenant();
                $john = $this->john($email);
                $tenant->users()->syncWithoutDetaching([
                    (int) $john->id => ['role' => 'admin', 'created_at' => now(), 'updated_at' => now()],
                ]);

                $commercial->assignTenantPlan(
                    tenantId: (int) $tenant->id,
                    planKey: 'base',
                    operatingMode: 'direct',
                    source: 'front_yard_foods_guided_demo',
                    actorId: (int) $john->id,
                );

                foreach (['customers', 'class_scheduling', 'plant_inventory', 'reporting'] as $moduleKey) {
                    $commercial->setTenantModuleState((int) $tenant->id, $moduleKey, true, 'configured', (int) $john->id);
                }
                $commercial->setTenantModuleState((int) $tenant->id, 'field_service', false, 'not_started', (int) $john->id);
                $commercial->setTenantModuleEntitlement((int) $tenant->id, 'plant_inventory', [
                    'availability_status' => 'available',
                    'enabled_status' => 'enabled',
                    'billing_status' => 'custom_contract',
                    'entitlement_source' => 'front_yard_foods_launch_partner',
                    'notes' => 'Front Yard Foods launch-only plant inventory workspace. Keep hidden from other tenants until productized.',
                    'metadata' => ['launch_scope' => 'front_yard_foods_only', 'mobile_hidden' => true],
                ], (int) $john->id);
                $commercial->setTenantModuleEntitlement((int) $tenant->id, 'messaging', [
                    'availability_status' => 'available',
                    'enabled_status' => 'enabled',
                    'billing_status' => 'complimentary',
                    'entitlement_source' => 'guided_demo',
                    'notes' => 'Front Yard Foods demonstration access. Provider readiness and consent still gate delivery.',
                    'metadata' => ['demo' => true, 'live_sms_enabled' => false],
                ], (int) $john->id);
                $commercial->setTenantModuleState((int) $tenant->id, 'messaging', true, 'configured', (int) $john->id);

                $setup = $setupStatuses->forTenant($tenant);
                $setup->forceFill([
                    'business_profile_status' => 'ready',
                    'import_path' => 'manual',
                    'csv_manual_status' => 'not_started',
                    'module_interests' => ['customers', 'class_scheduling', 'plant_inventory', 'messaging', 'uploads', 'reporting'],
                    'mobile_interest' => 'ios',
                    'plan_interest' => 'base',
                    'billing_lane_interest' => 'undecided',
                    'implementation_help_interest' => true,
                    'commercial_review_status' => 'reviewed',
                    'landlord_review_status' => 'reviewed',
                    'next_recommended_action' => 'Demonstrate class signup, garden consultation scheduling, customer follow-up, and plant inventory holds.',
                    'commercial_next_action' => 'Keep messaging in demo mode until email/SMS providers and consent are verified.',
                    'internal_notes' => 'Front Yard Foods guided demonstration tenant. Prefer tenant 4, then the next open tenant ID. Website branding and photos are copied into tenant-owned demo assets.',
                    'reviewed_at' => now(),
                ])->save();

                $this->brand($tenant);
                $settings = $this->settings($tenant);
                $customers = $this->customers($tenant);
                $classes = $this->classes($tenant);
                $this->enrollments($tenant, $classes, $customers);
                $this->archiveFrontYardDemoJobs($tenant);

                return [
                    'tenant_id' => (int) $tenant->id,
                    'tenant_slug' => (string) $tenant->slug,
                    'user_email' => (string) $john->email,
                    'classes' => count($classes),
                    'customers' => count($customers),
                    'public_url' => route('public.classes.index', ['tenant' => $tenant->slug]),
                ];
            });
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $tenant = Tenant::query()->findOrFail((int) $result['tenant_id']);
            $actorId = User::query()->where('email', $email)->value('id');
            $agreement = $agreements->prepareFrontYardFoods($tenant, $actorId ? (int) $actorId : null, $implementationFee, $implementationOnAcceptance, $implementationBeforeLaunch);
            $result['agreement_id'] = (int) $agreement->id;
            $result['agreement_status'] = (string) $agreement->status;
            $result['agreement_version'] = (int) $agreement->currentVersion?->version_number;
            $result['agreement_content_hash'] = (string) $agreement->currentVersion?->content_hash;
            $result['billing_activation'] = 'disabled_until_acceptance_billing_lane_decision_and_provider_verification';

            if ((bool) $this->option('send-agreement')) {
                $access = $agreements->send($agreement, $actorId ? (int) $actorId : null, $this->option('agreement-password') ?: null);
                $result['proposal_url'] = $access['url'];
                $result['proposal_password'] = $access['password'];
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result as $key => $value) {
            $this->line($key.'='.$value);
        }
        $this->line('sms_delivery=blocked_until_provider_and_consent_ready');

        return self::SUCCESS;
    }

    protected function dollarsToCents(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 999999.99) {
            throw new \InvalidArgumentException('Agreement pricing options must be valid non-negative dollar amounts.');
        }

        return (int) round(((float) $value) * 100);
    }

    protected function frontYardTenant(): Tenant
    {
        $tenant = Tenant::query()->where('slug', 'front-yard-foods')->first();
        if (! $tenant) {
            $usedIds = Tenant::query()->where('id', '>=', 4)->lockForUpdate()->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $tenantId = 4;
            while (in_array($tenantId, $usedIds, true)) {
                $tenantId++;
            }
            $tenant = Tenant::query()->forceCreate(['id' => $tenantId, 'name' => 'Front Yard Foods', 'slug' => 'front-yard-foods']);
        }
        $tenant->forceFill(['name' => 'Front Yard Foods', 'slug' => 'front-yard-foods'])->save();

        return $tenant;
    }

    protected function john(string $email): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $user->name ?: 'John Collins',
            'password' => $user->password ?: Hash::make(Str::random(40)),
            'role' => in_array($user->role, ['admin', 'manager', 'marketing_manager', 'platform_admin'], true) ? $user->role : 'admin',
            'is_active' => true,
            'email_verified_at' => $user->email_verified_at ?: now(),
            'approved_at' => $user->approved_at ?: now(),
            'requested_via' => $user->requested_via ?: 'front_yard_foods_demo',
        ])->save();

        return $user;
    }

    protected function brand(Tenant $tenant): void
    {
        TenantDiscoveryProfile::query()->updateOrCreate(['tenant_id' => (int) $tenant->id], [
            'primary_brand_name' => 'Front Yard Foods',
            'short_brand_summary' => 'Grow your own. We show you how.',
            'long_form_description' => 'Greenville classes, edible-garden consultations, garden design and installation, plant starts, and sustainable food education.',
            'support_email' => 'Laura@FrontYardFoods.com',
            'support_phone' => '(864) 518-7673',
            'primary_logo_url' => self::LOGO_URL,
            'brand_keywords' => ['edible gardening', 'sourdough', 'canning', 'preserving', 'plant starts', 'garden design'],
            'why_choose_us_bullets' => ['Individualized guidance', 'Organic growing practices', 'Greenville-based classes and garden help'],
            'domain_map' => ['primary' => 'https://www.frontyardfoods.com'],
            'geography' => ['city' => 'Greenville', 'state' => 'SC'],
            'is_active' => true,
        ]);
    }

    protected function settings(Tenant $tenant): ClassSchedulingSetting
    {
        return ClassSchedulingSetting::query()->updateOrCreate(['tenant_id' => (int) $tenant->id], [
            'public_signup_enabled' => true,
            'timezone' => 'America/New_York',
            'public_heading' => 'Learn with Laura',
            'public_intro' => 'Hands-on classes in cooking, preserving, canning, sourdough, and growing food near downtown Greenville, South Carolina.',
            'contact_email' => 'Laura@FrontYardFoods.com',
            'logo_url' => self::LOGO_URL,
            'hero_image_url' => self::HERO_URL,
            'brand_color' => '#42654a',
            'default_reminder_offsets' => [24],
            'metadata' => ['source' => 'frontyardfoods.com', 'demo' => true],
        ]);
    }

    /** @return array<string,MarketingProfile> */
    protected function customers(Tenant $tenant): array
    {
        $definitions = [
            'maya' => ['Maya', 'Thompson', 'maya.thompson@example.test', 'Interested in sourdough and a backyard herb garden.'],
            'elliot' => ['Elliot', 'Brooks', 'elliot.brooks@example.test', 'Planning a raised-bed garden consultation.'],
            'priya' => ['Priya', 'Shah', 'priya.shah@example.test', 'Returning canning class customer.'],
            'daniel' => ['Daniel', 'Reed', 'daniel.reed@example.test', 'Wants seasonal plant starts and growing guidance.'],
            'sofia' => ['Sofia', 'Martinez', 'sofia.martinez@example.test', 'Designing an edible front-yard garden.'],
            'noah' => ['Noah', 'Williams', 'noah.williams@example.test', 'Attending with a family member.'],
        ];
        $customers = [];
        foreach ($definitions as $key => [$first, $last, $email, $notes]) {
            $customers[$key] = MarketingProfile::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'normalized_email' => $email],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => $email,
                    'normalized_email' => $email,
                    'phone' => '8646165468',
                    'normalized_phone' => '8646165468',
                    'accepts_email_marketing' => true,
                    'accepts_sms_marketing' => false,
                    'source_channels' => ['front_yard_foods_demo'],
                    'notes' => $notes,
                    'tags' => ['demo', 'front-yard-foods'],
                ]
            );
        }

        return $customers;
    }

    /** @return array<string,ScheduledClass> */
    protected function classes(Tenant $tenant): array
    {
        $base = now()->startOfDay();
        $definitions = [
            'sourdough-basics' => ['Sourdough Basics', 'Cooking', $base->copy()->addDays(2)->setTime(18, 0), 12, 75, self::SERVICE_PHOTOS[0], 'Learn to build and maintain a starter, mix dough, shape a loaf, and understand the rhythm of naturally leavened bread.'],
            'veggie-growing-course' => ['The Veggie Growing Course', 'Growing', $base->copy()->addDays(5)->setTime(10, 0), 14, 95, self::HERO_URL, 'Seasonal, practical guidance for planning, planting, and caring for a productive Greenville vegetable garden.'],
            'canning-preserving-basics' => ['Canning & Preserving Basics', 'Preserving', $base->copy()->addDays(9)->setTime(18, 0), 10, 80, self::SERVICE_PHOTOS[2], 'Turn seasonal produce into safe, delicious food you can enjoy throughout the year.'],
            'edible-garden-workshop' => ['Edible Garden Design Workshop', 'Garden design', $base->copy()->addDays(14)->setTime(10, 0), 16, 65, self::SERVICE_PHOTOS[1], 'Explore placement, raised beds, edible flowers, herbs, and a garden plan that fits your space and lifestyle.'],
        ];
        $classes = [];
        foreach ($definitions as $slug => [$title, $category, $startsAt, $capacity, $price, $image, $description]) {
            $classes[$slug] = ScheduledClass::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'slug' => $slug],
                [
                    'title' => $title,
                    'category' => $category,
                    'description' => $description,
                    'location' => 'Near downtown Greenville, SC',
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->copy()->addHours(2),
                    'capacity' => $capacity,
                    'price' => $price,
                    'status' => 'published',
                    'registration_open' => true,
                    'image_url' => $image,
                    'reminder_offsets' => [24],
                    'metadata' => ['demo' => true, 'source' => 'frontyardfoods.com/services'],
                ]
            );
        }

        return $classes;
    }

    /** @param array<string,ScheduledClass> $classes @param array<string,MarketingProfile> $customers */
    protected function enrollments(Tenant $tenant, array $classes, array $customers): void
    {
        $assignments = [
            'sourdough-basics' => ['maya', 'priya', 'noah'],
            'veggie-growing-course' => ['elliot', 'daniel', 'sofia'],
            'canning-preserving-basics' => ['priya', 'maya'],
            'edible-garden-workshop' => ['sofia', 'elliot'],
        ];
        foreach ($assignments as $classKey => $customerKeys) {
            foreach ($customerKeys as $customerKey) {
                $customer = $customers[$customerKey];
                $class = $classes[$classKey];
                $enrollment = ClassEnrollment::query()->updateOrCreate(
                    ['tenant_id' => (int) $tenant->id, 'scheduled_class_id' => (int) $class->id, 'marketing_profile_id' => (int) $customer->id],
                    [
                        'name' => trim($customer->first_name.' '.$customer->last_name),
                        'email' => $customer->email,
                        'normalized_email' => $customer->normalized_email,
                        'phone' => '8646165468',
                        'normalized_phone' => '8646165468',
                        'seats' => $customerKey === 'noah' ? 2 : 1,
                        'status' => 'confirmed',
                        'email_reminders_enabled' => true,
                        'sms_reminders_enabled' => true,
                        'source' => 'demo_seed',
                        'metadata' => ['demo' => true],
                    ]
                );
                ClassReminder::query()->updateOrCreate(
                    ['tenant_id' => (int) $tenant->id, 'class_enrollment_id' => (int) $enrollment->id, 'channel' => 'sms'],
                    [
                        'scheduled_for' => $class->starts_at->copy()->subDay(),
                        'status' => 'scheduled',
                        'message' => 'Front Yard Foods reminder: '.$class->title.' starts '.$class->starts_at->format('M j \a\t g:i A').'.',
                        'provider_metadata' => ['demo' => true, 'delivery_gate' => 'provider_not_verified'],
                    ]
                );
            }
        }
    }

    protected function archiveFrontYardDemoJobs(Tenant $tenant): void
    {
        FieldServiceJob::query()
            ->forTenantId((int) $tenant->id)
            ->where('external_source', 'front_yard_foods_demo')
            ->orderBy('id')
            ->chunkById(100, function ($jobs): void {
                foreach ($jobs as $job) {
                    $metadata = is_array($job->metadata) ? $job->metadata : [];

                    $job->forceFill([
                        'status' => 'done',
                        'operational_status' => 'history',
                        'archived_at' => $job->archived_at ?? now(),
                        'metadata' => array_merge($metadata, [
                            'archived_reason' => 'front_yard_foods_events_classes_launch_scope',
                        ]),
                    ])->save();
                }
            });
    }
}
