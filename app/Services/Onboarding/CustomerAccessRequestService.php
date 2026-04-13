<?php

namespace App\Services\Onboarding;

use App\Models\CustomerAccessRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CustomerAccessRequestService
{
    public const INTENT_DEMO = 'demo';
    public const INTENT_PRODUCTION = 'production';

    /**
     * @param  array{
     *   intent:string,
     *   name:string,
     *   email:string,
     *   company?:string,
     *   requested_tenant_slug?:string,
     *   business_type?:string,
     *   team_size?:string,
     *   timeline?:string,
     *   website?:string,
     *   message?:string,
     *   preferred_plan_key?:string,
     *   addons_interest?:array<int,string>
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
        $businessType = strtolower(trim((string) ($input['business_type'] ?? '')));
        $teamSize = strtolower(trim((string) ($input['team_size'] ?? '')));
        $timeline = strtolower(trim((string) ($input['timeline'] ?? '')));
        $website = trim((string) ($input['website'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));
        $preferredPlanKey = strtolower(trim((string) ($input['preferred_plan_key'] ?? '')));
        $addonsInterest = array_values(array_filter(array_map(static function (mixed $value): ?string {
            $token = strtolower(trim((string) $value));

            return $token !== '' ? $token : null;
        }, (array) ($input['addons_interest'] ?? []))));

        $requestedSlug = trim((string) ($input['requested_tenant_slug'] ?? ''));
        if ($intent === self::INTENT_DEMO) {
            $requestedSlug = trim((string) config('tenancy.onboarding.demo_tenant_slug', 'demo'));
        }

        return DB::transaction(function () use ($intent, $email, $name, $company, $businessType, $teamSize, $timeline, $website, $message, $requestedSlug, $preferredPlanKey, $addonsInterest): CustomerAccessRequest {
            $normalizedSlug = $this->normalizeSlug($requestedSlug);

            $existing = $this->findOpenRequest($email, $normalizedSlug);
            if ($existing) {
                if ($this->isRejected($existing)) {
                    throw ValidationException::withMessages([
                        'email' => 'This request has been rejected. Please contact sales for next steps.',
                    ]);
                }

                $existing->forceFill([
                    'name' => $name !== '' ? $name : $existing->name,
                    'company' => $company !== '' ? $company : $existing->company,
                    'message' => $message !== '' ? $message : $existing->message,
                    'intent' => $intent,
                    'requested_tenant_slug' => $normalizedSlug,
                    'metadata' => array_merge((array) ($existing->metadata ?? []), array_filter([
                        'business_type' => $businessType !== '' ? $businessType : null,
                        'team_size' => $teamSize !== '' ? $teamSize : null,
                        'timeline' => $timeline !== '' ? $timeline : null,
                        'website' => $website !== '' ? $website : null,
                        'preferred_plan_key' => $preferredPlanKey !== '' ? $preferredPlanKey : null,
                        'addons_interest' => $addonsInterest !== [] ? $addonsInterest : null,
                    ], static fn (mixed $value): bool => $value !== null)),
                ])->save();

                return $existing;
            }

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
                'requested_tenant_slug' => $normalizedSlug,
                'message' => $message !== '' ? $message : null,
                'metadata' => [
                    'requested_via' => 'platform',
                    'business_type' => $businessType !== '' ? $businessType : null,
                    'team_size' => $teamSize !== '' ? $teamSize : null,
                    'timeline' => $timeline !== '' ? $timeline : null,
                    'website' => $website !== '' ? $website : null,
                    'preferred_plan_key' => $preferredPlanKey !== '' ? $preferredPlanKey : null,
                    'addons_interest' => $addonsInterest !== [] ? $addonsInterest : null,
                ],
                'user_id' => (int) $user->id,
            ]);

            return $request;
        });
    }

    protected function findOpenRequest(string $email, ?string $normalizedSlug): ?CustomerAccessRequest
    {
        if (! \Schema::hasTable('customer_access_requests')) {
            return null;
        }

        $query = CustomerAccessRequest::query()
            ->where('email', $email)
            ->whereIn('status', ['pending', 'approved'])
            ->orderByDesc('id');

        if ($normalizedSlug !== null) {
            $query->where('requested_tenant_slug', $normalizedSlug);
        } else {
            $query->whereNull('requested_tenant_slug');
        }

        return $query->first();
    }

    protected function normalizeSlug(string $value): ?string
    {
        $slug = strtolower(trim($value));
        if ($slug === '') {
            return null;
        }

        $slug = preg_replace('/[^a-z0-9\\-]/', '-', $slug);
        $slug = trim((string) $slug, '-');

        return $slug !== '' ? $slug : null;
    }

    protected function isRejected(CustomerAccessRequest $request): bool
    {
        return (string) ($request->status ?? '') === 'rejected'
            || $request->rejected_at !== null
            || $request->rejected_by !== null;
    }
}
