<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionCredentialRevision;
use App\Models\SubscriptionInstallation;
use App\Services\Subscriptions\SubscriptionWebhookInboxService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Authenticate and retain evidence; never project contracts or contact a provider. */
class SubscriptionProviderWebhookController extends Controller
{
    private const TOPICS = [
        'subscription_contracts/create', 'subscription_contracts/update',
        'subscription_billing_attempts/success', 'subscription_billing_attempts/failure',
        'subscription_billing_attempts/challenged', 'customer_payment_methods/create',
        'customer_payment_methods/update', 'customer_payment_methods/revoke',
        'app/uninstalled', 'app/scopes_update',
    ];

    public function __invoke(Request $request, string $publicId, SubscriptionWebhookInboxService $inbox): Response
    {
        $installation = SubscriptionInstallation::query()->where('public_id', $publicId)->firstOrFail();
        abort_unless($installation->verified_at, 404);
        $shop = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));
        abort_unless(preg_match('/^[a-z0-9-]+\.myshopify\.com$/D', $shop)
            && hash_equals($installation->shop_domain, $shop), 401);
        $body = $request->getContent();
        abort_if($body === '' || strlen($body) > 262144, 413);
        $encodedSignature = (string) $request->header('X-Shopify-Hmac-Sha256', '');
        $signature = strlen($encodedSignature) <= 100 ? base64_decode($encodedSignature, true) : false;
        abort_unless(is_string($signature) && strlen($signature) === 32, 401);

        // Only the bound current secret and explicitly unexpired previous revisions
        // may authenticate. Global Shopify credentials are never candidates.
        $now = now()->toImmutable();
        $nowSql = $now->format('Y-m-d H:i:s.u');
        $windowStartSql = $now->subMinutes(15)->format('Y-m-d H:i:s.u');
        $windowSql = (new SubscriptionCredentialRevision)->getConnection()->getDriverName() === 'mysql'
            ? 'app_secret_expires_at <= DATE_ADD(rotated_at, INTERVAL 15 MINUTE)'
            : '(julianday(app_secret_expires_at) - julianday(rotated_at)) <= (15.0 / 1440 + 0.00000001)';
        $eligible = SubscriptionCredentialRevision::query()->forTenantId($installation->tenant_id)
            ->where('installation_id', $installation->id)->whereNotNull('verified_at')
            ->whereNull('revoked_at')->whereNotNull('app_secret')
            ->whereNotNull('app_secret_reference')->whereNotNull('app_secret_revision')
            ->where(function ($query) use ($installation, $nowSql, $windowStartSql, $windowSql): void {
                $query->where(function ($active) use ($installation, $nowSql): void {
                    $active->whereKey($installation->active_credential_revision_id ?? 0)->where('status', 'active')
                        ->where(fn ($expiry) => $expiry->whereNull('app_secret_expires_at')->orWhere('app_secret_expires_at', '>', $nowSql));
                })->orWhere(function ($previous) use ($installation, $nowSql, $windowStartSql, $windowSql): void {
                    $previous->whereKeyNot($installation->active_credential_revision_id ?? 0)
                        ->whereIn('status', ['retired', 'rotated'])->where('rotated_at', '<=', $nowSql)
                        ->where('rotated_at', '>', $windowStartSql)->where('app_secret_expires_at', '>', $nowSql)
                        ->whereRaw($windowSql);
                });
            });
        // Token revisions may share one signing key. Count distinct approved
        // signing revisions, retaining the pinned current token's record first.
        $candidateIds = (clone $eligible)
            ->selectRaw('COALESCE(MAX(CASE WHEN id = ? THEN id END), MAX(id))', [$installation->active_credential_revision_id ?? 0])
            ->groupBy('app_secret_reference', 'app_secret_revision');
        $candidates = $eligible->whereIn('id', $candidateIds)
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$installation->active_credential_revision_id ?? 0])
            ->orderByDesc('revision')->limit(3)->get();
        abort_if($candidates->isEmpty(), 503, 'Subscription webhook setup is incomplete.');
        $verified = null;
        foreach ($candidates as $candidate) {
            if ($candidate->app_secret_expires_at && ! $candidate->app_secret_expires_at->gt($now)) {
                continue;
            }
            if ($candidate->id !== $installation->active_credential_revision_id
                && (! $candidate->rotated_at || ! $candidate->app_secret_expires_at
                    || $candidate->rotated_at->gt($now) || ! $candidate->app_secret_expires_at->gt($now)
                    || $candidate->app_secret_expires_at->gt($candidate->rotated_at->addMinutes(15)))) {
                continue;
            }
            $secret = $candidate->app_secret;
            if (is_string($secret) && strlen($secret) >= 16 && strlen($secret) <= 4096
                && $candidate->app_secret_reference && $candidate->app_secret_revision
                && hash_equals(hash_hmac('sha256', $body, $secret, true), $signature)) {
                $verified = $candidate;
                break;
            }
        }
        unset($secret);
        abort_unless($verified, 401);
        $topic = (string) $request->header('X-Shopify-Topic', '');
        abort_unless(in_array($topic, self::TOPICS, true), 422);
        $version = (string) $request->header('X-Shopify-Api-Version', '');
        $eventId = (string) $request->header('X-Shopify-Event-Id', '');
        $reason = $version !== $installation->api_version ? 'unsupported_api_version'
            : (! json_validate($body) ? 'malformed_payload'
                : (! config('subscriptions.background_reads_enabled', false) ? 'background_reads_paused' : null));
        $row = $inbox->persist(
            $installation->tenant_id, $installation->id,
            (string) $request->header('X-Shopify-Webhook-Id', ''), $eventId === '' ? null : $eventId,
            $topic, $version, $body, $request->headers->all(), $reason,
            signingEvidence: ['app_secret_reference' => $verified->app_secret_reference, 'app_secret_revision' => $verified->app_secret_revision],
        );

        return response('Accepted.', $row->status === 'quarantined' ? 202 : 200)
            ->header('Cache-Control', 'no-store');
    }
}
