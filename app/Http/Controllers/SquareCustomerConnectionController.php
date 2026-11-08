<?php

namespace App\Http\Controllers;

use App\Models\MarketingIdentityReview;
use App\Models\MarketingImportRun;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\SquareConnectionContext;
use App\Services\Marketing\SquareCustomerIdentityService;
use App\Services\Marketing\SquareMarketingSyncService;
use App\Services\Marketing\TenantCustomerLookup;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Core connection controls reuse the established Square sync, identity reviews and import audits. */
class SquareCustomerConnectionController extends Controller
{
    public function index(Request $request, SquareConnectionContext $connections, SquareCustomerIdentityService $identities)
    {
        [$tenant, $actor] = $this->access($request, $identities);
        $queryInput = $request->query('customer_query');
        $input = validator(['customer_query' => is_string($queryInput) ? trim($queryInput) : $queryInput], [
            'customer_query' => 'nullable|string|min:2|max:120',
        ])->validate();
        $customerQuery = $input['customer_query'] ?? '';
        $lookup = app(TenantCustomerLookup::class);
        $customerSearch = $customerQuery !== '' ? $lookup->search($tenant->id, $customerQuery) : ['customers' => [], 'has_more' => false, 'page' => 1];
        $runs = MarketingImportRun::query()->where('tenant_id', $tenant->id)->whereIn('type', ['square_customers_sync', 'square_orders_sync', 'square_payments_sync', 'square_cards_sync'])->latest('id')->limit(20)->get();
        $reviews = MarketingIdentityReview::query()->where('source_type', 'square_customer')->where('payload->source_context->tenant_id', $tenant->id)->where('status', 'pending')->latest('id')->paginate(25);
        $reviewCustomerChoices = [];
        foreach ($reviews as $review) {
            $candidateIds = collect((array) data_get($review->payload, 'candidate_profile_ids', []))
                ->filter(fn ($id) => (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0)
                ->map(fn ($id) => (int) $id)->unique()->take(100)->values()->all();
            $reviewCustomerChoices[$review->id] = $lookup->search($tenant->id, '', $candidateIds)['customers'];
        }
        $oauth = class_exists(\App\Services\Marketing\SquareCustomerOAuth::class) ? app(\App\Services\Marketing\SquareCustomerOAuth::class)->status($tenant, $actor) : null;

        return response()->view('customers.square-connection', ['tenant' => $tenant, 'connections' => $connections->available($tenant->id), 'runs' => $runs,
            'reviews' => $reviews, 'identities' => $identities, 'oauth' => $oauth,
            'customerQuery' => $customerQuery, 'customerSearch' => $customerSearch, 'reviewCustomerChoices' => $reviewCustomerChoices,
            'counts' => ['customers' => SquareCustomer::where('tenant_id', $tenant->id)->count(), 'orders' => SquareOrder::where('tenant_id', $tenant->id)->count(), 'payments' => SquarePayment::where('tenant_id', $tenant->id)->count()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function refresh(Request $request, SquareMarketingSyncService $sync, SquareCustomerIdentityService $identities)
    {
        [$tenant, $actor] = $this->access($request, $identities);
        $data = $request->validate(['request_id' => 'required|uuid', 'connection_id' => 'required|string|max:120',
            'expected_connection_generation' => 'required|string|max:128', 'sync_type' => 'required|in:customers,orders,payments,cards',
            'mode' => 'required|in:preview,import', 'limit' => 'required|integer|min:1|max:500', 'since' => 'nullable|date', 'resume_run_id' => 'nullable|integer|min:1', 'create_unmatched' => 'sometimes|boolean']);
        try {
            $options = $data + ['tenant_id' => $tenant->id, 'created_by' => $actor->id, 'manual_refresh' => true, 'historical_source_only' => true];
            $options['dry_run'] = $data['mode'] === 'preview';
            $result = match ($data['sync_type']) {
                'customers' => $sync->syncCustomers($options), 'orders' => $sync->syncOrders($options),
                'payments' => $sync->syncPayments($options), 'cards' => $sync->syncCards($options),
            };
            if ($request->expectsJson()) {
                return response()->json($result)->header('Cache-Control', 'private, no-store');
            }

            return $this->back($tenant)->with('toast', ['style' => 'success', 'message' => 'Square source batch recorded. Original checkout, consent and rewards permissions are unchanged.']);
        } catch (\Illuminate\Http\Client\ConnectionException $error) {
            $message = 'Square retrieval was not confirmed. The recorded original page can be retried without creating new source identities.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 503)->header('Cache-Control', 'private, no-store');
            }

            return $this->back($tenant)->withInput()->withErrors(['square' => $message]);
        } catch (RequestException $error) {
            $status = $error->response->status();
            $scope = match ($data['sync_type']) {
                'customers' => 'CUSTOMERS_READ', 'orders' => 'ORDERS_READ', default => 'PAYMENTS_READ'
            };
            $message = $status === 403 ? 'Square did not grant the '.$scope.' read required for this source. The original connection and checkpoint are retained.' : 'Square source retrieval was not confirmed. Check the recorded run and original connection before resuming.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], $status === 403 ? 409 : 503)->header('Cache-Control', 'private, no-store');
            }

            return $this->back($tenant)->withInput()->withErrors(['square' => $message]);
        } catch (HttpException $error) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $error->getMessage()], $error->getStatusCode())->header('Cache-Control', 'private, no-store');
            }
            if (! in_array($error->getStatusCode(), [409, 503], true)) {
                throw $error;
            }

            return $this->back($tenant)->withInput()->withErrors(['square' => $error->getStatusCode() === 503 ? 'Square source access needs connection review. Reconnect the original account if its status requires it.' : $error->getMessage()]);
        }
    }

    public function review(Request $request, SquareCustomerIdentityService $identities)
    {
        [$tenant, $actor] = $this->access($request, $identities);
        $review = MarketingIdentityReview::query()->whereKey($request->route('review'))->where('source_type', 'square_customer')
            ->where('payload->source_context->tenant_id', $tenant->id)->firstOrFail();
        $result = $identities->review($tenant->id, $actor, $review, $request->only(['request_id', 'expected_version', 'decision', 'profile_id', 'identity_confirmed', 'reason']));
        if ($request->expectsJson()) {
            return response()->json($result)->header('Cache-Control', 'private, no-store');
        }

        return $this->back($tenant)->with('toast', ['style' => 'success', 'message' => 'Source identity decision recorded. No account, wallet, historical reward or marketing permission was granted.']);
    }

    private function access(Request $request, SquareCustomerIdentityService $identities): array
    {
        $tenant = $request->attributes->get('current_tenant');
        $actor = $request->user();
        abort_unless($tenant instanceof Tenant && $actor instanceof User, 403);
        $identities->authorize($tenant->id, $actor);

        return [$tenant, $actor];
    }

    private function back(Tenant $tenant)
    {
        return redirect()->route('customers.square.index', ['tenant' => $tenant->slug])->header('Cache-Control', 'private, no-store');
    }
}
