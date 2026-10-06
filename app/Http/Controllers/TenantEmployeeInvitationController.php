<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Models\TenantEmployeeInvitation;
use App\Models\User;
use App\Services\Tenancy\TenantEmployeeInvitationService;
use App\Support\Tenancy\TenantHostBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TenantEmployeeInvitationController extends Controller
{
    use PasswordValidationRules;

    public function show(Request $request): View|RedirectResponse
    {
        $token = $this->token($request);
        $invitation = $this->pendingInvitation($token);

        return view('employee-invitations.show', [
            'token' => $token,
            'invitation' => $invitation,
            'tenant' => $invitation?->tenant,
            'accountExists' => $invitation?->email && User::query()->where('email', $invitation->email)->exists(),
        ]);
    }

    public function register(Request $request, TenantEmployeeInvitationService $invitations, TenantHostBuilder $hosts): RedirectResponse
    {
        abort_if($request->user(), 403);
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ]);
        $user = $invitations->register($validated['token'], $validated['name'], $validated['password']);
        Auth::login($user);
        $request->session()->regenerate();
        $tenant = $user->tenants()->firstOrFail();
        $target = $hosts->urlForHostPath($hosts->hostForSlug((string) $tenant->slug), '/dashboard') ?: route('dashboard', absolute: false);

        return redirect()->to($target)->with('status', 'Your Everbranch account is ready. Sign in to Everbranch Field with this email and password.');
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
