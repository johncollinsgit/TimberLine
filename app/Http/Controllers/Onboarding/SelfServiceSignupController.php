<?php

namespace App\Http\Controllers\Onboarding;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\SelfServiceVerifyEmail;
use App\Services\Onboarding\BranchSelectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SelfServiceSignupController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    public function show(Request $request): Response|RedirectResponse
    {
        abort_unless(config('self_service.registration_enabled'), 404);
        if ($request->user()) {
            return redirect()->route('workspace.first-login');
        }

        return response()->view('onboarding.self-service-signup');
    }

    public function store(Request $request, BranchSelectionService $selection): RedirectResponse
    {
        abort_unless(config('self_service.registration_enabled'), 404);
        abort_if($request->user() !== null, 409, 'You are already signed in.');
        if (! $this->verificationDeliveryReady()) {
            return back()->with('status_error', 'Account setup is temporarily unavailable. Your template is saved; try again shortly.');
        }
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $input = $request->validate([
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'terms' => ['accepted'],
        ]);
        $branches = $selection->normalize((array) $request->session()->get(BranchSelectionService::SESSION_KEY, []), false);
        $user = User::query()->create([
            'name' => $input['name'], 'email' => $input['email'], 'password' => $input['password'],
            'role' => 'self_service_pending', 'is_active' => true,
            'requested_via' => 'self_service_registration',
            'onboarding_guide_answers' => [
                'branch_selection' => $branches,
                'business_template' => $request->session()->get(\App\Services\Onboarding\SelfServiceTemplateSelectionService::SESSION_KEY),
            ],
        ]);
        Auth::login($user);
        $request->session()->regenerate();
        try {
            $user->notify(new SelfServiceVerifyEmail);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('self-service.verify.notice')->with('status_error', 'We could not send your verification email. Your account is saved; try sending it again.');
        }

        return redirect()->route('self-service.verify.notice')->with('status', 'Check your email to continue.');
    }

    public function notice(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active, 403);
        if ($user->hasVerifiedEmail()) {
            return redirect()->route('workspace.first-login');
        }

        return response()->view('onboarding.self-service-verify');
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active, 403);
        if (! $this->verificationDeliveryReady()) {
            return back()->with('status_error', 'Email delivery is temporarily unavailable. Try again shortly.');
        }
        if (! $user->hasVerifiedEmail()) {
            try {
                $user->notify(new SelfServiceVerifyEmail);
            } catch (\Throwable $exception) {
                report($exception);

                return back()->with('status_error', 'Email could not be sent. Try again shortly.');
            }
        }

        return back()->with('status', 'Verification email sent.');
    }

    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active && (int) $user->id === $id, 403);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new \Illuminate\Auth\Events\Verified($user));
        }

        return redirect()->route('workspace.first-login')->with('status', 'Email verified. Create your workspace.');
    }

    private function verificationDeliveryReady(): bool
    {
        return ! app()->isProduction() || ! in_array((string) config('mail.default'), ['', 'log', 'array'], true);
    }
}
