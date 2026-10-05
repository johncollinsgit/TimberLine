<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        // Fortify calls this action only after the password broker validates
        // the emailed reset token. For workspace-invited accounts, completing
        // that setup also proves access to the invited email address.
        $verifyInvitedEmail = $user->requested_via === 'workspace_invite'
            && ! $user->hasVerifiedEmail();
        $attributes = ['password' => $input['password']];
        if ($verifyInvitedEmail) {
            $attributes['email_verified_at'] = now();
        }

        $user->forceFill($attributes)->save();

        if ($verifyInvitedEmail && $user->wasChanged('email_verified_at')) {
            event(new Verified($user));
        }
    }
}
