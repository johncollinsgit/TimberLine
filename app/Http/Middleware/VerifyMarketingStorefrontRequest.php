<?php

namespace App\Http\Middleware;

use App\Services\Marketing\MarketingStorefrontEventLogger;
use App\Services\Marketing\MarketingStorefrontResponseFactory;
use App\Services\Marketing\MarketingStorefrontRequestVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyMarketingStorefrontRequest
{
    public function __construct(
        protected MarketingStorefrontRequestVerifier $verifier,
        protected MarketingStorefrontResponseFactory $responseFactory,
        protected MarketingStorefrontEventLogger $eventLogger
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $result = $this->verifier->verify($request);
        if (! (bool) ($result['ok'] ?? false)) {
            $reason = (string) ($result['reason'] ?? 'invalid_storefront_signature');

            $this->eventLogger->log('widget_verification_failed', [
                'status' => 'error',
                'issue_type' => 'signature_verification_failed',
                'source_surface' => 'shopify_widget',
                'endpoint' => '/' . ltrim((string) $request->path(), '/'),
                'request_key' => substr(hash('sha1', (string) $request->fullUrl() . '|' . (string) $request->ip()), 0, 40),
                'meta' => [
                    'reason' => $reason,
                    'method' => strtoupper((string) $request->getMethod()),
                    'ip' => (string) $request->ip(),
                ],
            ]);

            if ($request->expectsJson() || $request->is('shopify/marketing/*')) {
                return $this->responseFactory->error(
                    code: 'unauthorized_storefront_request',
                    message: 'Storefront request signature validation failed.',
                    status: 401,
                    details: ['reason' => $reason],
                    states: ['verification_required'],
                    recoveryStates: ['contact_support']
                );
            }

            abort(401);
        }

        $request->attributes->set('marketing_storefront_auth_mode', (string) ($result['mode'] ?? 'unknown'));

        return $next($request);
    }
}
