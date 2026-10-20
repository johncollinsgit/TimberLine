<?php

namespace App\Services\Soccer;

use App\Mail\SoccerSignInCodeMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SoccerFamilyService
{
    public function __construct(private SoccerStore $store) {}

    public function code(int $tenant, string $email, string $mode = 'new'): void
    {
        $email = strtolower(trim($email));
        $this->requireAlumniFamily($tenant, $email, $mode);
        abort_if($this->store->query('challenges', $tenant)->where('email', $email)->where('created_at', '>', now()->subHour())->count() >= 5, 429, 'Please wait before requesting another code.');
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->store->insert('challenges', $tenant, ['email' => $email, 'code_hash' => hash_hmac('sha256', $email.':'.$code, config('app.key')), 'expires_at' => now()->addMinutes(10)]);
        Mail::to($email)->send(new SoccerSignInCodeMail($code));
    }

    public function verify(int $tenant, string $email, string $code, string $mode = 'new'): ?string
    {
        return DB::transaction(function () use ($tenant, $email, $code, $mode) {
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
            $f = $this->findFamily($tenant, $email);
            abort_if($mode === 'existing' && ! $f, 422, self::ALUMNI_NOT_FOUND);
            $id = $f?->id ?? $this->store->insert('families', $tenant, ['email' => $email, 'profile' => $this->store->encrypt([])]);
            $this->store->query('families', $tenant)->where('id', $id)->update(['verified_at' => now(), 'updated_at' => now()]);
            $token = Str::random(64);
            $this->store->insert('sessions', $tenant, ['family_id' => $id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(30)]);

            return $token;
        }, 3);
    }

    private const ALUMNI_NOT_FOUND = 'We could not find a previous Wren family for this email. Check the address used for your 2026 registration or medical release, or choose New Family.';

    private function requireAlumniFamily(int $tenant, string $email, string $mode): void
    {
        abort_if($mode === 'existing' && ! $this->findFamily($tenant, $email), 422, self::ALUMNI_NOT_FOUND);
    }

    private function findFamily(int $tenant, string $email): ?object
    {
        $family = $this->store->query('families', $tenant)->where('email', $email)->first();

        return $family ?? $this->store->query('families', $tenant)->get()->first(fn ($item) => isset($this->store->decrypt($item->profile)['guardians'][$email]));
    }

    public function addGuardian(int $tenant, string $familyId, string $email, string $reason, int $actor): void
    {
        DB::transaction(function () use ($tenant, $familyId, $email, $reason, $actor) {
            $email = strtolower(trim($email));
            $family = $this->store->query('families', $tenant)->where('id', $familyId)->lockForUpdate()->firstOrFail();
            foreach ($this->store->query('families', $tenant)->get() as $other) {
                if ($other->id === $familyId) {
                    continue;
                }
                $profile = $this->store->decrypt($other->profile);
                if ($other->email === $email || isset($profile['guardians'][$email])) {
                    abort_if($this->store->query('players', $tenant)->where('family_id', $other->id)->exists(), 422, 'This email already belongs to a family with players. Review both families before changing access.');
                    unset($profile['guardians'][$email]);
                    $this->store->query('families', $tenant)->where('id', $other->id)->update(['email' => $other->email === $email ? null : $other->email, 'profile' => $this->store->encrypt($profile)]);
                    $this->store->query('sessions', $tenant)->where('family_id', $other->id)->delete();
                }
            }
            $profile = $this->store->decrypt($family->profile);
            $profile['guardians'][$email] = ['role' => 'guardian', 'source' => 'admin_verified', 'at' => now()->toIso8601String()];
            $this->store->query('families', $tenant)->where('id', $familyId)->update(['profile' => $this->store->encrypt($profile), 'updated_at' => now()]);
            app(SoccerEvents::class)->record($tenant, null, $familyId, 'guardian_access_verified', ['email' => $email, 'reason' => $reason], $actor);
        });
    }

    public function family(int $tenant, ?string $token): object
    {
        abort_unless($token, 401);
        $s = $this->store->query('sessions', $tenant)->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
        abort_unless($s, 401);

        return $this->store->row('families', $tenant, $s->family_id);
    }

    public function profile(int $tenant, object $family): array
    {
        $seasons = $this->store->query('seasons', $tenant)->orderByDesc('season_key')->get();
        $currentSeason = $seasons->firstWhere('season_key', 'spring-2027');

        return ['family' => ['id' => $family->id, 'email' => $family->email, 'profile' => $this->store->decrypt($family->profile), 'sms_consent' => (bool) $family->sms_consent_at],
            'players' => $this->store->query('players', $tenant)->where('family_id', $family->id)->orderBy('name')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'birthday' => $p->birthday, 'profile' => $this->store->decrypt($p->profile), 'eligibility' => $currentSeason ? app(SoccerEligibilityService::class)->assess($tenant, $p, $currentSeason) : null]),
            'seasons' => $seasons->map(fn ($s) => ['id' => $s->id, 'key' => $s->season_key, 'name' => $s->name, 'starts_on' => $s->starts_on, 'age_as_of' => $s->age_as_of ?? $s->starts_on, 'registration_opens_at' => $s->registration_opens_at, 'open' => (bool) $s->registration_open, 'teams' => app(SoccerRegistrationService::class)->capacities($s)]),
            'registrations' => $this->store->query('registrations', $tenant)->where('family_id', $family->id)->get(),
            'invoices' => $this->store->query('invoices', $tenant)->where('family_id', $family->id)->latest('created_at')->get()->map(fn ($i) => collect((array) $i)->except(['checkout_id', 'payment_intent_id', 'tenant_id'])->all()),
            'releases' => $this->store->query('releases', $tenant)->where('family_id', $family->id)->get()->map(fn ($r) => ['id' => $r->id, 'player_id' => $r->player_id, 'season_key' => $r->season_key, 'signed_at' => $r->signed_at, 'answers' => $this->store->decrypt($r->answers)]),
            'venmo' => config('soccer.venmo'), 'venmo_url' => config('soccer.venmo_url'),
            'paypal' => app(SoccerPayPalService::class)->publicReadiness($tenant, $family->id),
            'soccer_group_url' => $this->store->settings($tenant)['soccer_group_url'] ?? null];
    }

    public function saveProfile(int $tenant, object $family, array $data): void
    {
        $profile = $this->store->decrypt($family->profile);
        $normalizer = app(\App\Support\Marketing\MarketingIdentityNormalizer::class);
        if (! $normalizer->toE164($data['phone'] ?? '') || ! $normalizer->toE164($data['emergencyPhone'] ?? '') || empty($data['emergencyContact'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['phone' => 'Enter valid primary and emergency phone numbers and an emergency contact.']);
        }
        unset($data['email']);
        $consent = $data['sms_consent'] ?? false;
        unset($data['sms_consent']);
        $this->store->query('families', $tenant)->where('id', $family->id)->update(['profile' => $this->store->encrypt([...$profile, ...$data]), 'sms_consent_at' => $consent ? ($family->sms_consent_at ?? now()) : null, 'updated_at' => now()]);
    }

    public function player(int $tenant, object $family, array $data): string
    {
        $row = ['name' => $data['name'], 'birthday' => $data['birthday'], 'profile' => $this->store->encrypt($data['profile'] ?? []), 'updated_at' => now()];
        if (! empty($data['id'])) {
            $p = $this->store->row('players', $tenant, $data['id']);
            abort_unless($p->family_id === $family->id, 403);
            $this->store->query('players', $tenant)->where('id', $p->id)->update($row);

            return $p->id;
        }

        return $this->store->insert('players', $tenant, ['family_id' => $family->id, ...$row]);
    }

    public function release(int $tenant, object $family, array $data): string
    {
        return DB::transaction(fn () => $this->createRelease($tenant, $family, $data), 3);
    }

    private function createRelease(int $tenant, object $family, array $data): string
    {
        $p = $this->store->query('players', $tenant)->where('id', $data['player_id'])->lockForUpdate()->firstOrFail();
        abort_unless($p->family_id === $family->id, 403);
        $s = $this->store->row('seasons', $tenant, $data['season_id']);
        $a = $data['answers'];
        abort_unless(! empty($a['guardianSignature']) && ! empty($a['releaseSignature']) && ($data['medical_reviewed'] ?? false), 422, 'Review medical information and sign both permissions.');
        if ($p->birthday && $s->starts_on) {
            $a['child1Age'] = (string) (int) \Carbon\CarbonImmutable::parse($p->birthday)->diffInYears(\Carbon\CarbonImmutable::parse($s->age_as_of ?? $s->starts_on));
        }
        $a = [...$a, 'child1Name' => $p->name, 'child1Birthday' => $p->birthday, 'parentEmail' => $family->email, 'season' => $s->name, 'seasonStart' => $s->starts_on ?? ''];
        $a['motherName'] = ! empty($a['motherName']) ? $a['motherName'] : ($a['guardianName'] ?? $a['parentName'] ?? '');
        $a['motherPhone'] = ! empty($a['motherPhone']) ? $a['motherPhone'] : ($a['phone'] ?? '');
        $json = json_encode($a, JSON_THROW_ON_ERROR);
        $source = 'portal:'.$data['request_id'];
        $existing = $this->store->query('releases', $tenant)->where('source_key', $source)->first();
        if ($existing) {
            abort_unless($existing->family_id === $family->id && $existing->player_id === $p->id && $existing->sha256 === hash('sha256', $json), 409);

            return $existing->id;
        }

        $medical = array_intersect_key($a, array_flip(['child1Grade', 'child1Gender', 'allergies', 'allergenList', 'epipen', 'conditions', 'restrictions', 'comments', 'medical_note', 'insuranceCompany', 'policyNumber', 'physician', 'physicianPhone', 'dentist', 'dentistPhone', 'recentIllnesses', 'eyewear', 'tetanusDate']));
        $this->store->query('players', $tenant)->where('id', $p->id)->update(['profile' => $this->store->encrypt([...$this->store->decrypt($p->profile), ...$medical]), 'updated_at' => now()]);

        return $this->store->insert('releases', $tenant, ['family_id' => $family->id, 'player_id' => $p->id, 'season_key' => $s->season_key, 'source_key' => $source, 'form_version' => 'wren-season-release-v1', 'answers' => $this->store->encrypt($a), 'sha256' => hash('sha256', $json), 'signed_at' => now()]);
    }
}
