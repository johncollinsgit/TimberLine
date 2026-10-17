<?php

namespace App\Services\Retail;

use App\Mail\RetailSignupConfirmationMail;
use App\Models\CustomerAccessRequest;
use App\Models\LandlordProspect;
use App\Models\User;
use App\Services\Onboarding\CustomerAccessRequestService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RetailSignupService
{
    public function submit(User $owner, array $input): array
    {
        $email = strtolower(trim($input['email']));
        $company = preg_replace('/\s+/u', ' ', trim($input['business_name']));
        $offer = app(EvergroveOffer::class)->presentation();
        $request = DB::transaction(function () use ($owner, $input, $email, $company, $offer) {
            // Serialize first inserts as well as retries across all showroom workers.
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $existing = CustomerAccessRequest::platformAccess()->where('email', $email)
                ->whereNull('requested_tenant_slug')->whereIn('status', ['pending', 'approved'])->latest('id')->first();
            if ($existing && (strtolower(trim((string) $existing->company)) !== strtolower($company) || $existing->status !== 'pending')) {
                throw ValidationException::withMessages(['email' => 'An existing access request needs an operator review. No new registration or access was created.']);
            }
            if ($existing && data_get($existing->metadata, 'retail_signup.reference')) {
                if (data_get($existing->metadata, 'retail_signup.offer.quote_version') !== $offer['quote_version']) {
                    throw ValidationException::withMessages(['subscription' => 'Your earlier offer request needs an operator review before pricing can change.']);
                }

                return $existing;
            }
            $request = app(CustomerAccessRequestService::class)->submit([
                'intent' => 'production', 'application_kind' => CustomerAccessRequest::KIND_PLATFORM_ACCESS,
                'name' => trim($input['contact_name']), 'email' => $email, 'company' => $company,
                'phone' => trim($input['phone']), 'business_type' => 'retail', 'agreement' => true,
                // A blank destination prevents binding a new customer to any demo tenant.
                'requested_tenant_slug' => '', 'message' => 'Retail Launch Partner activation request; eligibility and written scope require review.',
            ]);
            $snapshot = ['reference' => (string) Str::uuid(), 'offer' => $offer, 'status' => 'pending_review',
                'confirmation_status' => 'pending', 'accepted_at' => now()->toIso8601String(), 'slot_reserved' => false];
            $request->forceFill(['metadata' => [...($request->metadata ?? []), 'retail_signup' => $snapshot]])->save();
            $prospect = LandlordProspect::whereRaw('lower(business_name) = ?', [strtolower($company)])->lockForUpdate()->first();
            $prospect ??= LandlordProspect::create(['business_name' => $company, 'contact_name' => trim($input['contact_name']),
                'email' => $email, 'phone' => trim($input['phone']), 'trade' => 'Retail', 'county' => 'Not supplied',
                'source' => 'retail-sales-demo', 'status' => 'replied', 'created_by_user_id' => $owner->id]);
            $prospect->forceFill(['source_snapshot' => [...($prospect->source_snapshot ?? []),
                'retail_signup' => ['request_id' => $request->id, 'reference' => $snapshot['reference'], 'offer_key' => $offer['key'], 'status' => 'pending_review']]])->save();
            app(LandlordOperatorActionAuditService::class)->record(tenantId: null, actorUserId: $owner->id,
                actionType: 'retail_signup_requested', targetType: 'customer_access_request', targetId: $request->id,
                result: ['offer_key' => $offer['key'], 'billing_impact' => 'none', 'tenant_created' => false]);

            return $request;
        });
        $this->confirm($request);
        $saved = data_get($request->fresh()->metadata, 'retail_signup');

        return ['status' => 'pending_review', 'reference' => $saved['reference'], 'subscription_active' => false,
            'slot_reserved' => false, 'confirmation_status' => $saved['confirmation_status']];
    }

    private function confirm(CustomerAccessRequest $request): void
    {
        if (! config('retail_showroom.signup_confirmation_enabled') || in_array(config('mail.default'), ['array', 'log', null], true)) {
            return;
        }
        $claimed = DB::transaction(function () use ($request) {
            $row = CustomerAccessRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $metadata = $row->metadata;
            if (data_get($metadata, 'retail_signup.confirmation_status') !== 'pending') {
                return false;
            }
            $metadata['retail_signup']['confirmation_status'] = 'sending';
            $row->metadata = $metadata;
            $row->save();

            return true;
        });
        if (! $claimed) {
            return;
        }
        $status = 'submitted';
        try {
            $snapshot = data_get($request->metadata, 'retail_signup');
            Mail::to($request->email)->send(new RetailSignupConfirmationMail($request->company, $snapshot['reference'], $snapshot['offer']));
        } catch (Throwable $exception) {
            $status = 'failed';
            report($exception);
        }
        DB::transaction(function () use ($request, $status) {
            $row = CustomerAccessRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $metadata = $row->metadata;
            $metadata['retail_signup']['confirmation_status'] = $status;
            $row->metadata = $metadata;
            $row->save();
        });
    }
}
