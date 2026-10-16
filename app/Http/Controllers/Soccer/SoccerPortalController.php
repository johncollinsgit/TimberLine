<?php

namespace App\Http\Controllers\Soccer;

use App\Http\Controllers\Controller;
use App\Services\Soccer\SoccerFamilyService;
use App\Services\Soccer\SoccerPaymentService;
use App\Services\Soccer\SoccerRegistrationService;
use App\Services\Soccer\SoccerStore;
use Illuminate\Http\Request;

class SoccerPortalController extends Controller
{
    public function __construct(private SoccerFamilyService $families, private SoccerStore $store) {}

    public function handle(Request $r, string $action)
    {
        $t = $r->attributes->get('soccer_tenant');
        $tenant = (int) $t->id;
        if ($action === 'code') {
            $d = $r->validate(['email' => 'required|email|max:254', 'mode' => 'sometimes|in:new,existing']);
            $this->families->code($tenant, $d['email'], $d['mode'] ?? 'new');

            return response()->json(['ok' => true]);
        }
        if ($action === 'verify') {
            $d = $r->validate(['email' => 'required|email|max:254', 'code' => 'required|digits:6', 'mode' => 'sometimes|in:new,existing']);
            $token = $this->families->verify($tenant, $d['email'], $d['code'], $d['mode'] ?? 'new');
            abort_unless($token, 422, 'That code is invalid or expired.');

            return response()->json(['token' => $token]);
        }
        $f = $this->families->family($tenant, $r->header('X-Wren-Session'));
        if ($action === 'help-history') {
            return response()->json(['messages' => app(\App\Services\Soccer\SoccerFamilyHelpService::class)->history($tenant, $f->id)]);
        }
        if ($action === 'help') {
            $d = $r->validate(['message' => 'required|string|min:2|max:1000']);

            return response()->json(app(\App\Services\Soccer\SoccerFamilyHelpService::class)->ask($tenant, $f, $d['message']));
        }
        if ($action === 'birthday-correction') {
            $d = $r->validate(['player_id' => 'required|uuid', 'birthday' => 'required|date_format:Y-m-d|before:today', 'confirmed' => 'required|accepted']);
            app(\App\Services\Soccer\SoccerFamilyHelpService::class)->correctBirthday($tenant, $f, $d['player_id'], $d['birthday']);

            return response()->json($this->families->profile($tenant, $this->store->row('families', $tenant, $f->id)));
        }
        if ($action === 'logout') {
            $this->store->query('sessions', $tenant)->where('token_hash', hash('sha256', $r->header('X-Wren-Session')))->delete();

            return response()->json(['ok' => true]);
        }
        if ($action === 'release-export') {
            $d = $r->validate(['release_id' => 'required|uuid']);
            $release = $this->store->row('releases', $tenant, $d['release_id']);
            abort_unless($release->family_id === $f->id, 403);

            $player = $release->player_id ? $this->store->row('players', $tenant, $release->player_id) : null;

            return response()->json(['playerName' => $player?->name ?? ($this->store->decrypt($release->answers)['child1Name'] ?? 'Player'), 'answers' => $this->store->decrypt($release->answers), 'season_key' => $release->season_key, 'signed_at' => $release->signed_at]);
        }
        if ($action === 'draft') {
            $d = $r->validate(['draft' => 'required|array']);
            abort_unless(strlen(json_encode($d)) <= 50000, 422);
            $p = $this->store->decrypt($f->profile);
            $p['registration_draft'] = $d['draft'];
            $this->store->query('families', $tenant)->where('id', $f->id)->update(['profile' => $this->store->encrypt($p), 'updated_at' => now()]);

            return response()->json(['ok' => true]);
        }
        if ($action === 'profile' && $r->isMethod('get')) {
            return response()->json($this->families->profile($tenant, $f));
        }
        if ($action === 'profile') {
            $d = $r->validate(['parentName' => 'required|string|max:160', 'phone' => 'required|string|max:40', 'spouseName' => 'nullable|string|max:160', 'spousePhone' => 'nullable|string|max:40', 'address' => 'nullable|string|max:300', 'city' => 'nullable|string|max:160', 'state' => 'nullable|string|max:50', 'zip' => 'nullable|string|max:20', 'motherName' => 'nullable|string|max:160', 'motherPhone' => 'nullable|string|max:40', 'fatherName' => 'nullable|string|max:160', 'fatherPhone' => 'nullable|string|max:40', 'pickupPerson' => 'nullable|string|max:160', 'emergencyContact' => 'nullable|string|max:160', 'emergencyPhone' => 'nullable|string|max:40', 'sms_consent' => 'required|boolean']);
            $this->families->saveProfile($tenant, $f, $d);
        } elseif ($action === 'player') {
            $d = $r->validate(['id' => 'nullable|uuid', 'name' => 'required|string|max:160', 'birthday' => 'required|date_format:Y-m-d|before:today', 'profile' => 'nullable|array', 'profile.*' => 'nullable|string|max:1000']);

            return response()->json(['id' => $this->families->player($tenant, $f, $d)]);
        } elseif ($action === 'release') {
            $d = $r->validate(['player_id' => 'required|uuid', 'season_id' => 'required|uuid', 'request_id' => 'required|uuid', 'medical_reviewed' => 'required|accepted', 'answers' => 'required|array', 'answers.*' => 'nullable|string|max:1000']);

            return response()->json(['id' => $this->families->release($tenant, $f, $d)]);
        } elseif ($action === 'register') {
            $d = $r->validate(['season_id' => 'required|uuid', 'volunteer' => 'required|boolean', 'coach_parent' => 'exclude_unless:volunteer,true|required|in:mother,father', 'coach_name' => 'exclude_unless:volunteer,true|required|string|max:160', 'coach_teams' => 'exclude_unless:volunteer,true|required|array|min:1|max:3', 'coach_teams.*' => 'in:green-bears,blue-dragons,red-falcons', 'coach_available_8_of_10' => 'exclude_unless:volunteer,true|required|accepted', 'coach_jersey_size' => 'nullable|string|max:30', 'waived_player_ids' => 'array|max:2', 'waived_player_ids.*' => 'uuid', 'children' => 'required|array|min:1|max:12', 'children.*.player_id' => 'required|uuid', 'children.*.needs_jersey' => 'required|boolean', 'children.*.jersey_size' => 'nullable|string|max:30']);
            app(SoccerRegistrationService::class)->register($tenant, $f->id, $d);
        } elseif ($action === 'checkout') {
            $d = $r->validate(['invoice_id' => 'required|uuid']);
            $i = $this->store->row('invoices', $tenant, $d['invoice_id']);
            abort_unless($i->family_id === $f->id, 403);

            return response()->json(['url' => app(SoccerPaymentService::class)->checkout($tenant, $i->id)]);
        } else {
            abort(404);
        }

        return response()->json($this->families->profile($tenant, $this->store->row('families', $tenant, $f->id)));
    }
}
