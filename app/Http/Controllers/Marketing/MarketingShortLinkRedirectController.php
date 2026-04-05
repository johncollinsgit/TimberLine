<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingShortLink;
use App\Services\Marketing\MessageClickTrackingService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class MarketingShortLinkRedirectController extends Controller
{
    public function __construct(
        protected MessageClickTrackingService $messageClickTrackingService
    ) {
    }

    public function show(Request $request, string $code): RedirectResponse
    {
        $link = MarketingShortLink::query()
            ->where('code', strtolower(trim($code)))
            ->firstOrFail();

        $link->forceFill([
            'usage_count' => (int) $link->usage_count + 1,
            'last_used_at' => now(),
        ])->save();

        try {
            $this->messageClickTrackingService->recordClickFromShortLink($link, $request);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return redirect()->away($link->destination_url);
    }
}
