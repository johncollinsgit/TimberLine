<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingShortLink;
use Illuminate\Http\RedirectResponse;

class MarketingShortLinkRedirectController extends Controller
{
    public function show(string $code): RedirectResponse
    {
        $link = MarketingShortLink::query()
            ->where('code', strtolower(trim($code)))
            ->firstOrFail();

        $link->forceFill([
            'usage_count' => (int) $link->usage_count + 1,
            'last_used_at' => now(),
        ])->save();

        return redirect()->away($link->destination_url);
    }
}
