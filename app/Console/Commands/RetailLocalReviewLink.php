<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Retail\RetailShowroomService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class RetailLocalReviewLink extends Command
{
    protected $signature = 'everbranch:retail-local-review';

    protected $description = 'Create a five-minute, single-use local review link for the synthetic showroom owner.';

    public function handle(RetailShowroomService $showroom): int
    {
        $owner = User::where('email', config('retail_showroom.owner_email'))->first();
        if (! app()->environment('local') || ! str_ends_with((string) $owner?->email, '.invalid') || ! $showroom->owner($owner)) {
            $this->error('Only a local synthetic showroom owner can use this command.');

            return self::FAILURE;
        }
        $token = Str::random(48);
        Cache::put('retail-local-review:'.hash('sha256', $token), $owner->id, now()->addMinutes(5));
        $this->line(URL::temporarySignedRoute('retail.local-review', now()->addMinutes(5), ['token' => $token]));

        return self::SUCCESS;
    }
}
