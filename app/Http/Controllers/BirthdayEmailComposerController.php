<?php

namespace App\Http\Controllers;

use App\Services\Marketing\BirthdayEmailComposerService;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedAppCredentials;
use App\Services\Shopify\ShopifyEmbeddedMessagingWorkspaceService;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class BirthdayEmailComposerController extends Controller
{
    public function show(Request $request, ShopifyEmbeddedAppContext $contexts, TenantResolver $tenants, BirthdayEmailComposerService $drafts, ShopifyEmbeddedAppCredentials $credentials): Response
    {
        $context = $contexts->resolvePageContext($request);
        $store = (array) ($context['store'] ?? []);
        $tenantId = ($context['ok'] ?? false) ? $tenants->resolveTenantIdForStoreContext($store) : null;
        $authorized = $tenantId !== null;

        return $this->embedded(response()->view('shopify.birthday-email-composer', [
            'authorized' => $authorized,
            'shopifyApiKey' => $authorized ? ($credentials->clientIdForStore($store) ?? (string) ($store['client_id'] ?? '')) : null,
            'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
            'host' => $context['host'] ?? null,
            'storeLabel' => 'Everbranch Rewards', 'headline' => 'Birthday email designer',
            'subheadline' => 'Build a photo-rich birthday email, preview desktop or mobile, then send a test only to an address you enter.',
            'appNavigation' => ['items' => [
                ['key' => 'rewards', 'label' => 'Rewards', 'href' => route('shopify.app.rewards', [], false), 'children' => []],
                ['key' => 'birthdays', 'label' => 'Birthdays', 'href' => route('shopify.app.rewards.birthdays', [], false), 'children' => []],
                ['key' => 'birthday-email', 'label' => 'Birthday Email', 'href' => route('shopify.app.rewards.birthdays.email', [], false), 'children' => []],
            ], 'workspaceLabel' => 'Everbranch Rewards'],
            'birthdayEmailComposerBootstrap' => [
                'authorized' => $authorized, 'draft' => $authorized ? $drafts->draft($tenantId) : null,
                'context_token' => $authorized ? $contexts->issueContextToken($context) : null,
                'endpoints' => ['save' => route('shopify.app.api.rewards.birthdays.email.save', [], false), 'test_send' => route('shopify.app.api.rewards.birthdays.email.test-send', [], false)],
                'meta' => ['eyebrow' => 'Everbranch Rewards', 'saveNotice' => 'Birthday email design saved.', 'testDescription' => 'Tests go only to addresses entered below. This screen never sends to the birthday audience.'],
            ],
        ]), $authorized ? 200 : 403);
    }

    public function save(Request $request, ShopifyEmbeddedAppContext $contexts, TenantResolver $tenants, BirthdayEmailComposerService $drafts): JsonResponse
    {
        $tenantId = $this->tenant($request, $contexts, $tenants);
        if ($tenantId instanceof JsonResponse) {
            return $tenantId;
        }
        try {
            $input = validator($request->all(), ['subject' => ['required', 'string', 'max:200'], 'sections' => ['required', 'array', 'min:3', 'max:24'], 'personalization' => ['nullable', 'array'], 'revision' => ['required', 'integer', 'min:1']])->validate();

            return response()->json(['ok' => true, 'data' => $drafts->save($tenantId, $input)]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'message' => 'Birthday email could not be saved.', 'errors' => $e->errors()], 422);
        }
    }

    public function testSend(Request $request, ShopifyEmbeddedAppContext $contexts, TenantResolver $tenants, BirthdayEmailComposerService $drafts, ShopifyEmbeddedMessagingWorkspaceService $messaging): JsonResponse
    {
        $tenantId = $this->tenant($request, $contexts, $tenants);
        if ($tenantId instanceof JsonResponse) {
            return $tenantId;
        }
        try {
            $input = validator($request->all(), ['test_emails' => ['required', 'array', 'min:1', 'max:5'], 'test_emails.*' => ['required', 'email:rfc', 'max:190']])->validate();
            $draft = $drafts->draft($tenantId);
            $result = $messaging->sendEmailSmokeTest(tenantId: $tenantId, testEmails: $input['test_emails'], subject: $draft['subject'], body: 'Birthday email designer test.', actorId: auth()->id(), storeKey: 'retail', emailTemplateMode: 'sections', emailTemplateKey: 'birthday_email_composer', emailSections: $drafts->sectionsForTestSend($draft['sections']), emailAdvancedHtml: null);

            return response()->json(['ok' => true, 'message' => 'Test email submitted. No birthday customers were contacted.', 'data' => $result]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'message' => 'Test email could not be sent.', 'errors' => $e->errors()], 422);
        }
    }

    protected function tenant(Request $request, ShopifyEmbeddedAppContext $contexts, TenantResolver $tenants): int|JsonResponse
    {
        $context = $contexts->resolveMutationContext($request);
        if (! ($context['ok'] ?? false)) {
            return response()->json(['ok' => false, 'message' => 'Reload this page from Shopify Admin and try again.'], 401);
        }
        $tenantId = $tenants->resolveTenantIdForStoreContext((array) ($context['store'] ?? []));

        return $tenantId ?? response()->json(['ok' => false, 'message' => 'Birthday email designer is unavailable for this store.'], 403);
    }

    protected function embedded(Response $response): Response
    {
        $response->headers->set('Content-Security-Policy', 'frame-ancestors https://admin.shopify.com https://*.myshopify.com https://*.shopify.com;');
        $response->headers->remove('X-Frame-Options');

        return $response;
    }
}
