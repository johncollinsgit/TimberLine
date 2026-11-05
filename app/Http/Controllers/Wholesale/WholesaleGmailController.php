<?php

namespace App\Http\Controllers\Wholesale;

use App\Http\Controllers\ShopifyEmbeddedAppController;
use App\Services\Shopify\ShopifyAdminIdentityResolver;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Tenancy\TenantResolver;
use App\Services\Wholesale\Gmail\WholesaleGmailConnection;
use App\Services\Wholesale\Gmail\WholesaleGmailJournal;
use App\Services\Wholesale\Gmail\WholesaleGmailSender;
use Illuminate\Http\Request;

class WholesaleGmailController extends ShopifyEmbeddedAppController
{
    private function staff(Request $request): array
    {
        // Fresh Shopify JWT is mandatory. A copied page context or arbitrary tenant ID is not send authority.
        $context = app(ShopifyEmbeddedAppContext::class)->resolveAuthenticatedApiContext($request);
        abort_unless($context['ok'] ?? false, 401, 'Reload Backstage to verify your Shopify session.');
        $tenant = $this->resolvedMappedWholesaleTenantId($context, app(TenantResolver::class));
        abort_unless($tenant, 403);
        if (empty($context['shopify_admin_email'])) {
            $context['shopify_admin_email'] = app(ShopifyAdminIdentityResolver::class)->emailForVerifiedSession($context, (string) $request->bearerToken());
        }
        $actor = $this->resolveWholesaleWorkspaceActor($context, $tenant);
        abort_unless($actor, 403, 'An authorized wholesale operator is required.');
        app(WholesaleGmailJournal::class)->operator($tenant, $actor->id, $context['shop_domain']);

        return [$tenant, $actor->id, $context['shop_domain']];
    }

    public function status(Request $request, WholesaleGmailConnection $gmail)
    {
        [$tenant, , $shop] = $this->staff($request);

        return response()->json($gmail->status($tenant, $shop))->header('Cache-Control', 'private, no-store');
    }

    public function connect(Request $request, WholesaleGmailConnection $gmail)
    {
        [$tenant, $actor, $shop] = $this->staff($request);

        return response()->json(['url' => $gmail->begin($tenant, $actor, $shop)])->header('Cache-Control', 'private, no-store');
    }

    public function disconnect(Request $request, WholesaleGmailConnection $gmail)
    {
        [$tenant, $actor, $shop] = $this->staff($request);
        $gmail->disconnect($tenant, $actor, $shop);

        return response()->json(['message' => 'Gmail disconnected from Backstage.'])->header('Cache-Control', 'private, no-store');
    }

    public function review(Request $request, string $accountKey, WholesaleGmailSender $sender)
    {
        [$tenant, $actor, $shop] = $this->staff($request);
        $data = $request->validate(['subject' => ['required', 'string', 'max:200'], 'message' => ['required', 'string', 'max:4000']]);
        try {
            $result = $sender->review($tenant, $actor, $shop, $accountKey, $data['subject'], $data['message']);
        } catch (\DomainException $error) {
            return response()->json(['message' => $error->getMessage()], 409);
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request, string $reviewId, WholesaleGmailSender $sender)
    {
        [$tenant, $actor, $shop] = $this->staff($request);
        $request->validate(['confirmed' => ['accepted']]);
        try {
            $result = $sender->send($tenant, $actor, $shop, $reviewId);
        } catch (\DomainException $error) {
            return response()->json(['message' => $error->getMessage()], 409);
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function test(Request $request, string $reviewId, WholesaleGmailSender $sender)
    {
        [$tenant, $actor, $shop] = $this->staff($request);
        $request->validate(['confirmed' => ['accepted']]);
        try {
            $result = $sender->send($tenant, $actor, $shop, $reviewId, true);
        } catch (\DomainException $error) {
            return response()->json(['message' => $error->getMessage()], 409);
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function launch(Request $request, string $stateId, WholesaleGmailConnection $gmail)
    {
        return $gmail->launch($request, $stateId)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function callback(Request $request, WholesaleGmailConnection $gmail)
    {
        try {
            $id = $gmail->callback($request);
        } catch (\Throwable) {
            return response('Gmail could not connect. Close this tab and return to Backstage to start again.', 422)
                ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
        }

        return response('Gmail connected. Close this tab and return to Backstage, then choose Check connection.', 200)
            ->withoutCookie($gmail->cookieName($id), '/')->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
