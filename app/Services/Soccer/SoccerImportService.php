<?php

namespace App\Services\Soccer;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class SoccerImportService
{
    public function __construct(private SoccerStore $store) {}

    public function normalize(string $v): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($v))));
    }

    public function birthday(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        foreach (['!Y-m-d', '!n/j/Y', '!n/j/y', '!m/d/Y', '!m/d/y'] as $format) {
            try {
                $d = \DateTimeImmutable::createFromFormat($format, $raw);
                $errors = \DateTimeImmutable::getLastErrors();
                if ($d && (! $errors || ! $errors['warning_count'] && ! $errors['error_count']) && $d->format('Y') >= '1990' && $d->format('Y-m-d') < now()->format('Y-m-d')) {
                    return $d->format('Y-m-d');
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function family(int $tenant, string $email, array $profile): ?string
    {
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $normalizer = app(\App\Support\Marketing\MarketingIdentityNormalizer::class);
            $phone = $normalizer->toE164($profile['phone'] ?? '');
            $matches = $this->store->query('families', $tenant)->get()->filter(function ($candidate) use ($phone, $profile, $normalizer) {
                $prior = $this->store->decrypt($candidate->profile);

                return $phone && $normalizer->toE164($prior['phone'] ?? '') === $phone && $this->normalize($prior['parentName'] ?? '') === $this->normalize($profile['parentName'] ?? '') && ! empty($profile['parentName']);
            });

            return $matches->count() === 1 ? $matches->first()->id : null;
        }
        $f = $this->store->query('families', $tenant)->where('email', $email)->first();
        if (! $f) {
            return $this->store->insert('families', $tenant, ['email' => $email, 'profile' => $this->store->encrypt($profile)]);
        }
        $prior = $this->store->decrypt($f->profile);
        foreach ($profile as $k => $v) {
            if ($v !== '' && empty($prior[$k])) {
                $prior[$k] = $v;
            }
        }
        $this->store->query('families', $tenant)->where('id', $f->id)->update(['profile' => $this->store->encrypt($prior), 'updated_at' => now()]);

        return $f->id;
    }

    private function player(int $tenant, string $family, string $name, ?string $birthday, array $profile): array
    {
        $norm = $this->normalize($name);
        $candidates = $this->store->query('players', $tenant)->where('family_id', $family)->get()->filter(function ($p) use ($norm, $birthday) {
            $n = $this->normalize($p->name);

            return $n === $norm && (! $p->birthday || ! $birthday || $p->birthday === $birthday);
        });
        if ($candidates->count() > 1) {
            return [null, 'review'];
        }
        if ($p = $candidates->first()) {
            // Known birthday conflicts never silently merge into an existing player.
            if (! $p->birthday && $birthday) {
                $this->store->query('players', $tenant)->where('id', $p->id)->update(['birthday' => $birthday]);
            }

            $prior = $this->store->decrypt($p->profile);
            foreach ($profile as $field => $value) {
                if ($value !== '' && empty($prior[$field])) {
                    $prior[$field] = $value;
                }
            }
            $this->store->query('players', $tenant)->where('id', $p->id)->update(['profile' => $this->store->encrypt($prior), 'updated_at' => now()]);

            return [$p->id, 'matched'];
        }
        $possible = $this->store->query('players', $tenant)->get()->contains(function ($candidate) use ($family, $norm, $birthday) {
            $n = $this->normalize($candidate->name);
            $alias = $n === $norm || str_starts_with($n, $norm.' ') || str_starts_with($norm, $n.' ');

            return $alias && ($candidate->family_id === $family || ($birthday && $candidate->birthday === $birthday));
        });
        if ($possible) {
            return [null, 'review'];
        }

        return [$this->store->insert('players', $tenant, ['family_id' => $family, 'name' => $name, 'birthday' => $birthday, 'profile' => $this->store->encrypt($profile)]), 'created'];
    }

    public function releases(int $tenant, array $records): array
    {
        $counts = ['imported' => 0, 'replayed' => 0, 'review' => 0];
        foreach ($records as $r) {
            $key = 'd1:'.$r['id'];
            if ($this->store->query('releases', $tenant)->where('source_key', $key)->exists()) {
                $counts['replayed']++;

                continue;
            }
            $a = $r['answers'];
            $f = $this->family($tenant, $r['parentEmail'], ['parentName' => $a['guardianName'] ?? $a['motherName'] ?? '', 'phone' => $a['motherPhone'] ?? '', 'secondaryPhone' => $a['fatherPhone'] ?? '', 'address' => $a['address'] ?? '', 'city' => $a['city'] ?? '', 'state' => $a['state'] ?? '', 'zip' => $a['zip'] ?? '', 'motherName' => $a['motherName'] ?? '', 'motherPhone' => $a['motherPhone'] ?? '', 'fatherName' => $a['fatherName'] ?? '', 'fatherPhone' => $a['fatherPhone'] ?? '', 'pickupPerson' => $a['pickupPerson'] ?? '', 'pickupPhone' => $a['pickupPhone'] ?? '', 'emergencyContact' => $a['emergencyContact'] ?? '', 'emergencyPhone' => $a['emergencyPhone'] ?? '']);
            [$p,$status] = $f ? $this->player($tenant, $f, $r['playerName'], $this->birthday($a['child1Birthday'] ?? ''), $a) : [null, 'review'];
            $json = json_encode($a, JSON_THROW_ON_ERROR);
            $this->store->insert('releases', $tenant, ['family_id' => $f, 'player_id' => $p, 'season_key' => 'fall-2026', 'source_key' => $key, 'form_version' => 'fall-2026-original', 'answers' => $this->store->encrypt($a), 'sha256' => hash('sha256', $json), 'signed_at' => CarbonImmutable::createFromTimestampMs($r['submittedAt'])]);
            if (! $p) {
                $counts['review']++;
            }$counts['imported']++;
        }

        return $counts;
    }

    public function csv(int $tenant, string $path, string $season): array
    {
        $h = fopen($path, 'r');
        $cols = fgetcsv($h, escape: '');
        $cols[0] = ltrim($cols[0], "\xEF\xBB\xBF");
        $n = 1;
        $stats = ['created' => 0, 'matched' => 0, 'review' => 0, 'replayed' => 0];
        while (($values = fgetcsv($h, escape: '')) !== false) {
            $n++;
            $row = array_combine($cols, array_pad($values, count($cols), ''));
            $fingerprint = hash('sha256', $season.'|'.json_encode($row, JSON_THROW_ON_ERROR));
            if ($this->store->query('import_rows', $tenant)->where('fingerprint', $fingerprint)->exists()) {
                $stats['replayed']++;

                continue;
            }
            $get = function (string $prefix) use ($row) {
                foreach ($row as $key => $value) {
                    if (str_starts_with(trim($key), $prefix)) {
                        return trim($value ?? '');
                    }
                }

                return '';
            };
            $email = $get('Email Address');
            $name = $get('Child Name');
            $surname = $get('Child Last Name');
            if ($surname && ! str_ends_with($this->normalize($name), $this->normalize($surname))) {
                $name .= ' '.$surname;
            }
            $birthday = $this->birthday($get('Child - Birthday'));
            $f = $this->family($tenant, $email, ['parentName' => $get('Parent Name'), 'phone' => $get('Parent Phone'), 'secondaryPhone' => $get('Secondary Parent'), 'address' => $get('Address')]);
            [$p,$status] = $f ? $this->player($tenant, $f, $name, $birthday, ['gender' => $get('Child  - Gender'), 'experience' => $get('Child 1 - Experience'), 'jersey_size' => $get('Child  - Shirt Size'), 'medical_note' => $get('Anything we need'), 'volunteer' => $get('Parent involvement'), 'child1Gender' => $get('Child  - Gender'), 'comments' => $get('Anything we need')]) : [null, 'review'];
            if (! $birthday) {
                $status = 'review';
            }
            $this->store->insert('import_rows', $tenant, ['source' => str_contains($season, 'spring') ? 'spring-registration-csv' : 'fall-registration-csv', 'season_key' => $season, 'row_number' => $n, 'fingerprint' => $fingerprint, 'status' => $status, 'family_id' => $f, 'player_id' => $p, 'evidence' => $this->store->encrypt(['row' => $row, 'birthday' => $birthday, 'payment_verified' => false, 'reason' => $status === 'review' ? 'Missing identity/birthday or conflicting player match' : null])]);
            if ($p && $status !== 'review') {
                $this->historical($tenant, $season, $f, $p, $fingerprint, $get('Age') ?: $get('Column 19'), $get('Child  - Shirt Size'));
            }
            $stats[$status]++;
        }fclose($h);

        return $stats;
    }

    public function historical(int $tenant, string $key, string $family, string $player, string $source, string $age, string $size): void
    {
        $season = $this->store->query('seasons', $tenant)->where('season_key', $key)->firstOrFail();
        if ($this->store->query('registrations', $tenant)->where('season_id', $season->id)->where('player_id', $player)->exists()) {
            return;
        }
        $team = null;
        if (is_numeric($age)) {
            foreach (config('soccer.teams') as $k => $t) {
                if ((int) $age >= $t['min_age'] && ($t['max_age'] === null || (int) $age <= $t['max_age'])) {
                    $team = $k;
                    break;
                }
            }
        }
        $release = $this->store->query('releases', $tenant)->where('player_id', $player)->where('season_key', $key)->latest('signed_at')->value('id');
        $this->store->insert('registrations', $tenant, ['season_id' => $season->id, 'family_id' => $family, 'player_id' => $player, 'release_id' => $release, 'team_key' => $team ?? 'unassigned', 'status' => 'historical', 'seat_kind' => null, 'auto_eligible' => false, 'needs_jersey' => $size !== '', 'jersey_size' => $size ?: null, 'waived' => false, 'amount_cents' => 0, 'payment_status' => 'unverified', 'queued_at' => now()]);
    }
}
