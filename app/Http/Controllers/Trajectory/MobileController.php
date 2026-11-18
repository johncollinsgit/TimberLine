<?php

namespace App\Http\Controllers\Trajectory;

use App\Http\Controllers\Controller;
use App\Models\Trajectory\AlertPreference;
use App\Models\Trajectory\ExpenseNotice;
use App\Models\Trajectory\PushSubscription;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Services\Trajectory\ExpenseNotificationService;
use App\Services\Trajectory\FinanceAccess;
use App\Services\Trajectory\WebPushService;
use Illuminate\Http\Request;

class MobileController extends Controller
{
    public function page(Request $request, string $surface, FinanceAccess $access)
    {
        abort_unless(config('trajectory.enabled') && in_array($surface, ['personal', 'business'], true), 404);
        $spaces = $access->spaces($request->user())->where('kind', $surface === 'personal' ? 'household' : 'business')->values();
        abort_if($spaces->isEmpty(), 403, 'No authorized finance space is available for this app.');

        return response()->view('trajectory.index', ['spaces' => $spaces, 'trajectorySurface' => $surface])->header('Cache-Control', 'private, no-store');
    }

    public function manifest(string $surface)
    {
        abort_unless(config('trajectory.enabled') && in_array($surface, ['personal', 'business'], true), 404);
        $name = 'Trajectory '.ucfirst($surface);

        return response()->json([
            'id' => '/trajectory/'.$surface.'/', 'name' => $name, 'short_name' => $name,
            'start_url' => '/trajectory/'.$surface.'/', 'scope' => '/trajectory/'.$surface.'/',
            'display' => 'standalone', 'background_color' => '#11251f', 'theme_color' => '#21755a',
            'icons' => [
                ['src' => '/trajectory-brand/'.$surface.'-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/trajectory-brand/'.$surface.'-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
            ],
        ])->header('Content-Type', 'application/manifest+json')->header('Cache-Control', 'no-cache');
    }

    public function worker(string $surface)
    {
        abort_unless(config('trajectory.enabled') && in_array($surface, ['personal', 'business'], true), 404);

        return response()->file(resource_path('js/trajectory/finance-sw.js'), [
            'Content-Type' => 'application/javascript', 'Service-Worker-Allowed' => '/trajectory/'.$surface.'/', 'Cache-Control' => 'no-cache',
        ]);
    }

    public function inbox(Request $request, Space $space, FinanceAccess $access, WebPushService $push, ExpenseNotificationService $expenses)
    {
        $access->authorize($request->user(), $space);
        $before = $request->validate(['before' => 'sometimes|integer|min:1', 'transaction_id' => 'sometimes|integer|min:1']);
        $notices = ExpenseNotice::where('space_id', $space->id)->where('user_id', $request->user()->id)
            ->when(isset($before['before']), fn ($q) => $q->where('id', '<', $before['before']))
            ->when(isset($before['transaction_id']), fn ($q) => $q->where('transaction_id', $before['transaction_id']))->orderByDesc('id')->limit(51)->get();
        $more = $notices->count() > 50;
        $rows = $notices->take(50)->map(function ($notice) use ($request, $space, $expenses): array {
            $transaction = Transaction::where('space_id', $space->id)->find($notice->transaction_id);
            $account = $transaction ? \App\Models\Trajectory\Account::where('space_id', $space->id)->find($transaction->account_id) : null;
            $available = $transaction && $account && ! $transaction->removed && $transaction->amount_cents < 0
                && in_array($transaction->flow, ['expense', 'medical_payment', 'medical_membership'], true);
            $payload = $available ? $expenses->payload($request->user(), $space, $transaction) : ['removed' => true, 'unavailable' => true];

            return ['id' => $notice->id, 'payload' => $payload, 'read_at' => $notice->read_at?->toIso8601String(), 'received_at' => $notice->created_at->toIso8601String(), 'delivery_status' => $notice->delivery_status];
        })->values();

        return response()->json([
            'rows' => $rows, 'next_before' => $more ? $rows->last()['id'] : null,
            'unread_count' => ExpenseNotice::where('space_id', $space->id)->where('user_id', $request->user()->id)->whereNull('read_at')->count(),
            'preferences' => $this->preference($request, $space),
            'push' => ['ready' => $push->ready(), 'public_key' => $push->ready() ? config('trajectory_mobile.public_key') : null],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function preferences(Request $request, Space $space, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        abort_unless($space->kind === 'household', 422, 'Personal expense alerts belong to the household app.');
        $data = $request->validate(['enabled' => 'required|boolean', 'include_pending' => 'required|boolean', 'show_details' => 'required|boolean']);
        $preference = AlertPreference::firstOrNew(['space_id' => $space->id, 'user_id' => $request->user()->id]);
        if ($data['enabled'] && ! $preference->enabled) {
            $preference->enabled_at = now();
        }
        $preference->fill($data)->save();

        return response()->json($this->preference($request, $space))->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, Space $space, ExpenseNotice $notice, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        abort_unless($notice->space_id === $space->id && $notice->user_id === $request->user()->id, 404);
        $notice->update(['read_at' => $notice->read_at ?? now()]);

        return response()->json(['ok' => true]);
    }

    public function subscribe(Request $request, Space $space, FinanceAccess $access, WebPushService $push)
    {
        $access->authorize($request->user(), $space);
        abort_unless($space->kind === 'household', 422);
        $data = $request->validate([
            'endpoint' => 'required|string|max:4096', 'keys.p256dh' => 'required|string|max:160',
            'keys.auth' => 'required|string|max:80', 'content_encoding' => 'sometimes|in:aes128gcm,aesgcm',
        ]);
        abort_unless($push->validEndpoint($data['endpoint']), 422, 'Use a supported browser push subscription endpoint.');
        foreach (['p256dh' => 65, 'auth' => 16] as $key => $bytes) {
            $value = $data['keys'][$key];
            $decoded = base64_decode(strtr($value, '-_', '+/'), true);
            abort_unless(preg_match('/^[A-Za-z0-9_=-]+$/D', $value) && $decoded !== false && strlen($decoded) === $bytes, 422, 'Invalid browser push subscription key.');
        }
        $subscription = PushSubscription::updateOrCreate(['user_id' => $request->user()->id, 'endpoint_hash' => hash('sha256', $data['endpoint'])], [
            'space_id' => $space->id, 'endpoint' => $data['endpoint'], 'keys' => $data['keys'],
            'content_encoding' => $data['content_encoding'] ?? 'aes128gcm', 'disabled_at' => null,
        ]);

        return response()->json(['id' => $subscription->id, 'ready' => $push->ready()], 201);
    }

    public function subscriptionStatus(Request $request, Space $space, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        abort_unless($space->kind === 'household', 422);
        $data = $request->validate(['endpoint_hash' => 'required|string|size:64|regex:/^[a-f0-9]{64}$/D']);
        $registered = PushSubscription::where('space_id', $space->id)->where('user_id', $request->user()->id)
            ->where('endpoint_hash', $data['endpoint_hash'])->whereNull('disabled_at')->exists();

        return response()->json(['registered' => $registered])->header('Cache-Control', 'private, no-store');
    }

    public function unsubscribe(Request $request, Space $space, FinanceAccess $access)
    {
        $access->authorize($request->user(), $space);
        $data = $request->validate(['endpoint' => 'required|string|max:4096']);
        PushSubscription::where('space_id', $space->id)->where('user_id', $request->user()->id)->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();

        return response()->json(['ok' => true]);
    }

    private function preference(Request $request, Space $space): array
    {
        $preference = AlertPreference::where('space_id', $space->id)->where('user_id', $request->user()->id)->first();

        return ['enabled' => $preference?->enabled ?? false, 'include_pending' => $preference?->include_pending ?? true, 'show_details' => $preference?->show_details ?? true];
    }
}
