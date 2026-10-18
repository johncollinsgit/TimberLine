<?php

namespace App\Http\Controllers\Retail;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Retail\RetailShowroomService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class LocalReviewController extends Controller
{
    public function __invoke(Request $request, RetailShowroomService $showroom, string $token)
    {
        // This developer convenience can never authenticate a production account.
        abort_unless(app()->environment('local') && str_ends_with((string) config('retail_showroom.owner_email'), '.invalid'), 404);
        $id = Cache::pull('retail-local-review:'.hash('sha256', $token));
        $owner = $id ? User::find($id) : null;
        abort_unless($showroom->owner($owner), 404);
        Auth::login($owner);
        $request->session()->regenerate();

        return redirect('/retail/showroom')->header('Cache-Control', 'private, no-store');
    }
}
