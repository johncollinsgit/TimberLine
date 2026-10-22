<?php

namespace App\Enterprise\Trial;

use Illuminate\Http\Request;

class TrialController
{
    private function json(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')->header('X-Robots-Tag', 'noindex, noarchive');
    }

    public function start(Request $request, ReviewerTrial $trial)
    {
        $input = $request->validate(['reviewer_code' => ['required', 'string', 'min:16', 'max:200']]);

        return $this->json($trial->start($request, $input['reviewer_code']));
    }

    public function show(Request $request, ReviewerTrial $trial)
    {
        return $this->json($trial->show($request));
    }

    public function preview(Request $request, ReviewerTrial $trial)
    {
        return $this->json($trial->preview($request, $request->only(['request_id', 'channel', 'destination', 'fields'])));
    }

    public function confirm(Request $request, string $id, ReviewerTrial $trial)
    {
        $input = $request->validate(['confirmed' => ['required', 'accepted'], 'digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);

        return $this->json($trial->confirm($request, $id, $input['digest']));
    }

    public function task(string $token, ReviewerTrial $trial)
    {
        return $this->json($trial->task($token));
    }

    public function update(Request $request, string $token, ReviewerTrial $trial)
    {
        return $this->json($trial->update($token, $request->only(['request_id', 'expected_version', 'action', 'status', 'note'])));
    }

    public function upload(Request $request, string $token, ReviewerTrial $trial)
    {
        $input = $request->validate(['request_id' => ['required', 'uuid'], 'file' => ['required', 'file', 'max:5120']]);

        return $this->json($trial->upload($token, $input['request_id'], $request->file('file')));
    }

    public function download(string $token, string $id, ReviewerTrial $trial)
    {
        return $trial->download($token, $id);
    }

    public function revokeLink(Request $request, string $id, ReviewerTrial $trial)
    {
        return $this->json($trial->revokeLink($request, $id));
    }

    public function wordmark(TrialStore $store)
    {
        $store->tenant();

        return response()->file(resource_path('js/enterprise/reviewer/assets/lees-mcrae-official-wordmark.png'),
            ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, noarchive', 'Referrer-Policy' => 'no-referrer']);
    }
}
