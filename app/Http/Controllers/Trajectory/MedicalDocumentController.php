<?php

namespace App\Http\Controllers\Trajectory;

use App\Http\Controllers\Controller;
use App\Models\Trajectory\MedicalDocument;
use App\Models\Trajectory\Space;
use App\Services\Trajectory\MedicalDocumentStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;

class MedicalDocumentController extends Controller
{
    public function index(Request $request, Space $space, MedicalDocumentStore $store)
    {
        $need = $store->authorizeFolder($request->user(), $space, $request->route('need'));
        $rows = MedicalDocument::where('space_id', $space->id)->where('need_id', $need)->latest('id')->get(MedicalDocument::LIST_COLUMNS);

        return response()->json(['documents' => $rows->map->summary()])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Space $space, MedicalDocumentStore $store)
    {
        $document = $store->save($request->user(), $space, $request->route('need'), $request->only(['document', 'note']));

        return response()->json(['document' => $document->summary(), 'created' => $document->wasRecentlyCreated], $document->wasRecentlyCreated ? 201 : 200)
            ->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request, Space $space, MedicalDocumentStore $store)
    {
        $need = $store->authorizeFolder($request->user(), $space, $request->route('need'));
        $original = MedicalDocument::where('space_id', $space->id)->where('need_id', $need)->findOrFail($request->route('document'));
        $bytes = $original->contents;
        abort_unless(hash_equals($original->sha256, hash('sha256', $bytes)), 500);
        $meta = $original->metadata;
        $fallback = 'medical-document.'.MedicalDocumentStore::MIME_EXTENSIONS[$meta['mime']];

        return response($bytes)->withHeaders([
            'Content-Type' => $meta['mime'], 'Content-Length' => strlen($bytes),
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $meta['filename'], $fallback),
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
