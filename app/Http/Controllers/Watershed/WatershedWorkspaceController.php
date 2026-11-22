<?php

namespace App\Http\Controllers\Watershed;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Soccer\SoccerStore;
use App\Services\Watershed\WatershedPlacement;
use App\Services\Watershed\WatershedRegistration;
use App\Services\Watershed\WatershedStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WatershedWorkspaceController extends Controller
{
    private function tenant(Request $r): Tenant
    {
        abort_unless(config('watershed.enabled'), 404);
        $t = app(\App\Services\Soccer\SoccerAccess::class)->tenant($r, true);
        abort_unless(in_array(strtolower($r->user()->email), config('watershed.leaders'), true), 403);

        return $t;
    }

    public function index(Request $r)
    {
        $t = $this->tenant($r);
        $s = app(WatershedStore::class);
        $shared = app(SoccerStore::class);
        $term = app(WatershedRegistration::class)->term($t->id);
        $families = $shared->query('families', $t->id)->get()->mapWithKeys(fn ($f) => [$f->id => ['id' => $f->id, 'email' => $f->email, 'name' => $shared->decrypt($f->profile)['parentName'] ?? $f->email, 'preapproved' => (bool) $s->query('family_states', $t->id)->where('family_id', $f->id)->value('preapproved')]]);
        $apps = $s->query('applications', $t->id)->where('term_id', $term->id)->latest('created_at')->get()->map(fn ($a) => [...(array) $a, 'data' => $s->decrypt($a->payload), 'releases' => $s->query('releases', $t->id)->where('application_id', $a->id)->get(['id', 'player_id', 'signed_at'])]);

        return view('watershed.workspace', ['tenant' => $t, 'term' => $term, 'families' => $families, 'applications' => $apps, 'classes' => WatershedPlacement::CLASSES, 'maximum' => app(WatershedPlacement::class)->maximum($term->school_year), 'imports' => $s->query('imports', $t->id)->whereNull('family_id')->get()->map(fn ($x) => ['id' => $x->id, ...$s->decrypt($x->payload)]), 'deliveries' => $s->query('deliveries', $t->id)->select('status')->get()->countBy('status')]);
    }

    public function action(Request $r, string $action)
    {
        $t = $this->tenant($r);
        $s = app(WatershedStore::class);
        $svc = app(WatershedRegistration::class);
        if ($action === 'preapprove') {
            $d = $r->validate(['family_id' => 'required|uuid', 'preapproved' => 'required|boolean', 'import_id' => 'nullable|uuid']);
            DB::transaction(function () use ($svc, $s, $t, $r, $d) {
                $svc->preapprove($t->id, $d['family_id'], (bool) $d['preapproved'], $r->user()->id);
                if (! empty($d['import_id'])) {
                    $s->query('imports', $t->id)->where('id', $d['import_id'])->whereNull('family_id')->update(['family_id' => $d['family_id'], 'updated_at' => now()]);
                }
            });
        } elseif ($action === 'term') {
            $d = $r->validate(['term_id' => 'required|uuid', 'open' => 'required|boolean']);
            $s->row('terms', $t->id, $d['term_id']);
            $s->query('terms', $t->id)->where('id', $d['term_id'])->update(['registration_open' => (bool) $d['open'], 'updated_at' => now()]);
            $s->event($t->id, 'registration_open_changed', $d, $r->user()->id);
        } elseif ($action === 'new-term') {
            $d = $r->validate(['year' => 'required|integer|min:2026|max:2040', 'semester' => 'required|in:fall,spring']);
            $key = $d['semester'].'-'.($d['semester'] === 'spring' ? $d['year'] + 1 : $d['year']);
            abort_if($s->query('terms', $t->id)->where('term_key', $key)->exists(), 422, 'That semester already exists.');
            $s->insert('terms', $t->id, ['term_key' => $key, 'name' => ucfirst($d['semester']).' '.($d['semester'] === 'spring' ? $d['year'] + 1 : $d['year']), 'school_year' => $d['year']]);
            $s->event($t->id, 'term_created', ['key' => $key], $r->user()->id);
        } else {
            $d = $r->validate(['application_id' => 'required|uuid', 'version' => 'required|integer', 'player_id' => 'nullable|uuid', 'reason' => 'nullable|string|max:2000']);
            $svc->decision($t->id, $d['application_id'], $d['version'], $action, $d['player_id'] ?? null, $d['reason'] ?? null, $r->user()->id);
        }

        return back()->with('status', 'Watershed updated.');
    }

    public function release(Request $r, string $id)
    {
        $t = $this->tenant($r);

        return app(WatershedPdf::class)->response(app(WatershedStore::class)->row('releases',$t->id,$id));
    }
}
