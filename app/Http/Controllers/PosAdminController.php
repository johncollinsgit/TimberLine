<?php

namespace App\Http\Controllers;

use App\Services\Pos\PosAdministration;
use Illuminate\Http\Request;

final class PosAdminController extends Controller
{
    public function __construct(private PosAdministration $admin) {}

    public function index(Request $request)
    {
        $scope = $this->admin->scope();
        $from = now()->utc()->startOfDay()->toDateTimeString();
        $to = now()->utc()->endOfDay()->toDateTimeString();

        return response()->view('pos.admin', ['scope' => $scope, 'report' => $this->admin->report($scope, $from, $to),
            'inventory' => $this->admin->inventory($scope), 'tenantSlug' => $request->query('tenant')], 200,
            ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function report(Request $request)
    {
        $input = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);

        return response()->json($this->admin->report($this->admin->scope(), $input['from'].' 00:00:00', $input['to'].' 23:59:59'), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function adjust(Request $request, string $item)
    {
        $input = $request->validate(['request_id' => 'required|uuid', 'delta' => 'required|integer|between:-1000000,1000000', 'reason' => 'required|string|min:3|max:200']);
        $input['delta'] = (int) $input['delta'];
        $this->admin->adjust($this->admin->scope(), $item, $input);

        return back()->with('status', 'Stock adjustment recorded.');
    }

    public function pause()
    {
        $this->admin->pause($this->admin->scope());

        return back()->with('status', 'New payments paused. Recovery and refunds remain available.');
    }
}
