<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\StripeMessagingCreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MessagingCreditController extends Controller
{
    public function checkout(Request $request, StripeMessagingCreditService $service): RedirectResponse
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant && $request->user() instanceof User, 403);
        $validated = $request->validate(['pack_cents' => ['required', 'integer']]);
        $result = $service->createCheckout($tenant, $request->user(), (int) $validated['pack_cents']);

        return ($result['ok'] ?? false) && filled($result['url'] ?? null)
            ? redirect()->away((string) $result['url'])
            : back()->with('status_error', (string) ($result['message'] ?? 'Credit checkout could not be started.'));
    }
}
