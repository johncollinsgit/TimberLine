<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class FileCaptionController
{
    public function show(Request $request, string $id, FileCaptions $captions)
    {
        return response()->json($captions->show($request->attributes->get('current_tenant'), $request->user(), $id))
            ->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, string $id, FileCaptions $captions)
    {
        return response()->json($captions->save($request->attributes->get('current_tenant'), $request->user(), $id,
            $request->only(['caption', 'expected_version', 'request_id'])))->header('Cache-Control', 'private, no-store');
    }
}
