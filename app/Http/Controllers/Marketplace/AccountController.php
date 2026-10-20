<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Identity;
use App\Models\Marketplace\LoginLink;
use App\Models\Marketplace\Order;
use App\Services\Marketplace\MarketplaceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AccountController extends Controller
{
    public function index(Request $request, MarketplaceAccess $access)
    {
        $identity = $access->identity($request);

        return view('marketplace.account', ['identity' => $identity, 'orders' => $identity ? Order::query()->where('email_hash', hash('sha256', $identity->email))->latest('id')->paginate(15) : null]);
    }

    public function send(Request $request)
    {
        $email = strtolower(trim($request->validate(['email' => 'required|email:rfc|max:254'])['email']));
        $token = Str::random(64);
        $link = LoginLink::query()->create(['email' => $email, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(15)]);
        $url = 'https://'.config('marketplace.host').'/account/verify/'.$token;
        if (app()->environment('local') && in_array(config('marketplace.host'), ['127.0.0.1', 'localhost'], true)) {
            $url = $request->getSchemeAndHttpHost().'/account/verify/'.$token;
        }
        try {
            Mail::raw("Sign in to Markets and Makers:\n\n".$url."\n\nThis link expires in 15 minutes. Open it and confirm sign-in. If you did not request this, you can ignore it.", fn ($message) => $message->to($email)->subject('Your Markets and Makers sign-in link'));
        } catch (\Throwable) {
            $link->delete();

            return back()->withErrors(['email' => 'We could not send your link. Please try again shortly.']);
        }

        return redirect('/account')->with('status', 'Check your email for a sign-in link.');
    }

    public function verify(string $token)
    {
        $link = LoginLink::query()->where('token_hash', hash('sha256', $token))->whereNull('consumed_at')->where('expires_at', '>', now())->firstOrFail();

        return view('marketplace.verify', ['token' => $token]);
    }

    public function consume(Request $request, string $token)
    {
        $identity = DB::transaction(function () use ($token) {
            $link = LoginLink::query()->where('token_hash', hash('sha256', $token))->whereNull('consumed_at')->where('expires_at', '>', now())->lockForUpdate()->firstOrFail();
            $identity = Identity::query()->firstOrCreate(['email' => $link->email], ['active' => true]);
            abort_unless($identity->active, 403);
            $link->forceFill(['consumed_at' => now()])->save();

            return $identity;
        });
        $request->session()->regenerate();
        $request->session()->put('marketplace_identity', $identity->id);

        return redirect('/account')->with('status', 'You’re signed in.');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('marketplace_identity');
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    public function order(Request $request, int $id, MarketplaceAccess $access)
    {
        $identity = $access->identity($request);
        abort_unless($identity, 403);
        $order = Order::query()->where('email_hash', hash('sha256', $identity->email))->with('lines.maker')->findOrFail($id);

        return view('marketplace.order', compact('order'));
    }
}
