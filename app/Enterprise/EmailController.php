<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class EmailController
{
    public function mailboxes(Request $request, EmailDrafts $drafts)
    {
        $data = $request->validate(['area_id' => ['required', 'uuid']]);

        return response()->json($drafts->mailboxes($request->attributes->get('current_tenant'), $request->user(), $data['area_id']))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, EmailDrafts $drafts)
    {
        $data = $request->validate(['area_id' => ['required', 'uuid'], 'mailbox_id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'],
            'to' => ['required', 'email:rfc', 'max:254', 'not_regex:/[\r\n]/'], 'subject' => ['required', 'string', 'max:200', 'not_regex:/[\r\n]/'],
            'body' => ['required', 'string', 'max:10000'], 'source_refs' => ['sometimes', 'array', 'max:10'],
            'source_refs.*' => ['required', 'string', 'distinct', 'max:100']]);

        return response()->json($drafts->create($request->attributes->get('current_tenant'), $request->user(), $data))->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, string $id, EmailDrafts $drafts)
    {
        return response()->json($drafts->preview($request->attributes->get('current_tenant'), $request->user(), $id))->header('Cache-Control', 'private, no-store');
    }

    public function confirm(Request $request, string $id, EmailDrafts $drafts)
    {
        $data = $request->validate(['digest' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'confirmed' => ['required', 'accepted']]);

        return response()->json($drafts->confirm($request->attributes->get('current_tenant'), $request->user(), $id, $data['digest']))->header('Cache-Control', 'private, no-store');
    }
}
