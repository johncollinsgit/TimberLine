<?php

namespace App\Services\Onboarding;

use App\Models\CustomerAccessRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalPasswordSetupNotification;
use App\Support\Tenancy\TenantHostBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CustomerAccessRequestService
{
    public const INTENT_DEMO = 'demo';
    public const INTENT_PRODUCTION = 'production';

    public function __construct(
        protected TenantHostBuilder $hostBuilder,
    ) {
    }

    /**
     * @param  array{
     *   intent:string,
     *   name:string,
     *   email:string,
     *   company?:string,
     *   requested_tenant_slug?:string,
     *   message?:string
     * }  $input
     */
    public function submit(array $input): CustomerAccessRequest
    {
        $intent = strtolower(trim((string) ($input['intent'] ?? self::INTENT_PRODUCTION)));
        if (! in_array($intent, [self::INTENT_DEMO, self::INTENT_PRODUCTION], true)) {
            $intent = self::INTENT_PRODUCTION;
        }

        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $company = trim((string) ($input['company'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));

        $requestedSlug = trim((string) ($input['requested_tenant_slug'] ?? ''));
        if ($intent === self::INTENT_DEMO) {
            $requestedSlug = trim((string) config('tenancy.onboarding.demo_tenant_slug', 'demo'));
        }

        return DB::transaction(function () use ($intent, $email, $name, $company, $message, $requestedSlug): CustomerAccessRequest {
            $user = User::query()->where('email', $email)->first();

            if (! $user) {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make(Str::password(32)),
                    'role' => 'manager',
                    'is_active' => false,
                    'requested_via' => 'customer_'.$intent,
                    'approval_requested_at' => Carbon::now(),
                ]);
            }

            $request = CustomerAccessRequest::query()->create([
                'intent' => $intent,
                'status' => 'pending',
                'name' => $name,
                'email' => $email,
                'company' => $company,
                'requested_tenant_slug' => $requestedSlug !== '' ? strtolower($requestedSlug) : null,
                'message' => $message !== '' ? $message : null,
                'metadata' => [
                    'requested_via' => 'platform',
                ],
                'user_id' => (int) $user->id,
            ]);

            return $request;
        });
    }

    public function approveUser(User $user, ?CustomerAccessRequest $accessRequest, ?int $approverId = null): void
    {
        $intent = strtolower(trim((string) ($accessRequest?->intent ?? '')));
        if (! in_array($intent, [self::INTENT_DEMO, self::INTENT_PRODUCTION], true)) {
            $intent = self::INTENT_PRODUCTION;
        }

        $requestedSlug = strtolower(trim((string) ($accessRequest?->requested_tenant_slug ?? '')));
        if ($intent === self::INTENT_DEMO) {
            $requestedSlug = strtolower(trim((string) config('tenancy.onboarding.demo_tenant_slug', 'demo')));
        }

        DB::transaction(function () use ($user, $accessRequest, $approverId, $requestedSlug): void {
            $user->forceFill([
                'is_active' => true,
                'approved_at' => now(),
                'approved_by' => $approverId,
            ])->save();

            if ($requestedSlug !== '') {
                $tenant = Tenant::query()->where('slug', $requestedSlug)->first();
                if (! $tenant) {
                    $tenant = Tenant::query()->create([
                        'name' => $accessRequest?->company ?: Str::headline($requestedSlug),
                        'slug' => $requestedSlug,
                    ]);
                }

                $user->tenants()->syncWithoutDetaching([
                    (int) $tenant->id => ['role' => 'manager'],
                ]);

                if ($accessRequest) {
                    $accessRequest->forceFill([
                        'status' => 'approved',
                        'tenant_id' => (int) $tenant->id,
                        'approved_by' => $approverId,
                        'approved_at' => now(),
                    ])->save();
                }
            }
        });

        $preferredHost = $requestedSlug !== '' ? $this->hostBuilder->hostForSlug($requestedSlug) : null;
        $user->notify(new ApprovalPasswordSetupNotification($user, $preferredHost));
    }
}

