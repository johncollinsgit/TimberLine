<?php

namespace App\Services\Watershed;

use App\Services\Soccer\SoccerStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class WatershedAuth
{
    public function __construct(private WatershedStore $store, private SoccerStore $shared) {}

    public function code(int $tenant, string $email): void
    {
        $email = strtolower(trim($email));
        abort_if($this->store->query('challenges', $tenant)->where('email', $email)->where('created_at', '>', now()->subHour())->count() >= 5, 429, 'Please wait before requesting another code.');
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->store->insert('challenges', $tenant, ['email' => $email, 'code_hash' => hash_hmac('sha256', $email.':'.$code, config('app.key')), 'expires_at' => now()->addMinutes(10)]);
        Mail::to($email)->send(new \App\Mail\WatershedMail('Your Watershed sign-in code', 'Use this code to sign in. It expires in ten minutes. If you did not request it, you can ignore this email.', $code));
    }

    public function verify(int $tenant, string $email, string $code): ?string
    {
        return DB::transaction(function () use ($tenant, $email, $code) {
            $email = strtolower(trim($email));
            $c = $this->store->query('challenges', $tenant)->where('email', $email)->whereNull('consumed_at')->where('expires_at', '>', now())->latest('created_at')->lockForUpdate()->first();
            if (! $c || $c->attempts >= 5) {
                return null;
            }
            if (! hash_equals($c->code_hash, hash_hmac('sha256', $email.':'.$code, config('app.key')))) {
                $this->store->query('challenges', $tenant)->where('id', $c->id)->increment('attempts');

                return null;
            }
            $this->store->query('challenges', $tenant)->where('id', $c->id)->update(['consumed_at' => now()]);
            $f = $this->shared->query('families', $tenant)->where('email', $email)->first();
            $f ??= $this->shared->query('families', $tenant)->get()->first(fn ($r) => isset($this->shared->decrypt($r->profile)['guardians'][$email]));
            $id = $f?->id ?? $this->shared->insert('families', $tenant, ['email' => $email, 'profile' => $this->shared->encrypt([]), 'verified_at' => now()]);
            $token = Str::random(64);
            $this->store->insert('sessions', $tenant, ['family_id' => $id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(30)]);

            return $token;
        }, 3);
    }

    public function family(int $tenant, ?string $token): object
    {
        abort_unless($token, 401);
        $s = $this->store->query('sessions', $tenant)->where('token_hash', hash('sha256', $token))->where('expires_at','>',now())->first();
        abort_unless($s,401);

        return $this->shared->row('families',$tenant,$s->family_id);
    }
}
