<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

final class RolePreviewController
{
    public function __invoke(Request $request, RolePreview $preview)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid'], 'member_id' => ['nullable', 'integer', 'min:1']]);

        return response()->json($preview->show($request->attributes->get('current_tenant'), $request->user(), $input['area_id'],
            isset($input['member_id']) ? (int) $input['member_id'] : null))->header('Cache-Control', 'private, no-store');
    }
}
