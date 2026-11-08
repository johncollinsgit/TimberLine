<?php

namespace App\Http\Controllers;

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\Marketing\CustomerPreferenceService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerStaffPreferenceController extends Controller
{
    public function __invoke(Request $request, CustomerPreferenceService $preferences, CanonicalMarketingProfileResolver $identities): JsonResponse|RedirectResponse
    {
        $tenant = $request->attributes->get('current_tenant');
        $actor = $request->user();
        abort_unless($tenant instanceof Tenant && $actor instanceof User
            && app(TenantModuleAccessResolver::class)->canAccess((int) $tenant->id, 'customers'), 403);
        $membership = $actor->tenants()->whereKey($tenant->id)->wherePivot('membership_active', true)->first();
        abort_unless($actor->is_active && $actor->email_verified_at && $membership
            && in_array(strtolower((string) $membership->pivot->role), ['owner', 'tenant_owner', 'admin', 'manager'], true), 403);
        $id = $request->route('profile');
        $id = $id instanceof MarketingProfile ? $id->id : $id;
        abort_unless(is_numeric($id) && (int) $id > 0, 404);
        $profile = $identities->byId((int) $tenant->id, (int) $id);
        abort_unless($profile && $profile->archived_at === null, 404);

        try {
            $command = $request->only(['request_id', 'expected_version', 'customer_confirmed', 'changes']);
            if (! $request->expectsJson()) {
                $command['changes'] = is_array($command['changes'] ?? null) ? $command['changes'] : [];
                foreach (['favorite_scents', 'avoided_scents'] as $field) {
                    unset($command['changes'][$field]);
                    if ($request->boolean('apply_'.$field)) {
                        $text = $request->validate([$field.'_text' => ['present', 'nullable', 'string', 'max:3000']])[$field.'_text'];
                        $command['changes'][$field] = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) $text) ?: []), fn ($value) => $value !== ''));
                    }
                }
                if (! $request->boolean('apply_contact_names')) {
                    unset($command['changes']['first_name'], $command['changes']['last_name']);
                }
                foreach (['email', 'sms'] as $channel) {
                    unset($command['changes'][$channel.'_marketing']);
                    if ($request->boolean('revoke_'.$channel.'_marketing')) {
                        $command['changes'][$channel.'_marketing'] = false;
                    }
                }
            }
            $saved = $preferences->updateForStaff($profile, (int) $tenant->id, $actor, $command);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422)->header('Cache-Control', 'private, no-store');
            }

            return $this->profileRedirect($profile, $tenant)->withInput($request->except('_token'))->withErrors($exception->errors(), 'customerPreferences');
        } catch (HttpException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], $exception->getStatusCode())->header('Cache-Control', 'private, no-store');
            }
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return $this->profileRedirect($profile, $tenant)->withInput($request->except('_token'))->withErrors(['preferences' => $exception->getMessage()], 'customerPreferences');
        }
        if ($request->expectsJson()) {
            return response()->json(['preferences' => $saved])->header('Cache-Control', 'private, no-store');
        }

        return $this->profileRedirect($profile, $tenant)->with('toast', ['style' => 'success', 'message' => 'Customer choices saved. No message sent.']);
    }

    private function profileRedirect(MarketingProfile $profile, Tenant $tenant): RedirectResponse
    {
        return redirect()->route('customers.profile', ['profile' => $profile->id, 'tenant' => $tenant->slug])->header('Cache-Control', 'private, no-store');
    }
}
