<?php

namespace App\Enterprise;

use DomainException;
use Illuminate\Http\Request;

class DiscussionController
{
    public function show(Request $request, string $kind, string $id, Discussion $discussion)
    {
        return response()->json($discussion->show($request->attributes->get('current_tenant'), $request->user(), $kind, $id))->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, string $kind, string $id, string $upload, Discussion $discussion)
    {
        return $discussion->preview($request->attributes->get('current_tenant'), $request->user(), $kind, $id, $upload);
    }

    public function open(Request $request, string $kind, string $id, Discussion $discussion)
    {
        $result = $discussion->open($request->attributes->get('current_tenant'), $request->user(), $kind, $id);

        return response()->json(['task_id' => $result['task']['id']])->header('Cache-Control', 'private, no-store');
    }

    public function comment(Request $request, string $kind, string $id, Discussion $discussion)
    {
        $input = $request->validate(['body' => ['required', 'string', 'max:5000'], 'request_id' => ['required', 'uuid']]);
        try {
            return response()->json($discussion->comment($request->attributes->get('current_tenant'), $request->user(), $kind, $id,
                $input['body'], $input['request_id']))->header('Cache-Control', 'private, no-store');
        } catch (DomainException $e) {
            return response()->json(['message' => 'This note could not be saved. Reload or retry the same note.', 'saved' => false], $e->getMessage() === 'idempotency_mismatch' ? 409 : 422);
        }
    }
}
