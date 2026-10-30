<?php

namespace App\Services\CampusFacilities;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class Participants
{
    public function current(array $state, Request $request): ?array
    {
        $cookie = $request->cookie('lmc_facilities');
        if (! is_string($cookie) || ! preg_match('/^([a-f0-9-]{36})\.([a-f0-9]{64})$/D', $cookie, $parts)) {
            return null;
        }
        $participant = $state['participants'][$parts[1]] ?? null;

        return $participant && hash_equals($participant['capability'], hash('sha256', $parts[2])) ? $participant : null;
    }

    public function projection(array $participant): array
    {
        $email = Crypt::decryptString($participant['email']);
        [$user, $domain] = explode('@', $email, 2);

        return ['id' => $participant['id'], 'name' => $participant['name'], 'verified' => $participant['verified'], 'enabled' => $participant['enabled'], 'emailHint' => mb_substr($user, 0, 1).'•••@'.$domain];
    }

    public function directory(array $state, Request $request): array
    {
        $current = $this->current($state, $request);

        return ['people' => array_values(array_map($this->projection(...), array_filter($state['participants'], fn ($person) => $person['verified'] && $person['enabled']))), 'me' => $current ? $this->projection($current) : null];
    }

    public function register(DemoStore $store, Request $request): array
    {
        $data = Validator::make($request->json()->all(), ['name' => 'required|string|min:2|max:80', 'email' => 'required|email:rfc|max:254', 'enabled' => 'required|boolean'])->validate();
        abort_unless(app(DemoMail::class)->configured(), 503, 'Mention email is awaiting the Everbranch sender connection. You can still post comments.');
        $email = mb_strtolower(trim($data['email']));
        $secret = bin2hex(random_bytes(32));
        $token = bin2hex(random_bytes(32));
        $prepared = $store->access(function (&$state) use ($request, $data, $email, $secret, $token) {
            $current = $this->current($state, $request);
            abort_if(! $current && count($state['participants']) >= 100, 429, 'This demo has reached its participant limit.');
            if ($current && $current['verified'] && hash_equals(Crypt::decryptString($current['email']), $email)) {
                $current['name'] = trim($data['name']);
                $current['enabled'] = $data['enabled'];
                $state['participants'][$current['id']] = $current;

                return ['person' => $current, 'cookie' => null, 'skip' => true];
            }
            $day = now()->format('Y-m-d');
            $recipientHash = hash_hmac('sha256', $email, (string) config('app.key'));
            $last = $state['verification_requests'][$recipientHash] ?? 0;
            abort_if($last > time() - 600 || ($state['mail_usage'][$day] ?? 0) >= 50, 429, 'Please wait before requesting another confirmation email.');
            $id = $current['id'] ?? (string) Str::uuid();
            $same = $current && hash_equals(Crypt::decryptString($current['email']), $email);
            $person = ['id' => $id, 'name' => trim($data['name']), 'email' => Crypt::encryptString($email), 'capability' => $current['capability'] ?? hash('sha256', $secret), 'enabled' => $data['enabled'], 'verified' => $same && $current['verified'], 'verification' => hash('sha256', $token), 'expires' => time() + 1800];
            $state['participants'][$id] = $person;
            $state['verification_requests'][$recipientHash] = time();
            $state['mail_usage'][$day] = ($state['mail_usage'][$day] ?? 0) + 1;

            return ['person' => $person, 'cookie' => $current ? null : $id.'.'.$secret];
        });
        $person = $prepared['person'];
        if ($prepared['skip'] ?? false) {
            return ['me' => $this->projection($person), 'message' => 'Your email preferences were updated.', 'cookie' => null];
        }
        $link = 'https://'.config('tenancy.domains.canonical.landlord_host').'/platform/demos/lees-mcrae/verify/'.$person['id'].'/'.$token;
        $status = app(DemoMail::class)->deliver($email, 'Confirm your email for mentions', 'Hello '.$person['name'].",\n\nYou entered this address for the Lees-McRae Facilities demo. Confirm it to receive ticket comments when someone mentions you. If you did not request this, ignore this email. The link expires in 30 minutes.", $link, 'Confirm email');
        $store->access(function (&$state) use ($person, $status) {
            $state['participants'][$person['id']]['verification_status'] = $status;
        });

        return ['me' => $this->projection($person), 'message' => $status === 'accepted' ? 'Confirmation email accepted. Open its link, then refresh the mention menu.' : ($status === 'unknown' ? 'The email outcome is uncertain. Check your inbox before requesting another link.' : 'Your details were saved, but the confirmation email could not be accepted.'), 'cookie' => $prepared['cookie']];
    }

    public function verify(DemoStore $store, string $id, string $token, bool $apply): void
    {
        abort_unless(Str::isUuid($id) && preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        $store->access(function (&$state) use ($id, $token, $apply) {
            $person = $state['participants'][$id] ?? null;
            abort_unless($person && $person['expires'] >= time() && hash_equals($person['verification'], hash('sha256', $token)), 404, 'This confirmation link is unavailable or expired.');
            if ($apply) {
                $state['participants'][$id]['verified'] = true;
            }
        }, $apply);
    }
}
