<?php

namespace App\Http\Controllers\HighLevel;

use App\Http\Controllers\Controller;
use App\Models\HighLevel\EmbeddedSession;
use App\Models\HighLevel\Installation;
use App\Services\FleetTracking\FleetTrackingAccessService;
use App\Services\HighLevel\EmbeddedSessionService;
use App\Services\HighLevel\FleetService;
use App\Services\HighLevel\OAuthStateService;
use App\Services\Integrations\Bouncie\BouncieConnector;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FleetController extends Controller
{
    public function bootstrap(Request $request, FleetService $fleet)
    {
        $install = $this->installation($request);
        // Connection and settings remain available during suspension so an admin
        // can recover; unpaid installations receive no vehicle/location data.
        $data = $fleet->bootstrap($install);
        if (! $install->hasSubscriptionAccess()) {
            $data['vehicles'] = [];
        }

        return response()->json($data);
    }

    public function devices(Request $request, FleetService $fleet)
    {
        $install = $this->installation($request);
        abort_unless($install->collectionAllowed(), 403, 'Fleet has not been activated for this account.');
        $selected = \App\Models\FleetTrackingDevice::forTenantId($install->tenant_id)->where('provider', 'bouncie')->where('status', 'active')->pluck('external_device_id');
        $vehicles = collect($fleet->available($install))->filter(fn ($v) => is_string($v['imei'] ?? null))->map(fn ($v) => [
            'id' => $v['imei'], 'name' => (string) ($v['nickName'] ?? $v['name'] ?? 'Vehicle '.$v['imei']), 'selected' => $selected->contains($v['imei'])])->values();

        return response()->json(['devices' => $vehicles, 'limit' => 25]);
    }

    public function select(Request $request, FleetService $fleet)
    {
        $data = $request->validate(['devices' => 'present|array|max:25', 'devices.*' => 'required|string|max:100|distinct']);
        $fleet->select($this->installation($request), $data['devices'], $request->user()->id);

        return response()->json(['saved' => true]);
    }

    public function settings(Request $request, FleetTrackingAccessService $access, LandlordOperatorActionAuditService $audit)
    {
        $install = $this->installation($request);
        abort_unless($install->collectionAllowed(), 403, 'Fleet collection is awaiting activation.');
        $data = $request->validate(['retention_days' => 'required|integer|min:1|max:30', 'tracking_enabled' => 'required|boolean',
            'policy_version' => 'required|string|max:80', 'policy_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/',
            'approval_confirmed' => 'accepted', 'approval_reference' => 'required|string|max:255']);
        DB::transaction(function () use ($install, $data, $request, $access, $audit): void {
            Installation::whereKey($install->id)->lockForUpdate()->firstOrFail();
            abort_unless($install->refresh()->collectionAllowed(), 403);
            $settings = $access->settings($install->tenant);
            $before = $settings->only(['retention_days', 'bouncie_tracking_enabled', 'policy_version']);
            $settings->update(['retention_days' => $data['retention_days'], 'bouncie_tracking_enabled' => $data['tracking_enabled'],
                'phone_tracking_enabled' => false, 'policy_version' => $data['policy_version'], 'policy_sha256' => $data['policy_sha256'],
                'approval_basis' => 'owner', 'approval_reference' => $data['approval_reference'],
                'approved_at' => now(), 'approved_by_user_id' => $request->user()->id]);
            $audit->record($install->tenant_id, $request->user()->id, 'highlevel.fleet.policy', beforeState: $before,
                afterState: $settings->only(['retention_days', 'bouncie_tracking_enabled', 'policy_version']),
                confirmation: ['company_vehicle_policy_confirmed' => true]);
        });

        return response()->json(['saved' => true]);
    }

    public function connect(Request $request, OAuthStateService $states)
    {
        $install = $this->installation($request);
        abort_unless($install->collectionAllowed(), 403, 'Fleet collection is awaiting activation.');
        abort_unless(filled(config('services.fleet_tracking.bouncie_client_id')) && filled(config('services.fleet_tracking.bouncie_client_secret')), 503, 'Bouncie developer configuration is pending.');
        $session = $request->attributes->get('highlevel_session');
        $ticket = $states->issue('bouncie_launch', [], $session);

        return response()->json(['url' => route('highlevel.bouncie.launch', ['ticket' => $ticket])]);
    }

    public function bouncieLaunch(Request $request, OAuthStateService $states, BouncieConnector $connector, EmbeddedSessionService $sessions)
    {
        $ticket = $states->consume('bouncie_launch', (string) $request->query('ticket'));
        $session = EmbeddedSession::findOrFail($ticket->session_id);
        abort_unless(! $session->revoked_at && $session->expires_at->isFuture() && $session->installation->collectionAllowed(), 403);
        $sessions->verify($session->installation, $session->binding->provider_user_id);
        $verifier = Str::random(96);
        $state = $states->issue('bouncie', ['verifier' => $verifier], $session);

        return redirect()->away($connector->buildAuthorizationUrl($session->installation->tenant, ['state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'redirect_uri' => config('highlevel.bouncie_redirect_uri')]));
    }

    public function bouncieCallback(Request $request, OAuthStateService $states, BouncieConnector $connector,
        EmbeddedSessionService $sessions, FleetService $fleet, LandlordOperatorActionAuditService $audit)
    {
        $state = $states->consume('bouncie', (string) $request->query('state'));
        $session = EmbeddedSession::findOrFail($state->session_id);
        abort_unless(! $session->revoked_at && $session->expires_at->isFuture() && ! $session->binding->revoked_at, 403);
        $install = $session->installation;
        abort_unless($install->collectionAllowed(), 403);
        $sessions->verify($install, $session->binding->provider_user_id);
        abort_if($request->filled('error'), 422, 'Bouncie authorization was cancelled.');
        $request->attributes->set('bouncie_code_verifier', $state->payload['verifier'] ?? '');
        $request->attributes->set('bouncie_redirect_uri', config('highlevel.bouncie_redirect_uri'));
        Cache::lock('hl:bouncie:'.$install->id, 90)->block(5, function () use ($connector, $install, $request, $fleet, $session, $audit): void {
            DB::transaction(function () use ($connector, $install, $request, $fleet, $session, $audit): void {
                Installation::whereKey($install->id)->lockForUpdate()->firstOrFail();
                abort_unless($install->refresh()->collectionAllowed(), 403);
                $old = $fleet->connection($install)?->external_account_id;
                try {
                    $connection = $connector->handleCallback($install->tenant, $request);
                } catch (\Throwable) {
                    abort(503, 'Bouncie authorization could not complete. Start the connection again.');
                }
                if ($old && $old !== $connection->external_account_id) {
                    // Replacing accounts invalidates all old mappings before any new
                    // devices can be selected. Retained GPS stays tenant-owned.
                    DB::table('fleet_provider_device_claims')->where('integration_connection_id', $connection->id)->update(['integration_connection_id' => null]);
                    \App\Models\FleetTrackingDevice::forTenantId($install->tenant_id)->where('provider', 'bouncie')->update(['status' => 'inactive', 'uninstalled_at' => now()]);
                }
                $connection->update(['connected_by_user_id' => $session->binding->user_id]);
                $audit->record($install->tenant_id, $session->binding->user_id, 'highlevel.bouncie.connected',
                    targetType: 'integration_connection', targetId: $connection->id);
            });
        });

        return response()->view('highlevel.complete', ['title' => 'Bouncie connected', 'message' => 'Return to Everbranch in your CRM to select your vehicles. You can close this window.', 'notify' => true]);
    }

    public function disconnect(Request $request, FleetService $fleet)
    {
        $fleet->disconnect($this->installation($request));

        return response()->json(['message' => 'Bouncie disconnected and collection stopped. This does not cancel your Bouncie subscription.']);
    }

    private function installation(Request $request): Installation
    {
        return $request->attributes->get('highlevel_session')->installation;
    }
}
