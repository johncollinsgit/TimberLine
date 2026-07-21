<?php

namespace App\Http\Controllers;

use App\Models\TenantEmployeeInvitation;
use App\Models\User;
use App\Services\Tenancy\TenantEmployeeInvitationService;
use App\Support\Tenancy\TenantHostBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantEmployeeInvitationController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $token = $this->token($request);
        if (! $request->user()) {
            return redirect()->guest(route('login'));
        }

        $invitation = $this->pendingInvitation($token);

        return view('employee-invitations.show', [
            'token' => $token,
            'invitation' => $invitation,
            'tenant' => $invitation?->tenant,
        ]);
    }

    public function accept(Request $request, TenantEmployeeInvitationService $invitations, TenantHostBuilder $hosts): RedirectResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active !== false, 401);
        $tenant = $invitations->accept($user, $validated['token']);
        $target = $hosts->urlForHostPath($hosts->hostForSlug((string) $tenant->slug), '/dashboard') ?: route('dashboard', absolute: false);

        return redirect()->to($target)->with('status', 'You joined '.$tenant->name.'.');
    }

    protected function token(Request $request): string
    {
        return $request->validate(['token' => ['required', 'string', 'size:64']])['token'];
    }

    protected function pendingInvitation(string $token): ?TenantEmployeeInvitation
    {
        return TenantEmployeeInvitation::query()
            ->with('tenant')
            ->where('token_hash', hash('sha256', $token))
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();
    }
}
