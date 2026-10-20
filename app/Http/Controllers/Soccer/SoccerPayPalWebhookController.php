<?php

namespace App\Http\Controllers\Soccer;

use App\Http\Controllers\Controller;
use App\Services\Soccer\SoccerPayPalService;
use Illuminate\Http\Request;

class SoccerPayPalWebhookController extends Controller
{
    public function __invoke(Request $request, SoccerPayPalService $payments)
    {
        abort_if(strlen($request->getContent()) > 1048576, 413);
        $payments->webhook($request);

        return response()->json(['received' => true]);
    }
}
