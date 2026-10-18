<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Voice\PhonePractice;
use Illuminate\Http\Request;

class AiPhonePracticeController extends Controller
{
    public function run(Request $request, string $tenant = '')
    {
        $workspace = $request->attributes->get('current_tenant');
        abort_unless($workspace instanceof Tenant, 404);
        $data = $request->validate(['scenario' => ['required', 'string'], 'request_id' => ['required', 'uuid']]);

        return response()->json(app(PhonePractice::class)->run($workspace, $request->user(), $data['scenario'], $data['request_id']))->header('Cache-Control', 'no-store, private');
    }
}
