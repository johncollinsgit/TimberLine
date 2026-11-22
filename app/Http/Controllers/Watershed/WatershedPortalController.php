<?php

namespace App\Http\Controllers\Watershed;

use App\Http\Controllers\Controller;
use App\Services\Watershed\WatershedAuth;
use App\Services\Watershed\WatershedPayment;
use App\Services\Watershed\WatershedPlacement;
use App\Services\Watershed\WatershedRegistration;
use App\Services\Watershed\WatershedStore;
use Illuminate\Http\Request;

class WatershedPortalController extends Controller
{
    public function handle(Request $r, string $action)
    {
        $tenant = (int) $r->attributes->get('watershed_tenant')->id;
        $store = app(WatershedStore::class);
        $registration = app(WatershedRegistration::class);
        $auth = app(WatershedAuth::class);
        if ($action === 'info') {
            $t = $registration->term($tenant);

            return response()->json(['name' => $t->name, 'open' => (bool) $t->registration_open, 'cutoff' => $t->school_year.'-09-01', 'maximum' => app(WatershedPlacement::class)->maximum($t->school_year), 'permission_text' => config('watershed.permission_text'), 'medical_release_text' => config('watershed.medical_release_text')]);
        }
        abort_unless($r->isMethod('post') || $action === 'profile', 405);
        if ($action === 'code') {
            $d = $r->validate(['email' => 'required|email|max:254']);
            $auth->code($tenant, $d['email']);

            return response()->json(['ok' => true]);
        }
        if ($action === 'verify') {
            $d = $r->validate(['email' => 'required|email|max:254', 'code' => 'required|digits:6']);
            $token = $auth->verify($tenant, $d['email'], $d['code']);
            abort_unless($token, 422, 'That code is invalid or expired.');

            return response()->json(['token' => $token]);
        }
        $f = $auth->family($tenant, $r->header('X-Watershed-Session'));
        if ($action === 'profile') {
            return response()->json($registration->profile($tenant, $f));
        }
        if ($action === 'contact') {
            $rules = [];
            foreach (WatershedRegistration::CONTACT as $key) {
                $rules[$key] = in_array($key, ['parentName', 'phone', 'emergencyContact', 'emergencyPhone'], true) ? 'required|string|max:300' : 'nullable|string|max:300';
            }$registration->contact($tenant, $f, $r->validate($rules));
        } elseif ($action === 'child') {
            $d = $r->validate(['id' => 'nullable|uuid', 'name' => 'required|string|max:160', 'birthday' => 'required|date_format:Y-m-d|before_or_equal:today']);

            return response()->json(['id' => $registration->child($tenant, $f, $d)]);
        } elseif ($action === 'submit') {
            $d = $r->validate(['term_id' => 'required|uuid', 'request_id' => 'required|uuid', 'expected_version' => 'required|integer|min:0', 'teaching' => 'required|accepted', 'medicalReviewed' => 'required|accepted', 'guardianAccepted' => 'required|accepted', 'releaseAccepted' => 'required|accepted', 'guardianSignature' => 'required|string|min:2|max:160', 'releaseSignature' => 'required|string|min:2|max:160', 'children' => 'required|array|min:1|max:20', 'children.*.player_id' => 'required|uuid', 'children.*.grade' => 'required|integer|min:-2|max:12', 'children.*.class' => 'required|in:nursery,kinder,lower,upper', 'children.*.reason' => 'nullable|string|max:2000', 'children.*.medical' => 'required|array', 'children.*.medical.*' => 'nullable|string|max:2000']);
            $registration->submit($tenant, $f, $d);
        } elseif ($action === 'checkout' || $action === 'reconcile') {
            $d = $r->validate(['invoice_id' => 'required|uuid']);
            $i = $store->row('invoices', $tenant, $d['invoice_id']);
            abort_unless($store->row('applications', $tenant, $i->application_id)->family_id === $f->id, 403);
            if ($action === 'checkout') {
                return response()->json(['url' => app(WatershedPayment::class)->checkout($tenant, $f->id, $i->id)]);
            }app(WatershedPayment::class)->reconcile($tenant, $i->id);
        } elseif ($action === 'release') {
            $d = $r->validate(['release_id' => 'required|uuid']);
            $release = $store->row('releases', $tenant, $d['release_id']);
            abort_unless($store->row('applications', $tenant, $release->application_id)->family_id === $f->id, 403);

            return app(\App\Services\Watershed\WatershedReleaseExport::class)->response($release);
        } elseif ($action === 'logout') {
            $store->query('sessions', $tenant)->where('token_hash', hash('sha256', $r->header('X-Watershed-Session')))->delete();

            return response()->json(['ok' => true]);
        } else {
            abort(404);
        }

        return response()->json($registration->profile($tenant, app(\App\Services\Soccer\SoccerStore::class)->row('families', $tenant, $f->id)));
    }
}
