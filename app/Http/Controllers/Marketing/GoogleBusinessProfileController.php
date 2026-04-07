<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\GoogleBusinessProfileLocation;
use App\Services\Marketing\GoogleBusinessProfileConnectionService;
use App\Services\Marketing\GoogleBusinessProfileException;
use App\Services\Marketing\GoogleBusinessProfileReviewSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleBusinessProfileController extends Controller
{
    public function connect(Request $request, GoogleBusinessProfileConnectionService $connectionService): RedirectResponse
    {
        abort_unless(auth()->check(), 403);

        try {
            return redirect()->away($connectionService->buildConnectUrl($request->user()));
        } catch (GoogleBusinessProfileException $exception) {
            return redirect()->route('marketing.candle-cash.settings')
                ->with('toast', ['style' => 'danger', 'message' => $exception->getMessage()]);
        }
    }

    public function callback(Request $request, GoogleBusinessProfileConnectionService $connectionService): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        try {
            $result = $connectionService->connectFromCallback($data['code'], $data['state']);
        } catch (GoogleBusinessProfileException $exception) {
            return redirect()->route('marketing.candle-cash.settings')
                ->with('toast', ['style' => 'danger', 'message' => $exception->getMessage()]);
        }

        $locationCount = $result['locations']->count();
        $message = $locationCount === 1
            ? 'Google Business Profile connected and the location was linked automatically.'
            : 'Google Business Profile connected. Pick the location you want to use for review sync.';

        return redirect()->route('marketing.candle-cash.settings')
            ->with('toast', ['style' => 'success', 'message' => $message]);
    }

    public function status(GoogleBusinessProfileConnectionService $connectionService): JsonResponse
    {
        $status = $connectionService->status();

        return response()->json([
            'ok' => true,
            'data' => [
                'oauth_ready' => (bool) $status['oauth_ready'],
                'enabled' => (bool) $status['enabled'],
                'ready' => (bool) ($status['ready'] ?? false),
                'reason' => (string) ($status['reason'] ?? 'needs_connection'),
                'message' => (string) ($status['message'] ?? ''),
                'storefront_message' => (string) ($status['storefront_message'] ?? ''),
                'effective_mode' => (string) ($status['effective_mode'] ?? 'unavailable'),
                'fallback_mode' => $status['fallback_mode'] ?? null,
                'connection_status' => (string) $status['connection_status'],
                'project_approval_status' => (string) $status['project_approval_status'],
                'linked_account_id' => $status['linked_account_id'],
                'linked_account_display_name' => $status['linked_account_display_name'],
                'linked_location_id' => $status['linked_location_id'],
                'linked_location_title' => $status['linked_location_title'],
                'granted_scopes' => $status['granted_scopes'],
                'last_sync_at' => optional($status['last_sync_at'])->toIso8601String(),
                'last_error_code' => $status['last_error_code'],
                'last_error_message' => $status['last_error_message'],
                'review_url' => $status['review_url'],
                'locations' => collect($status['locations'])->map(fn ($location) => [
                    'id' => (int) $location->id,
                    'account_display_name' => (string) ($location->account_display_name ?? ''),
                    'location_id' => (string) ($location->location_id ?? ''),
                    'title' => (string) ($location->title ?? ''),
                    'is_selected' => (bool) $location->is_selected,
                ])->all(),
            ],
        ]);
    }

    public function disconnect(GoogleBusinessProfileConnectionService $connectionService): RedirectResponse
    {
        $connectionService->disconnect();

        return redirect()->route('marketing.candle-cash.settings')
            ->with('toast', ['style' => 'success', 'message' => 'Google Business Profile disconnected.']);
    }

    public function sync(Request $request, GoogleBusinessProfileReviewSyncService $syncService): RedirectResponse
    {
        try {
            $result = $syncService->sync($request->user());
        } catch (GoogleBusinessProfileException $exception) {
            return redirect()->route('marketing.candle-cash.settings')
                ->with('toast', ['style' => 'danger', 'message' => $exception->getMessage()]);
        }

        $counts = $result['counts'];
        $message = sprintf(
            'Google reviews synced. %d fetched, %d matched, %d awarded.',
            (int) ($counts['fetched'] ?? 0),
            (int) ($counts['matched'] ?? 0),
            (int) ($counts['awarded'] ?? 0)
        );

        return redirect()->route('marketing.candle-cash.settings')
            ->with('toast', ['style' => 'success', 'message' => $message]);
    }

    public function selectLocation(Request $request, GoogleBusinessProfileConnectionService $connectionService): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:google_business_profile_locations,id'],
        ]);

        $location = GoogleBusinessProfileLocation::query()->findOrFail((int) $data['location_id']);
        $connectionService->selectLocation($location);

        return redirect()->route('marketing.candle-cash.settings')
            ->with('toast', ['style' => 'success', 'message' => 'Google Business location linked.']);
    }
}
