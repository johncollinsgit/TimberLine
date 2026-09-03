<?php

namespace App\Http\Controllers\Marketing;

use App\Services\Marketing\MarketingWishlistService;
use Illuminate\Contracts\View\View;

class MarketingWishlistShareController
{
    public function show(string $token, MarketingWishlistService $wishlistService): View
    {
        $payload = $wishlistService->publicSharedListPayload($token);

        abort_unless($payload, 404);

        return view('marketing.public.wishlist-share', $payload);
    }
}
