<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\AgenticChange;
use App\Models\ReadinessChecklistItem;
use App\Models\VisionIdea;
use App\Services\Operations\OperationalStatusService;
use Illuminate\Http\Response;

/**
 * Landlord-only "Developer Control Center": a live operator dashboard showing
 * system health, recent autonomous changes, and the forward-looking vision board.
 * Host-locked + landlord.operator gated via the landlord route group.
 */
class LandlordDeveloperDashboardController extends Controller
{
    public function __invoke(OperationalStatusService $status): Response
    {
        $checklist = ReadinessChecklistItem::query()
            ->orderBy('sort_order')
            ->get();

        return response()->view('landlord.developer.index', [
            'status' => $status->snapshot(),
            'checklist' => $checklist,
            'checklistDone' => $checklist->where('status', ReadinessChecklistItem::STATUS_DONE)->count(),
            'changes' => AgenticChange::query()
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->limit(12)
                ->get(),
            // Completed ideas drop off the board (status set to 'done' when shipped).
            'ideas' => VisionIdea::query()
                ->where('status', '!=', 'done')
                ->orderBy('sort_order')
                ->orderByDesc('id')
                ->limit(12)
                ->get(),
        ]);
    }
}
