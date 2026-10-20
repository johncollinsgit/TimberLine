<?php

namespace App\Services\Marketplace;

use App\Models\User;

/** Account-only example-data access; never an authorization for a merchant workspace. */
class MarketplaceAccountPreview
{
    public function allows(?User $user): bool
    {
        return $user !== null
            && $user->is_active
            && $user->email_verified_at !== null
            && in_array((int) $user->getAuthIdentifier(), (array) config('marketplace.account_preview_user_ids', []), true);
    }

    public function url(): string
    {
        return rtrim((string) config('app.url'), '/').route('marketplace.preview', [], false);
    }
}
