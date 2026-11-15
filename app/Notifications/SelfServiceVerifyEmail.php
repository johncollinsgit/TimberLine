<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;

class SelfServiceVerifyEmail extends VerifyEmail
{
    // Reuse the canonical, signed confirmation flow without requiring a login.
}
