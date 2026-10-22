<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class RequesterController
{
    public function index(Request $request, RequesterPortal $portal)
    {
        return response()->json($portal->listing($request->attributes->get('current_tenant'), $request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $id, RequesterPortal $portal)
    {
        return response()->json($portal->show($request->attributes->get('current_tenant'), $request->user(), $id))->header('Cache-Control', 'private, no-store');
    }

    public function submit(Request $request, RequesterPortal $portal)
    {
        $input = $request->validate(['id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'], 'area_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:100'], 'description' => ['required', 'string', 'max:5000'],
            'category' => ['required', 'in:IT,Events,Maintenance,Projects'], 'location' => ['nullable', 'string', 'max:200']]);

        return response()->json($portal->submit($request->attributes->get('current_tenant'), $request->user(), $input))->header('Cache-Control', 'private, no-store');
    }

    public function publish(Request $request, string $id, RequesterPortal $portal)
    {
        $input = $request->validate(['request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1'],
            'message' => ['required', 'string', 'max:2000'], 'confirmed_public' => ['required', 'accepted']]);
        $input['expected_version'] = (int) $input['expected_version'];

        return response()->json($portal->publish($request->attributes->get('current_tenant'), $request->user(), $id, $input))->header('Cache-Control', 'private, no-store');
    }
}
