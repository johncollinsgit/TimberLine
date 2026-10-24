<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class GmailController
{
    private function json(array $value)
    {
        return response()->json($value)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function status(Request $request, GmailConnections $connections)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid']]);

        return $this->json($connections->status($request->attributes->get('current_tenant'), $request->user(), $input['area_id']));
    }

    public function connect(Request $request, GmailConnections $connections)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid']]);

        return $this->json($connections->connect($request->attributes->get('current_tenant'), $request->user(), $input['area_id'], $request));
    }

    public function callback(Request $request, GmailConnections $connections)
    {
        try {
            $result = $connections->callback($request);
        } catch (\Throwable $error) {
            // Never redirect codes/state into login intended URLs or reflect provider text.
            return response('This Gmail connection could not be completed. Return to your Enterprise workspace and start again.',
                $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getStatusCode() : 400)
                ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
        }

        return redirect()->route('enterprise.index', ['tenant' => $result['tenant_slug'], 'area_id' => $result['area_id'], 'section' => 'ask',
            'gmail' => $result['status']], 303)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function disconnect(Request $request, string $id, GmailConnections $connections)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid']]);

        return $this->json($connections->disconnect($request->attributes->get('current_tenant'), $request->user(), $input['area_id'], $id));
    }
}
