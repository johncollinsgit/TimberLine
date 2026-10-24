<?php

namespace App\Http\Controllers\Soccer;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Soccer\SoccerAccess;
use App\Services\Soccer\SoccerGroupMeService;
use App\Services\Soccer\SoccerPaymentService;
use App\Services\Soccer\SoccerRegistrationService;
use App\Services\Soccer\SoccerStore;
use App\Services\Soccer\SoccerWebsiteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SoccerWorkspaceController extends Controller
{
    public function __construct(private SoccerStore $store, private SoccerAccess $access) {}

    public function index(Request $r, string $section = 'home')
    {
        $t = $this->access->tenant($r);
        $admin = $this->access->admin($t, $r->user());
        $coach = $this->access->coach($t, $r->user());
        abort_unless(in_array($section, ['home', 'parents', 'players', 'coaches', 'teams', 'messages', 'settings', 'website'], true), 404);
        if (! $admin) {
            abort_unless(in_array($section, ['home', 'players', 'teams', 'coaches', 'messages'], true), 403);
        }
        $seasons = $this->store->query('seasons', $t->id)->orderByDesc('season_key')->get();
        $selected = $seasons->firstWhere('id', $r->query('season')) ?? $seasons->firstWhere('season_key', 'spring-2027');
        $regs = $this->store->query('registrations', $t->id)->when($selected, fn ($q) => $q->where('season_id', $selected->id))->when(! $admin, fn ($q) => $q->whereIn('team_key', json_decode($coach->team_keys, true)))->get();
        $players = $this->store->query('players', $t->id)->when(! $admin, fn ($q) => $q->whereIn('id', $regs->pluck('player_id')))->orderBy('name')->get()->map(fn ($p) => [...(array) $p, 'profile' => $this->store->decrypt($p->profile)])->keyBy('id');
        $families = $this->store->query('families', $t->id)->when(! $admin, fn ($q) => $q->whereIn('id', $regs->pluck('family_id')))->get()->map(fn ($f) => [...(array) $f, 'profile' => $this->store->decrypt($f->profile)])->keyBy('id');
        if (! $admin) {
            $regs = $regs->map(fn ($row) => (object) collect((array) $row)->except(['amount_cents', 'payment_status', 'waived', 'offer_group'])->all());
        }
        $data = ['tenant' => $t, 'admin' => $admin, 'coach' => $coach, 'section' => $section, 'seasons' => $seasons, 'season' => $selected, 'registrations' => $regs, 'players' => $players, 'families' => $families, 'capacity' => $selected ? app(SoccerRegistrationService::class)->capacities($selected) : [], 'coaches' => $admin ? $this->store->query('coaches', $t->id)->get() : collect([$coach]), 'payments' => $admin ? $this->store->query('payments', $t->id)->latest('created_at')->get() : collect(), 'refunds' => $admin ? $this->store->query('refunds', $t->id)->latest('created_at')->get()->map(fn ($refund) => [...(array) $refund, 'evidence' => $this->store->decrypt($refund->evidence)]) : collect(), 'invoices' => $admin ? $this->store->query('invoices', $t->id)->latest('created_at')->get() : collect(), 'imports' => $admin ? $this->store->query('import_rows', $t->id)->where('status', 'review')->get()->map(fn ($i) => [...(array) $i, 'evidence' => $this->store->decrypt($i->evidence)]) : collect(), 'events' => $admin ? $this->store->query('events', $t->id)->latest('created_at')->limit(50)->get()->map(fn ($e) => [...(array) $e, 'data' => $this->store->decrypt($e->data)]) : collect(), 'cardAccount' => $admin ? $t->paymentAccount()->first() : null, 'smsAccount' => $admin ? \App\Models\TenantMessagingAccount::query()->forAllTenants()->where('tenant_id', $t->id)->where('channel', 'sms')->first() : null, 'settings' => $admin ? $this->store->settings($t->id) : [], 'smsThreads' => $admin ? \App\Models\MessagingConversation::query()->forTenantId($t->id)->where('source_type', 'soccer_waitlist')->with('messages')->latest('last_message_at')->limit(50)->get() : collect(), 'blockers' => $admin ? app(\App\Services\Soccer\SoccerLaunchReadiness::class)->blockers($t) : [], 'releases' => $admin ? $this->store->query('releases', $t->id)->get() : collect(), 'groups' => app(SoccerGroupMeService::class)->allowed($t->id, $r->user(), $coach, $admin)];
        $data['helpQuestions'] = collect();
        $data['notificationDeliveries'] = collect();
        if ($admin && $section === 'messages') {
            $data['notificationDeliveries'] = $this->store->query('deliveries', $t->id)->latest('created_at')->limit(50)->get()->map(fn ($delivery) => ['channel' => $this->store->decrypt($delivery->payload)['channel'] ?? 'sms', 'status' => $delivery->status, 'attempts' => $delivery->attempts, 'error' => $delivery->error, 'created_at' => $delivery->created_at]);
            $reviewed = $this->store->query('events', $t->id)->where('action', 'family_help_reviewed')->get()->map(fn ($e) => $this->store->decrypt($e->data)['question_event_id'] ?? null)->filter()->all();
            $data['helpQuestions'] = $this->store->query('events', $t->id)->where('action', 'family_help_unanswered')->latest('created_at')->limit(100)->get()->reject(fn ($e) => in_array($e->id, $reviewed, true))->map(fn ($e) => [...(array) $e, 'data' => $this->store->decrypt($e->data)])->values();
        }
        if ($section === 'website') {
            $svc = app(SoccerWebsiteService::class);
            $svc->editor($t, $r->user());
            $site = $svc->site($t);
            $data['site'] = $site;
            $data['content'] = $site ? $svc->content($site, $site->draft_site_version_id) : $svc->defaults();
            $data['fields'] = $svc->fields();
            $data['preview'] = $site ? $svc->preview($t, $r->user()) : null;
            $data['versions'] = $site ? $site->siteVersions()->where('status', 'published')->latest('id')->get() : collect();
        }

        return response()->view('soccer.workspace', $data)->header('Cache-Control', 'private, no-store');
    }

    public function action(Request $r, string $action)
    {
        try {
            return $this->performAction($r, $action);
        } catch (\Illuminate\Validation\ValidationException $error) {
            if ($r->expectsJson()) {
                throw $error;
            }

            return back()->withErrors($error->validator)->withInput($r->except(['token', 'ein', 'help_ai_key']));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            if (in_array($error->getStatusCode(), [409, 422, 423, 502], true)) {
                return back()->withErrors(['soccer' => $error->getMessage() ?: 'This action could not be completed. Reload and try again.']);
            }
            throw $error;
        }
    }

    private function performAction(Request $r, string $action)
    {
        $t = $this->access->tenant($r, ! in_array($action, ['groupme-connect', 'groupme-send', 'coach-size'], true));
        $id = $t->id;
        $actor = $r->user()->id;
        $svc = app(SoccerRegistrationService::class);
        if ($action === 'sms-prepare') {
            try {
                $account = app(\App\Services\Marketing\Messaging\TenantMessagingProvisioningService::class)->provisionSms($t);
            } catch (\RuntimeException $error) {
                abort(502, 'Texting account setup is unavailable. Check the approved sender rollout and provider configuration.');
            }
            app(\App\Services\Soccer\SoccerEvents::class)->record($id, null, null, 'sms_account_prepared', ['summary' => 'Wren texting account prepared; sender registration and approval are still required.', 'account_id' => $account->id], $actor);
        } elseif ($action === 'sms-profile') {
            $d = $r->validate(['operator_type' => 'required|in:registered,individual', 'legal_name' => 'required|string|max:160', 'contact_name' => 'required|string|max:160', 'contact_email' => 'required|email|max:254', 'contact_phone' => 'required|string|max:40', 'address' => 'required|string|max:500', 'city' => 'required|string|max:100', 'state' => 'required|string|size:2', 'postal_code' => 'required|string|max:10', 'ein' => 'nullable|regex:/^[0-9]{2}-?[0-9]{7}$/']);
            abort_unless(app(\App\Support\Marketing\MarketingIdentityNormalizer::class)->toE164($d['contact_phone']), 422, 'Enter a valid contact phone for sender registration.');
            $settings = $this->store->settings($id);
            $prior = $settings['sms_business_profile'] ?? [];
            $d['ein'] = $d['operator_type'] === 'registered' ? (preg_replace('/[^0-9]/', '', $d['ein'] ?? '') ?: ($prior['ein'] ?? '')) : null;
            abort_unless($d['operator_type'] !== 'registered' || strlen($d['ein']) === 9, 422, 'The registered organization needs its EIN. Enter it only in this secure form.');
            $this->store->saveSettings($id, [...$settings, 'sms_business_profile' => [...$d, 'country' => 'US', 'submitted_by' => $actor, 'submitted_at' => now()->toIso8601String()]]);
            app(\App\Services\Soccer\SoccerEvents::class)->record($id, null, null, 'sms_business_profile_saved', ['summary' => 'Sender operator details saved securely for provider registration.'], $actor);
        } elseif ($action === 'card-connect') {
            $d = $r->validate(['email' => 'required|email|max:254']);

            return redirect()->away(app(\App\Services\Soccer\SoccerOnboardingService::class)->start($t, $r->user(), $d['email']));
        } elseif ($action === 'card-refresh') {
            app(\App\Services\Soccer\SoccerOnboardingService::class)->refresh($t, $r->user());
        } elseif ($action === 'season') {
            $d = $r->validate(['id' => 'required|uuid', 'starts_on' => 'nullable|date_format:Y-m-d', 'age_as_of' => 'nullable|date_format:Y-m-d', 'registration_opens_at' => 'nullable|date_format:Y-m-d\\TH:i', 'offer_hours' => 'required|integer|min:1|max:336', 'registration_open' => 'nullable|boolean', 'teams' => 'required|array', 'teams.*.public' => 'required|integer|min:0|max:1000', 'teams.*.reserved' => 'required|integer|min:0|max:1000']);
            DB::transaction(function () use ($id, $d, $svc, $actor) {
                $s = $this->store->lockSeason($id, $d['id']);
                $teams = json_decode($s->teams, true);
                $cap = $svc->capacities($s);
                foreach ($teams as $key => &$v) {
                    abort_unless(isset($d['teams'][$key]), 422);
                    $v['public'] = (int) $d['teams'][$key]['public'];
                    $v['reserved'] = (int) $d['teams'][$key]['reserved'];
                    abort_unless($v['public'] >= $cap[$key]['public_used'] && $v['reserved'] >= $cap[$key]['reserved_used'], 422, 'Capacity cannot be lower than occupied seats.');
                }unset($v);
                if ($d['registration_open'] ?? false) {
                    abort_unless($d['starts_on'] && app(\App\Services\Soccer\SoccerLaunchReadiness::class)->blockers(\App\Models\Tenant::findOrFail($id)) === [], 422, 'Verify payments and the selected email, SMS, and GroupMe notification setup before opening registration.');
                }$this->store->query('seasons', $id)->where('id', $s->id)->update(['teams' => json_encode($teams), 'starts_on' => $d['starts_on'], 'age_as_of' => $d['age_as_of'] ?? $d['starts_on'], 'registration_opens_at' => ! empty($d['registration_opens_at']) && \Carbon\CarbonImmutable::parse($d['registration_opens_at'], 'America/New_York')->gt(now()) ? \Carbon\CarbonImmutable::parse($d['registration_opens_at'], 'America/New_York')->utc() : null, 'offer_hours' => $d['offer_hours'], 'registration_open' => $d['registration_open'] ?? false, 'updated_at' => now()]);
                app(\App\Services\Soccer\SoccerEvents::class)->record($id, $s->id, null, 'season_settings_changed', ['summary' => 'Team capacities or season settings changed.'], $actor, waitlist: true);
            });
            $svc->promote($id, $d['id']);
        } elseif ($action === 'offer') {
            $d = $r->validate(['season_id' => 'required|uuid', 'family_id' => 'required|uuid', 'assignments' => 'required|array|min:1', 'assignments.*.id' => 'required|uuid|distinct', 'assignments.*.seat_kind' => 'required|in:public,reserved']);
            $svc->manualOffer($id, $d['season_id'], $d['family_id'], $d['assignments'], $actor);
        } elseif ($action === 'withdraw-offer') {
            $d = $r->validate(['invoice_id' => 'required|uuid', 'reason' => 'required|string|max:1000']);
            $svc->withdrawOffer($id, $d['invoice_id'], $actor, $d['reason']);
        } elseif ($action === 'override-team') {
            $d = $r->validate(['id' => 'required|uuid', 'team_key' => 'required|in:green-bears,blue-dragons,red-falcons', 'reason' => 'required|string|max:1000']);
            $svc->overrideTeam($id, $d['id'], $d['team_key'], $d['reason'], $actor);
        } elseif ($action === 'cancel') {
            $d = $r->validate(['id' => 'required|uuid']);
            $svc->cancel($id, $d['id'], $actor);
        } elseif ($action === 'reactivate') {
            $d = $r->validate(['id' => 'required|uuid']);
            $reg = $this->store->row('registrations', $id, $d['id']);
            abort_unless(in_array($reg->status, ['waitlisted', 'payment_pending'], true), 422);
            DB::transaction(function () use ($id, $reg, $actor) {
                $this->store->lockSeason($id, $reg->season_id);
                $this->store->query('registrations', $id)->where('id', $reg->id)->update(['auto_eligible' => true, 'updated_at' => now()]);
                app(\App\Services\Soccer\SoccerEvents::class)->record($id, $reg->season_id, $reg->family_id, 'waitlist_reactivated', ['summary' => 'Waiting player reactivated.'], $actor, waitlist: true);
            });
            $svc->promote($id, $reg->season_id);
        } elseif ($action === 'venmo') {
            $d = $r->validate(['invoice_id' => 'required|uuid', 'reference' => 'nullable|string|max:190', 'amount_cents' => 'required|integer|min:1', 'reason' => 'required|string|max:1000', 'confirmed' => 'required|accepted']);
            $result = app(SoccerPaymentService::class)->confirmVenmo($id, $d['invoice_id'], $d['amount_cents'], $d['reference'] ?? null, $d['reason'], $actor);
            if ($result === 'review') {
                return back()->withErrors(['soccer' => 'The Venmo receipt was recorded for review. The current offer was not changed; check for another payment or an expired offer.']);
            }
        } elseif ($action === 'waive') {
            $d = $r->validate(['invoice_id' => 'required|uuid', 'reason' => 'required|string|max:1000']);
            app(SoccerPaymentService::class)->waive($id, $d['invoice_id'], $d['reason'], $actor);
        } elseif ($action === 'refund') {
            $d = $r->validate(['invoice_id' => 'required|uuid', 'amount_cents' => 'required|integer|min:1', 'reason' => 'required|string|max:1000', 'venmo_completed' => 'nullable|boolean', 'payment_id' => 'nullable|uuid']);
            app(SoccerPaymentService::class)->refund($id, $d['invoice_id'], $d['amount_cents'], $d['reason'], $actor, (bool) ($d['venmo_completed'] ?? false), $d['payment_id'] ?? null);
        } elseif ($action === 'coach') {
            $d = $r->validate(['email' => 'required|email', 'name' => 'required|string|max:160', 'role' => 'required|in:head,assistant', 'team_keys' => 'required|array', 'team_keys.*' => 'in:green-bears,blue-dragons,red-falcons']);
            $u = User::where('email', strtolower($d['email']))->first();
            abort_unless($u && $u->is_active && $u->tenants()->whereKey($id)->wherePivot('membership_active', true)->exists(), 422, 'Invite this coach to Wren through Team Access first.');
            $existing = $this->store->query('coaches', $id)->where('user_id', $u->id)->first();
            $data = ['user_id' => $u->id, 'name' => $d['name'], 'role' => $d['role'], 'team_keys' => json_encode($d['team_keys'])];
            if ($existing) {
                $this->store->query('coaches', $id)->where('id', $existing->id)->update($data);
            } else {
                $this->store->insert('coaches', $id, $data);
            }
        } elseif ($action === 'coach-size') {
            $d = $r->validate(['jersey_size' => 'required|string|max:30']);
            $coach = $this->access->coach($t, $r->user());
            abort_unless($coach, 403);
            $this->store->query('coaches', $id)->where('id', $coach->id)->update(['jersey_size' => $d['jersey_size'], 'updated_at' => now()]);
        } elseif ($action === 'settings') {
            $d = $r->validate(['soccer_group' => 'nullable|regex:/^[0-9]+$/|max:80', 'head_coaches_group' => 'nullable|regex:/^[0-9]+$/|max:80', 'all_coaches_group' => 'nullable|regex:/^[0-9]+$/|max:80', 'soccer_group_url' => 'nullable|url:https|max:500', 'launch_verified' => 'nullable|boolean']);
            $settings = $this->store->settings($id);
            $settings['groupme_groups'] = array_filter(['soccer' => $d['soccer_group'], 'head_coaches' => $d['head_coaches_group'], 'all_coaches' => $d['all_coaches_group']]);
            abort_unless(count(array_unique($settings['groupme_groups'])) === count($settings['groupme_groups']), 422, 'Each GroupMe group must have a different ID.');
            $settings['soccer_group_url'] = $d['soccer_group_url'];
            $settings['launch_verified'] = $d['launch_verified'] ?? false;
            $settings['launch_verified_mode'] = config('soccer.notification_mode');
            $this->store->saveSettings($id, $settings);
        } elseif ($action === 'help-ai-settings') {
            $d = $r->validate(['help_ai_key' => 'nullable|string|min:20|max:500', 'enabled' => 'nullable|boolean', 'remove_key' => 'nullable|boolean']);
            $settings = $this->store->settings($id);
            if ($d['remove_key'] ?? false) {
                unset($settings['help_ai_key']);
            } elseif (! empty($d['help_ai_key'])) {
                $settings['help_ai_key'] = trim($d['help_ai_key']);
            }
            $enabled = (bool) ($d['enabled'] ?? false);
            abort_if($enabled && empty($settings['help_ai_key']) && ! config('services.openai.api_key'), 422, 'Add a server-side OpenAI API key before enabling AI answers.');
            $settings['help_ai_enabled'] = $enabled;
            $this->store->saveSettings($id, $settings);
            app(\App\Services\Soccer\SoccerEvents::class)->record($id, null, null, 'family_help_ai_settings_changed', ['summary' => 'Wren family help AI settings changed; credential remains encrypted.'], $actor);
        } elseif ($action === 'groupme-connect') {
            $d = $r->validate(['token' => 'required|string|max:500']);
            app(SoccerGroupMeService::class)->connect($id, $r->user(), $d['token']);
        } elseif ($action === 'groupme-send') {
            $d = $r->validate(['group_id' => 'required|string|max:80', 'body' => 'required|string|max:1000', 'request_id' => 'required|uuid']);
            $allowed = app(SoccerGroupMeService::class)->allowed($id, $r->user(), $this->access->coach($t, $r->user()), $this->access->admin($t, $r->user()));
            abort_unless(in_array($d['group_id'], $allowed, true), 403);
            app(SoccerGroupMeService::class)->send($id, $r->user(), $d['group_id'], $d['body'], $d['request_id']);
        } elseif ($action === 'help-reviewed') {
            $d = $r->validate(['event_id' => 'required|uuid']);
            $question = $this->store->row('events', $id, $d['event_id']);
            abort_unless($question->action === 'family_help_unanswered', 422);
            app(\App\Services\Soccer\SoccerEvents::class)->record($id, $question->season_id, $question->family_id, 'family_help_reviewed', ['question_event_id' => $question->id, 'summary' => 'Wren reviewed a family help question.'], $actor);
        } elseif ($action === 'website-initialize') {
            app(SoccerWebsiteService::class)->initialize($t, $r->user());
        } elseif (in_array($action, ['website-save', 'website-publish', 'website-restore'], true)) {
            $d = $r->validate(['version' => 'required|integer', 'content' => 'nullable|array', 'restore_version' => 'nullable|integer']);
            app(SoccerWebsiteService::class)->save($t, $r->user(), $d['content'] ?? [], (int) $d['version'], $action === 'website-publish', $action === 'website-restore' ? (int) $d['restore_version'] : null);
        } elseif ($action === 'guardian') {
            $d = $r->validate(['family_id' => 'required|uuid', 'email' => 'required|email|max:254', 'reason' => 'required|string|max:1000']);
            app(\App\Services\Soccer\SoccerFamilyService::class)->addGuardian($id, $d['family_id'], $d['email'], $d['reason'], $actor);
        } elseif ($action === 'release-link') {
            $d = $r->validate(['id' => 'required|uuid', 'player_id' => 'required|uuid', 'reason' => 'required|string|max:1000']);
            $release = $this->store->row('releases', $id, $d['id']);
            $player = $this->store->row('players', $id, $d['player_id']);
            abort_unless(! $release->player_id, 422, 'This release is already linked.');
            $this->store->query('releases', $id)->where('id', $release->id)->update(['family_id' => $player->family_id, 'player_id' => $player->id, 'updated_at' => now()]);
            app(\App\Services\Soccer\SoccerEvents::class)->record($id, null, $player->family_id, 'historical_release_linked', ['release_id' => $release->id, 'reason' => $d['reason']], $actor);
        } elseif ($action === 'import-create') {
            $d = $r->validate(['id' => 'required|uuid', 'email' => 'required|email|max:254', 'parentName' => 'required|string|max:160', 'phone' => 'required|string|max:40', 'name' => 'required|string|max:160', 'birthday' => 'required|date_format:Y-m-d|before:today', 'reason' => 'required|string|max:1000']);
            DB::transaction(function () use ($id, $d, $actor) {
                $row = $this->store->row('import_rows', $id, $d['id']);
                abort_unless($row->status === 'review', 422);
                $family = $this->store->query('families', $id)->where('email', strtolower($d['email']))->first();
                $familyId = $family?->id ?? $this->store->insert('families', $id, ['email' => strtolower($d['email']), 'profile' => $this->store->encrypt(['parentName' => $d['parentName'], 'phone' => $d['phone']])]);
                $player = $this->store->query('players', $id)->where('family_id', $familyId)->where('name', $d['name'])->where('birthday', $d['birthday'])->first();
                $playerId = $player?->id ?? $this->store->insert('players', $id, ['family_id' => $familyId, 'name' => $d['name'], 'birthday' => $d['birthday'], 'profile' => $this->store->encrypt([])]);
                $e = $this->store->decrypt($row->evidence);
                $e['review'] = ['actor' => $actor, 'reason' => $d['reason'], 'at' => now()->toIso8601String()];
                $this->store->query('import_rows', $id)->where('id', $row->id)->update(['family_id' => $familyId, 'player_id' => $playerId, 'status' => 'reviewed', 'evidence' => $this->store->encrypt($e), 'updated_at' => now()]);
                app(\App\Services\Soccer\SoccerImportService::class)->historical($id, $row->season_key, $familyId, $playerId, $row->fingerprint, '', '');
                app(\App\Services\Soccer\SoccerEvents::class)->record($id, null, $familyId, 'historical_identity_reviewed', ['row_id' => $row->id, 'reason' => $d['reason']], $actor);
            });
        } elseif ($action === 'import-resolve') {
            $d = $r->validate(['id' => 'required|uuid', 'family_id' => 'required|uuid', 'player_id' => 'required|uuid', 'reason' => 'required|string|max:1000']);
            $row = $this->store->row('import_rows', $id, $d['id']);
            $p = $this->store->row('players', $id, $d['player_id']);
            abort_unless($p->family_id === $d['family_id'], 422);
            $e = $this->store->decrypt($row->evidence);
            $e['review'] = ['actor' => $actor, 'reason' => $d['reason'], 'at' => now()->toIso8601String()];
            app(\App\Services\Soccer\SoccerImportService::class)->historical($id, $row->season_key, $p->family_id, $p->id, $row->fingerprint, '', '');
            app(\App\Services\Soccer\SoccerEvents::class)->record($id, null, $p->family_id, 'import_match_reviewed', ['row_id' => $row->id, 'reason' => $d['reason']], $actor);
            $this->store->query('import_rows', $id)->where('id', $row->id)->update(['family_id' => $p->family_id, 'player_id' => $p->id, 'status' => 'reviewed', 'evidence' => $this->store->encrypt($e), 'updated_at' => now()]);
        } else {
            abort(404);
        }

        return back()->with('status', 'Saved.');
    }

    public function groupMessages(Request $r)
    {
        $t = $this->access->tenant($r);
        $d = $r->validate(['group_id' => 'required|string|max:80', 'before' => 'nullable|regex:/^[0-9]+$/|max:80']);
        $svc = app(SoccerGroupMeService::class);
        abort_unless(in_array($d['group_id'], $svc->allowed($t->id, $r->user(), $this->access->coach($t, $r->user()), $this->access->admin($t, $r->user())), true), 403);

        return response()->json($svc->messages($t->id, $r->user(), $d['group_id'], $d['before'] ?? null));
    }
}
