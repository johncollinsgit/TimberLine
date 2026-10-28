<?php

namespace App\Http\Controllers\Wholesale;

use App\Http\Controllers\Controller;
use App\Models\WholesaleLabelDesign;
use App\Models\WholesaleLabelRequest;
use App\Models\WholesaleLabelRevision;
use App\Services\Wholesale\Labels\WholesaleLabelContext;
use App\Services\Wholesale\Labels\WholesaleLabelLibrary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WholesaleLabelStorefrontController extends Controller
{
    public function index(Request $request, WholesaleLabelContext $auth, WholesaleLabelLibrary $library)
    {
        $owner = $auth->storefront($request);
        $designs = $library->owned(WholesaleLabelDesign::class, $owner)->whereNull('archived_at')->get();
        $revisions = $library->owned(WholesaleLabelRevision::class, $owner)->whereIn('design_id', $designs->pluck('id'))->where('status', 'approved')->whereIn('id', $designs->pluck('approved_revision_id')->filter())->orderByDesc('approved_at')->get();

        $recent = $library->owned(WholesaleLabelRequest::class, $owner)->whereNotNull('bound_at')->orderByDesc('bound_at')->pluck('design_id')->filter()->unique()->values()->flip();
        $revisions = $revisions->sortBy(fn ($revision) => $recent->get($revision->design_id, PHP_INT_MAX))->values();

        return response()->json(['labels' => $revisions->map(fn ($r) => $library->revisionPayload($r, '/apps/forestry/wholesale/labels/assets')), 'setup_variant' => config('wholesale_labels.setup_variant'), 'reuse_variant' => config('wholesale_labels.reuse_variant')])->header('Cache-Control', 'private, no-store');
    }

    public function upload(Request $request, WholesaleLabelContext $auth, WholesaleLabelLibrary $library)
    {
        $owner = $auth->storefront($request);
        $data = $request->validate(['file' => ['required', 'file', 'max:20480'], 'name' => ['required', 'string', 'max:120']]);
        $revision = $library->upload($owner, $request->file('file'), $data['name']);

        return response()->json(['label' => $library->revisionPayload($revision->fresh(), '/apps/forestry/wholesale/labels/assets')], 201)->header('Cache-Control', 'private, no-store');
    }

    public function prepare(Request $request, WholesaleLabelContext $auth, WholesaleLabelLibrary $library)
    {
        $owner = $auth->storefront($request);
        $data = $request->validate([
            'mode' => ['required', 'in:new,reuse,legacy'], 'revision_id' => ['nullable', 'uuid', 'required_unless:mode,legacy'],
            'instructions' => ['nullable', 'string', 'max:2000'], 'idempotency_key' => ['required', 'uuid'],
            'groups' => ['required', 'array', 'min:1', 'max:100'], 'groups.*.variant_id' => ['required', 'regex:/^[0-9]{1,20}$/'],
            'groups.*.line_key' => ['required', 'string', 'max:200'], 'groups.*.quantity' => ['required', 'integer', 'min:3', 'multiple_of:3'],
        ]);
        foreach ($data['groups'] as $group) {
            abort_if(in_array($group['variant_id'], [config('wholesale_labels.setup_variant'), config('wholesale_labels.reuse_variant')], true), 422);
        }
        app(\App\Services\Wholesale\Labels\WholesaleLabelCatalog::class)->verifyCandleGroups($owner, $data['groups']);
        $receipt = $library->prepare($owner, $data);

        return response()->json(['receipt' => $receipt->id, 'revision_id' => $receipt->revision_id, 'mode' => $receipt->mode,
            'fee_cents' => $receipt->fee_cents, 'variant_id' => config('wholesale_labels.'.($receipt->mode === 'new' ? 'setup' : 'reuse').'_variant')])->header('Cache-Control', 'private, no-store');
    }

    public function asset(Request $request, string $revision, string $kind, WholesaleLabelContext $auth, WholesaleLabelLibrary $library)
    {
        $owner = $auth->storefront($request);
        $record = $library->owned(WholesaleLabelRevision::class, $owner)->findOrFail($revision);
        abort_unless(in_array($kind, ['preview', 'vector-preview'], true), 404);
        $path = $kind === 'preview' ? $record->preview_path : $record->vector_preview_path;
        abort_unless($path && Storage::disk(config('wholesale_labels.disk'))->exists($path), 404);

        return response(Storage::disk(config('wholesale_labels.disk'))->get($path))->header('Content-Type', 'image/png')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function revisionStatus(Request $request, string $revision, WholesaleLabelContext $auth, WholesaleLabelLibrary $library)
    {
        $owner = $auth->storefront($request);
        $record = $library->owned(WholesaleLabelRevision::class, $owner)->findOrFail($revision);

        return response()->json(['label' => $library->revisionPayload($record, '/apps/forestry/wholesale/labels/assets')])->header('Cache-Control', 'private, no-store');
    }

    public function requestStatus(Request $request, string $receipt, WholesaleLabelContext $auth, WholesaleLabelLibrary $library)
    {
        $owner = $auth->storefront($request);
        $record = $library->owned(WholesaleLabelRequest::class, $owner)->findOrFail($receipt);

        return response()->json(['receipt' => $record->id, 'mode' => $record->mode, 'revision_id' => $record->revision_id, 'status' => $record->status, 'instructions' => $record->instructions, 'bound' => (bool) $record->bound_at, 'label' => $record->revision_id ? $library->revisionPayload($library->owned(WholesaleLabelRevision::class, $owner)->findOrFail($record->revision_id), '/apps/forestry/wholesale/labels/assets') : null])->header('Cache-Control', 'private, no-store');
    }
}
