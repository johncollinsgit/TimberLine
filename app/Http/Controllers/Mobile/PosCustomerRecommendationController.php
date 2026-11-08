<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Inventory\StockException;
use App\Services\Pos\PosCustomerRecommendations;
use App\Services\Pos\PosException;
use Illuminate\Http\Request;

class PosCustomerRecommendationController extends Controller
{
    public function __invoke(Request $request, string $tenant, string $register, string $profile)
    {
        try {
            if ($request->all() || ! ctype_digit($profile) || (int) $profile < 1) {
                throw new PosException('invalid_request', 422);
            }
            $body = app(PosCustomerRecommendations::class)->forCustomer($register, (int) $profile);
            $status = 200;
        } catch (PosException $error) {
            $body = ['contract_version' => 1, 'error' => ['code' => $error->blocker]];
            $status = $error->status;
        } catch (StockException $error) {
            $body = ['contract_version' => 1, 'error' => ['code' => $error->reason]];
            $status = $error->status;
        }

        return response()->json($body, $status, ['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache']);
    }
}
