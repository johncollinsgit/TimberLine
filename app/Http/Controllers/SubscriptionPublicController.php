<?php

namespace App\Http\Controllers;

use App\Services\Subscriptions\SubscriptionModuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class SubscriptionPublicController extends Controller
{
    public function showPoll(Request $request, SubscriptionModuleService $subscriptions, int $poll, string $token): Response
    {
        $pollRow = DB::table('subscription_polls')
            ->where('id', $poll)
            ->where('share_token', $token)
            ->first();

        abort_if(! $pollRow, 404);

        return response()->view('subscriptions.public-poll', [
            'poll' => $subscriptions->pollPayload((int) $pollRow->id),
            'tenantId' => (int) $pollRow->tenant_id,
            'shareToken' => (string) $pollRow->share_token,
        ]);
    }

    public function requestVoteCode(Request $request, SubscriptionModuleService $subscriptions, int $poll, string $token): JsonResponse
    {
        $pollRow = DB::table('subscription_polls')
            ->where('id', $poll)
            ->where('share_token', $token)
            ->first();

        if (! $pollRow) {
            return response()->json(['ok' => false, 'status' => 'poll_not_found'], 404);
        }

        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:190'],
            'source' => ['nullable', 'string', 'max:40'],
        ]);

        $payload = $subscriptions->requestVoteCode(
            (int) $pollRow->tenant_id,
            (int) $pollRow->id,
            (string) $validated['identifier'],
            (string) ($validated['source'] ?? 'facebook')
        );

        return response()->json($payload, (bool) ($payload['ok'] ?? false) ? 200 : 422);
    }

    public function castVote(Request $request, SubscriptionModuleService $subscriptions, int $poll, string $token): JsonResponse
    {
        $pollRow = DB::table('subscription_polls')
            ->where('id', $poll)
            ->where('share_token', $token)
            ->first();

        if (! $pollRow) {
            return response()->json(['ok' => false, 'status' => 'poll_not_found'], 404);
        }

        $validated = $request->validate([
            'option_id' => ['required', 'integer'],
            'verification_token_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:20'],
            'source' => ['nullable', 'string', 'max:40'],
        ]);

        $payload = $subscriptions->castVoteWithCode(
            (int) $pollRow->tenant_id,
            (int) $pollRow->id,
            (int) $validated['option_id'],
            (int) $validated['verification_token_id'],
            (string) $validated['code'],
            (string) ($validated['source'] ?? 'facebook')
        );

        return response()->json($payload, (bool) ($payload['ok'] ?? false) ? 200 : 422);
    }
}
