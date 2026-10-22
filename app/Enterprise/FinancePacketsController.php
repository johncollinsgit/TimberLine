<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class FinancePacketsController
{
    private function json(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, FinancePackets $packets)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid']]);

        return $this->json($packets->listing($request->attributes->get('current_tenant'), $request->user(), $input['area_id']));
    }

    public function show(Request $request, string $id, FinancePackets $packets)
    {
        return $this->json($packets->show($request->attributes->get('current_tenant'), $request->user(), $id));
    }

    public function history(Request $request, string $id, FinancePackets $packets)
    {
        return $this->json($packets->history($request->attributes->get('current_tenant'), $request->user(), $id));
    }

    public function command(Request $request, FinancePackets $packets)
    {
        abort_unless($request->isJson(), 415, 'Packet commands require JSON.');

        return $this->json($packets->execute($request->attributes->get('current_tenant'), $request->user(), $request->json()->all()));
    }
}
