<?php

namespace App\Services\Watershed;

use App\Services\Soccer\SoccerStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WatershedRegistration
{
    public const MEDICAL = ['child1Gender', 'allergies', 'allergenList', 'epipen', 'conditions', 'restrictions', 'comments', 'medical_note', 'insuranceCompany', 'policyNumber', 'physician', 'physicianPhone', 'dentist', 'dentistPhone', 'recentIllnesses', 'eyewear', 'tetanusDate'];

    public const CONTACT = ['parentName', 'phone', 'spouseName', 'spousePhone', 'address', 'city', 'state', 'zip', 'motherName', 'motherPhone', 'fatherName', 'fatherPhone', 'pickupPerson', 'pickupPhone', 'emergencyContact', 'emergencyPhone'];

    public function __construct(private WatershedStore $store, private SoccerStore $shared, private WatershedPlacement $placement) {}

    public function term(int $tenant): object
    {
        return $this->store->query('terms', $tenant)->orderByDesc('school_year')->orderByDesc('term_key')->firstOrFail();
    }

    public function profile(int $tenant, object $family): array
    {
        $term = $this->term($tenant);
        $state = $this->store->query('family_states', $tenant)->where('family_id', $family->id)->first();
        $apps = $this->store->query('applications', $tenant)->where('family_id', $family->id)->latest('created_at')->get();
        $players = $this->shared->query('players', $tenant)->where('family_id', $family->id)->orderBy('name')->get()->map(function ($p) use ($tenant, $term) {
            $assessment = $this->placement->assess($p->birthday, $term->school_year);
            $adjustment = $this->store->query('adjustments', $tenant)->where('player_id', $p->id)->where('school_year', $term->school_year)->first();
            $latest = $this->store->query('releases', $tenant)->where('player_id', $p->id)->latest('signed_at')->first();
            $wren = $this->shared->query('releases', $tenant)->where('player_id', $p->id)->where('family_id', $p->family_id)->whereNotNull('signed_at')->latest('signed_at')->first();
            $snapshots = collect([$latest, $wren])->filter()->sortBy('signed_at');
            $medical = [];
            foreach ($snapshots as $snapshot) {
                $medical = [...$medical, ...$this->store->decrypt($snapshot->answers)];
            }

            return ['id' => $p->id, 'name' => $p->name, 'birthday' => $p->birthday, 'assessment' => $assessment, 'adjustment' => $adjustment ? $this->store->decrypt($adjustment->payload) : null, 'medical' => array_intersect_key([...$medical, ...$this->shared->decrypt($p->profile)], array_flip(self::MEDICAL))];
        });

        return ['family' => ['id' => $family->id, 'email' => $family->email, 'profile' => array_intersect_key($this->shared->decrypt($family->profile), array_flip(self::CONTACT)), 'preapproved' => (bool) $state?->preapproved],
            'term' => ['id' => $term->id, 'name' => $term->name, 'school_year' => $term->school_year, 'cutoff' => $term->school_year.'-09-01', 'maximum' => $this->placement->maximum($term->school_year), 'open' => (bool) $term->registration_open],
            'children' => $players, 'applications' => $apps->map(function ($a) use ($tenant) {
                $invoice = $this->store->query('invoices', $tenant)->where('application_id', $a->id)->first();

                return ['id' => $a->id, 'term_id' => $a->term_id, 'status' => $a->status, 'version' => $a->version, 'amount_cents' => $a->amount_cents, 'payload' => $this->store->decrypt($a->payload), 'invoice' => $invoice ? collect((array) $invoice)->only(['id', 'status', 'amount_cents', 'paid_at'])->all() : null, 'releases' => $this->store->query('releases', $tenant)->where('application_id', $a->id)->where('application_version', $this->store->decrypt($a->payload)['release_version'])->get(['id', 'player_id', 'signed_at'])];
            })];
    }

    public function contact(int $tenant, object $family, array $data): void
    {
        $normalizer = app(\App\Support\Marketing\MarketingIdentityNormalizer::class);
        abort_unless($normalizer->toE164($data['phone'] ?? '') && $normalizer->toE164($data['emergencyPhone'] ?? '') && filled($data['emergencyContact'] ?? null), 422, 'Enter a valid primary phone and emergency contact with a phone number.');
        DB::transaction(function () use ($tenant, $family, $data) {
            $f = $this->shared->query('families', $tenant)->where('id', $family->id)->lockForUpdate()->firstOrFail();
            $this->shared->query('families', $tenant)->where('id', $f->id)->update(['profile' => $this->shared->encrypt([...$this->shared->decrypt($f->profile), ...array_intersect_key($data, array_flip(self::CONTACT))]), 'updated_at' => now()]);
        });
    }

    public function child(int $tenant, object $family, array $data): string
    {
        return DB::transaction(function () use ($tenant, $family, $data) {
            $this->shared->query('families', $tenant)->where('id', $family->id)->lockForUpdate()->firstOrFail();
            if (! empty($data['id'])) {
                $p = $this->shared->row('players', $tenant, $data['id']);
                abort_unless($p->family_id === $family->id, 403);
                if ($p->birthday !== $data['birthday']) {
                    abort_if($this->shared->query('registrations', $tenant)->where('player_id', $p->id)->whereNotIn('status', ['cancelled', 'withdrawn'])->exists() || $this->store->query('releases', $tenant)->where('player_id', $p->id)->exists(), 422, 'Please ask leadership to review this birthday correction with the existing placements.');
                }
                $id = $p->id;
                $this->shared->query('players', $tenant)->where('id', $id)->update(['name' => $data['name'], 'birthday' => $data['birthday'], 'updated_at' => now()]);
            } else {
                $existing = $this->shared->query('players', $tenant)->where('family_id', $family->id)->where('name', $data['name'])->where('birthday', $data['birthday'])->first();
                $id = $existing?->id ?? $this->shared->insert('players', $tenant, ['family_id' => $family->id, 'name' => $data['name'], 'birthday' => $data['birthday'], 'profile' => $this->shared->encrypt([])]);
            }

            return $id;
        }, 3);
    }

    public function submit(int $tenant, object $family, array $data): string
    {
        return DB::transaction(function () use ($tenant, $family, $data) {
            $f = $this->shared->query('families', $tenant)->where('id', $family->id)->lockForUpdate()->firstOrFail();
            $term = $this->store->row('terms', $tenant, $data['term_id']);
            abort_unless($term->registration_open, 422, 'Registration is currently closed. Your information is saved.');
            $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $app = $this->store->query('applications', $tenant)->where('term_id', $term->id)->where('family_id', $family->id)->lockForUpdate()->first();
            if ($app && $app->request_id === $data['request_id']) {
                abort_unless(($this->store->decrypt($app->payload)['submission_hash'] ?? '') === $fingerprint, 409, 'This submission changed. Please reload and review it.');

                return $app->id;
            }
            abort_unless(($app?->version ?? 0) === $data['expected_version'], 409, 'This application changed in another session. Reload it before continuing.');
            abort_if($app && ! in_array($app->status, ['pending', 'needs_parent_review'], true), 422, 'This application has already been decided. Please contact leadership about changes.');
            $contact = array_intersect_key($this->shared->decrypt($f->profile), array_flip(self::CONTACT));
            abort_unless(filled($contact['parentName'] ?? null) && filled($contact['phone'] ?? null) && filled($contact['emergencyContact'] ?? null) && filled($contact['emergencyPhone'] ?? null), 422, 'Complete your household and emergency contact information first.');
            $children = [];
            $needsReview = false;
            $ids = [];
            foreach ($data['children'] as $child) {
                abort_if(in_array($child['player_id'], $ids, true), 422, 'Choose each child once.');
                $ids[] = $child['player_id'];
                $p = $this->shared->query('players', $tenant)->where('id', $child['player_id'])->lockForUpdate()->firstOrFail();
                abort_unless($p->family_id === $family->id, 403);
                $a = $this->placement->assess($p->birthday, $term->school_year);
                abort_unless($a['eligible'], 422, $a['reason']);
                $grade = (int) $child['grade'];
                $class = $child['class'];
                abort_unless($grade >= -2 && $grade <= $a['maximum'], 422, 'Choose a grade within this school year’s range.');
                abort_unless(isset(WatershedPlacement::CLASSES[$class]), 422, 'Choose a valid class.');
                $changed = $grade !== $a['grade'] || $class !== $a['class'];
                $prior = $this->store->query('adjustments', $tenant)->where('player_id', $p->id)->where('school_year', $term->school_year)->first();
                $prior = $prior ? $this->store->decrypt($prior->payload) : [];
                $approved = $changed && ($prior['birthday'] ?? null) === $p->birthday && ($prior['grade'] ?? null) === $grade && ($prior['class'] ?? null) === $class;
                abort_if($changed && ! $approved && ! filled($child['reason'] ?? null), 422, 'Provide a written reason for each placement request so leadership can consider your child’s safety.');
                abort_unless(in_array($child['medical']['allergies'] ?? '', ['yes', 'no'], true) && in_array($child['medical']['epipen'] ?? '', ['yes', 'no'], true), 422, 'Review the allergy and EpiPen questions for every child.');
                abort_if(($child['medical']['allergies'] ?? '') === 'yes' && ! filled($child['medical']['allergenList'] ?? ''), 422, 'List your child’s allergens and reaction details.');
                $needsReview = $needsReview || ($changed && ! $approved);
                $children[] = ['player_id' => $p->id, 'name' => $p->name, 'birthday' => $p->birthday, 'suggested_grade' => $a['grade'], 'suggested_class' => $a['class'], 'grade' => $grade, 'class' => $class, 'reason' => $child['reason'] ?? '', 'exception' => $changed, 'placement_status' => $changed && ! $approved ? 'pending' : 'approved', 'medical' => array_intersect_key($child['medical'], array_flip(self::MEDICAL))];
            }
            $preapproved = (bool) $this->store->query('family_states', $tenant)->where('family_id', $family->id)->value('preapproved');
            $id = $app?->id ?? (string) Str::uuid();
            $version = ($app?->version ?? 0) + 1;
            $payload = ['children' => $children, 'contact' => $contact, 'term_name' => $term->name, 'school_year' => $term->school_year, 'teaching_acknowledged' => true, 'teaching_text' => 'Our family must be prepared to provide at least one parent to teach at least once each school year. Participation is subject to Watershed leadership approval.', 'guardianSignature' => $data['guardianSignature'], 'releaseSignature' => $data['releaseSignature'], 'submission_hash' => $fingerprint, 'release_version' => $version];
            $values = ['status' => 'pending', 'version' => $version, 'request_id' => $data['request_id'], 'payload' => $this->store->encrypt($payload), 'updated_at' => now()];
            if ($app) {
                $this->store->query('applications', $tenant)->where('id', $id)->update($values);
            } else {
                $this->store->insert('applications', $tenant, ['id' => $id, 'term_id' => $term->id, 'family_id' => $family->id, ...$values]);
            }
            foreach ($children as $child) {
                $answers = [...array_intersect_key($contact, array_flip(self::CONTACT)), ...$child['medical'], 'child1Name' => $child['name'], 'child1Birthday' => $child['birthday'], 'child1Age' => (string) $this->placement->assess($child['birthday'], $term->school_year)['age'], 'child1Grade' => (string) $child['grade'], 'requestedClass' => WatershedPlacement::CLASSES[$child['class']], 'guardianSignature' => $data['guardianSignature'], 'releaseSignature' => $data['releaseSignature'], 'parentEmail' => $family->email, 'season' => $term->name, 'medical_reviewed' => true, 'guardian_permission' => true, 'medical_release_permission' => true, 'teaching_acknowledgement' => $payload['teaching_text'], 'permission_text' => config('watershed.permission_text'), 'medical_release_text' => config('watershed.medical_release_text')];
                $this->store->insert('releases', $tenant, ['application_id' => $id, 'player_id' => $child['player_id'], 'application_version' => $version, 'answers' => $this->store->encrypt($answers), 'sha256' => hash('sha256', json_encode($answers, JSON_THROW_ON_ERROR)), 'form_version' => 'watershed-semester-v1', 'signed_at' => now()]);
                $p = $this->shared->row('players', $tenant, $child['player_id']);
                $this->shared->query('players', $tenant)->where('id', $p->id)->update(['profile' => $this->shared->encrypt([...$this->shared->decrypt($p->profile), ...$child['medical']]), 'updated_at' => now()]);
            }
            $this->store->event($tenant, 'application_submitted', ['application_id' => $id, 'version' => $version], key: 'submitted:'.$id.':'.$version);
            $this->notify($tenant, $family->email, 'Application received', 'Your '.$term->name.' application has been received. '.($needsReview ? 'Leadership will review your placement request before payment is available.' : ($preapproved ? 'Your preapproval is being applied.' : 'Watershed leadership will review your family’s application.')), 'received:'.$id.':'.$version);
            $this->leaders($tenant, 'Watershed application received', 'A family submitted a '.$term->name.' application. Review it in your private leadership workspace.', 'submitted:'.$id.':'.$version);
            if ($preapproved && ! $needsReview) {
                $this->accept($tenant, $id, null);
            }

            return $id;
        }, 3);
    }

    public function accept(int $tenant, string $id, ?int $actor): void
    {
        $app = $this->store->query('applications', $tenant)->where('id', $id)->lockForUpdate()->firstOrFail();
        abort_unless($app->status === 'pending', 409, 'This application has already changed.');
        $payload = $this->store->decrypt($app->payload);
        abort_if(collect($payload['children'])->contains(fn ($c) => $c['placement_status'] !== 'approved'), 422, 'Resolve every placement request before accepting the family.');
        $amount = collect($payload['children'])->sum(fn ($c) => $c['class'] === 'nursery' ? 0 : 7500);
        $this->store->query('applications', $tenant)->where('id', $id)->update(['status' => $amount ? 'accepted' : 'registered', 'amount_cents' => $amount, 'version' => $app->version + 1, 'updated_at' => now()]);
        if ($amount) {
            $this->store->insert('invoices', $tenant, ['application_id' => $id, 'amount_cents' => $amount]);
        }
        $this->store->event($tenant, 'family_accepted', ['application_id' => $id, 'amount_cents' => $amount], $actor, 'accepted:'.$id);
        $family = $this->shared->row('families', $tenant, $app->family_id);
        $this->notify($tenant, $family->email, $amount ? 'Your Watershed family is accepted' : 'Your Watershed registration is complete', $amount ? 'Your family has been accepted. Review the approved classes and pay $'.number_format($amount / 100, 2).' in your account to complete registration.' : 'Your Nursery registration is complete. No payment is due.', 'accepted:'.$id);
    }

    public function decision(int $tenant, string $id, int $version, string $action, ?string $player, ?string $reason, int $actor): void
    {
        DB::transaction(function () use ($tenant, $id, $version, $action, $player, $reason, $actor) {
            $a = $this->store->query('applications', $tenant)->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($a->version === $version && $a->status === 'pending', 409, 'This application changed. Reload before deciding.');
            if ($action === 'accept') {
                $this->accept($tenant, $id, $actor);

                return;
            }
            $payload = $this->store->decrypt($a->payload);
            $status = 'pending';
            if ($action === 'decline') {
                $status = 'declined';
            } elseif (in_array($action, ['approve-placement', 'decline-placement'], true)) {
                $found = false;
                foreach ($payload['children'] as &$child) {
                    if ($child['player_id'] !== $player) {
                        continue;
                    }$found = true;
                    abort_unless($child['placement_status'] === 'pending', 409);
                    $child['placement_status'] = $action === 'approve-placement' ? 'approved' : 'declined';
                    $child['leadership_note'] = $reason;
                    if ($action === 'approve-placement') {
                        $existing = $this->store->query('adjustments', $tenant)->where('player_id', $player)->where('school_year', $payload['school_year'])->first();
                        $v = ['payload' => $this->store->encrypt(['grade' => $child['grade'], 'class' => $child['class'], 'birthday' => $child['birthday'], 'reason' => $child['reason'], 'actor' => $actor]), 'updated_at' => now()];
                        if ($existing) {
                            $this->store->query('adjustments', $tenant)->where('id', $existing->id)->update($v);
                        } else {
                            $this->store->insert('adjustments', $tenant, ['player_id' => $player, 'school_year' => $payload['school_year'], ...$v]);
                        }
                    } else {
                        $status = 'needs_parent_review';
                    }
                }unset($child);
                abort_unless($found, 404);
            } else {
                abort(422);
            }
            $payload['leadership_note'] = $reason;
            $this->store->query('applications', $tenant)->where('id', $id)->update(['status' => $status, 'payload' => $this->store->encrypt($payload), 'version' => $a->version + 1, 'updated_at' => now()]);
            $this->store->event($tenant, $action, ['application_id' => $id, 'player_id' => $player, 'reason' => $reason], $actor);
            $family = $this->shared->row('families', $tenant, $a->family_id);
            $text = $status === 'declined' ? 'Leadership has reviewed your application and is unable to accept it this semester. Please contact us with any questions.' : ($status === 'needs_parent_review' ? 'Leadership has reviewed your placement request. Please sign in to review the suggested placement and fee before continuing.' : 'Leadership has approved your placement request. Sign in to see the decision. Final family acceptance is a separate step.');
            $this->notify($tenant, $family->email, 'Watershed application update', $text, 'decision:'.$id.':'.($a->version + 1));
        }, 3);
    }

    public function preapprove(int $tenant, string $family, bool $approved, int $actor): void
    {
        $this->shared->row('families', $tenant, $family);
        $s = $this->store->query('family_states', $tenant)->where('family_id', $family)->first();
        if ($s) {
            $this->store->query('family_states', $tenant)->where('id', $s->id)->update(['preapproved' => $approved, 'updated_at' => now()]);
        } else {
            $this->store->insert('family_states', $tenant, ['family_id' => $family, 'preapproved' => $approved]);
        }
        $this->store->event($tenant, 'preapproval_changed', ['family_id' => $family, 'preapproved' => $approved], $actor);
    }

    public function notify(int $tenant, ?string $email, string $title, string $body, string $key, bool $leader = false): void
    {
        if (! $email) {
            return;
        }$key = hash('sha256', $key.':'.strtolower($email));
        if ($this->store->query('deliveries', $tenant)->where('delivery_key', $key)->exists()) {
            return;
        }
        $this->store->insert('deliveries', $tenant, ['delivery_key' => $key, 'payload' => $this->store->encrypt(['email' => $email, 'title' => $title, 'body' => $body, 'leader' => $leader])]);
    }

    public function leaders(int $tenant, string $title, string $body, string $key): void
    {
        foreach (config('watershed.leaders', []) as $email) {
            $this->notify($tenant, $email, $title, $body, $key, true);
        }
    }
}
