<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class ContractorCertificatesController
{
    private function json(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, ContractorCertificates $certificates)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid'], 'contractor_id' => ['sometimes', 'uuid']]);

        return $this->json($certificates->listing($request->attributes->get('current_tenant'), $request->user(), $input['area_id'], $input['contractor_id'] ?? null));
    }

    public function reminders(Request $request, ContractorCertificates $certificates)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid']]);

        return $this->json($certificates->reminders($request->attributes->get('current_tenant'), $request->user(), $input['area_id']));
    }

    public function show(Request $request, string $id, ContractorCertificates $certificates)
    {
        return $this->json($certificates->show($request->attributes->get('current_tenant'), $request->user(), $id));
    }

    public function history(Request $request, string $id, ContractorCertificates $certificates)
    {
        return $this->json($certificates->history($request->attributes->get('current_tenant'), $request->user(), $id));
    }

    public function command(Request $request, ContractorCertificates $certificates)
    {
        abort_unless($request->isJson(), 415, 'Certificate commands require a JSON body.');

        return $this->json($certificates->execute($request->attributes->get('current_tenant'), $request->user(), $request->json()->all()));
    }
}
