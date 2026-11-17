<?php

namespace App\Http\Controllers\Trajectory;

use App\Http\Controllers\Controller;
use App\Models\Trajectory\Space;
use App\Services\Trajectory\AccountLedgerService;
use App\Services\Trajectory\FinanceAccess;
use Illuminate\Http\Request;

class AccountLedgerController extends Controller
{
    public function show(Request $request, Space $space, FinanceAccess $access, AccountLedgerService $ledger)
    {
        $access->authorize($request->user(), $space);
        $data = $request->validate(['snapshot_id' => 'required|integer|min:1', 'account_key' => ['required', 'string', 'max:160', 'regex:/^[a-zA-Z0-9_.:-]+$/D']]);

        return response()->json($ledger->forAccount($request->user(), $space, (int) $data['snapshot_id'], $data['account_key']))->header('Cache-Control', 'private, no-store');
    }
}
