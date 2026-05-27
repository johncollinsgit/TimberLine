<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\ServiceInquiry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class LandlordServiceInquiryController extends Controller
{
    public function index(Request $request): View
    {
        $status = strtolower(trim((string) $request->query('status', 'all')));
        $allowedStatuses = ['all', 'new', 'contacted', 'qualified', 'archived'];
        if (! in_array($status, $allowedStatuses, true)) {
            $status = 'all';
        }

        $query = ServiceInquiry::query()->latest('id');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return view('landlord.service-inquiries.index', [
            'inquiries' => $query->paginate(50)->withQueryString(),
            'activeStatus' => $status,
            'statusOptions' => [
                'all' => 'All',
                'new' => 'New',
                'contacted' => 'Contacted',
                'qualified' => 'Qualified',
                'archived' => 'Archived',
            ],
        ]);
    }
}
