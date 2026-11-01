<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Pos\PosException;
use App\Services\Pos\PosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class PosController extends Controller
{
    public function __construct(private PosService $pos) {}

    public function registers(Request $r): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->registers());
    }

    public function catalog(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->catalog($register));
    }

    public function checkoutRules(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, [], fn () => app(\App\Services\Pos\PosCommerceRuleStore::class)->get($this->checkoutRuleScope($register, false)));
    }

    public function rewardRedemption(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, [], fn () => app(\App\Services\Pos\PosRewardPolicyManager::class)->get(
            app(\App\Services\Pos\PosAccess::class)->register($register, 'admin')));
    }

    public function saveRewardRedemption(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, ['draft' => ['required', 'array:enabled,max_minor,channel,refund_policy'],
            'draft.enabled' => ['required', 'boolean'], 'draft.max_minor' => ['required', 'integer', 'min:1', 'max:100000000'],
            'draft.channel' => ['required', 'in:in_person'], 'draft.refund_policy' => ['required', 'in:restore_full_review_partial'],
            'expected_draft_version' => ['required', 'regex:/^[a-f0-9]{64}$/D']],
            fn ($input) => app(\App\Services\Pos\PosRewardPolicyManager::class)->save(
                app(\App\Services\Pos\PosAccess::class)->register($register, 'admin'), $input['draft'], $input['expected_draft_version']));
    }

    public function publishRewardRedemption(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, ['draft_version' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'expected_published_version' => ['present', 'nullable', 'regex:/^[a-f0-9]{64}$/D'],
            'expected_register_version' => ['present', 'nullable', 'regex:/^[a-f0-9]{64}$/D'],
            'expected_program_version' => ['present', 'nullable', 'regex:/^[a-f0-9]{64}$/D'],
            'confirmed' => ['required', 'boolean']], fn ($input) => app(\App\Services\Pos\PosRewardPolicyManager::class)->publish(
                app(\App\Services\Pos\PosAccess::class)->register($register, 'admin'), $input['draft_version'],
                $input['expected_published_version'], $input['expected_register_version'], $input['expected_program_version'], $input['confirmed'] === true));
    }

    public function availableCheckoutRules(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, [], fn () => app(\App\Services\Pos\PosCheckoutOffers::class)->available(app(\App\Services\Pos\PosAccess::class)->register($register)));
    }

    public function reviewCheckoutRules(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, [], fn () => app(\App\Services\Pos\PosCommerceRuleStore::class)->reviewStatus($this->checkoutRuleScope($register, false), $register));
    }

    public function reconcileOnlineCheckoutRule(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, ['rule_id' => ['required', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/D']],
            fn ($input) => app(\App\Services\Pos\PosShopifyOnlineDiscount::class)->reconcile($this->checkoutRuleScope($register), $register, $input['rule_id']));
    }

    public function taxLocations(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, [], fn () => app(\App\Services\Pos\PosTaxLocations::class)->options(app(\App\Services\Pos\PosAccess::class)->register($register)));
    }

    public function previewTaxLocation(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, ['key' => ['required', 'regex:/^(event|shopify):[0-9]+$/D']],
            fn ($input) => app(\App\Services\Pos\PosTaxLocations::class)->preview(app(\App\Services\Pos\PosAccess::class)->register($register, 'admin'), $input['key']));
    }

    public function publishTaxLocation(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, ['key' => ['required', 'regex:/^(event|shopify):[0-9]+$/D'],
            'version' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'previous_version' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'valid_until' => ['required', 'date_format:Y-m-d\\TH:i:s\\Z'], 'confirmed' => ['required', 'boolean']],
            fn ($input) => app(\App\Services\Pos\PosTaxLocations::class)->publish(app(\App\Services\Pos\PosAccess::class)->register($register, 'admin'),
                $input['key'], $input['version'], $input['previous_version'], $input['valid_until'], $input['confirmed'] === true));
    }

    public function publishCheckoutRules(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, ['version' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'previous_reviewed_version' => ['present', 'nullable', 'regex:/^[a-f0-9]{64}$/D'], 'confirmed' => ['required', 'boolean']],
            fn ($input) => app(\App\Services\Pos\PosCommerceRuleStore::class)->publish($this->checkoutRuleScope($register), $register,
                $input['version'], $input['previous_reviewed_version'], $input['confirmed'] === true));
    }

    public function reviewOnlineDiscount(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, $this->onlineDiscountValidation(false), fn ($input) => app(\App\Services\Pos\PosShopifyOnlineDiscount::class)
            ->review($this->checkoutRuleScope($register, false), $register, $input['version'], $input['rule_id'], $input['code'], $input['starts_at'], $input['public_eligibility_confirmed'] === true));
    }

    public function publishOnlineDiscount(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, $this->onlineDiscountValidation(true), function ($input) use ($register): array {
            if ($input['confirmed'] !== true) {
                throw new PosException('checkout_rules_confirmation_required', 422);
            }

            return app(\App\Services\Pos\PosShopifyOnlineDiscount::class)->publish($this->checkoutRuleScope($register), $register,
                $input['version'], $input['rule_id'], $input['code'], $input['starts_at'], $input['request_id'], $input['public_eligibility_confirmed'] === true);
        });
    }

    private function onlineDiscountValidation(bool $publish): array
    {
        return ['version' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'rule_id' => ['required', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/D'],
            'code' => ['required', 'regex:/^[A-Z0-9][A-Z0-9_-]{2,31}$/D'], 'starts_at' => ['required', 'date_format:Y-m-d\\TH:i:s\\Z'],
            'public_eligibility_confirmed' => ['required', 'boolean'], ...($publish ? ['request_id' => ['required', 'uuid'], 'confirmed' => ['required', 'boolean']] : [])];
    }

    public function saveCheckoutRule(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, ['version' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'rule' => ['required', 'array']],
            fn ($input) => app(\App\Services\Pos\PosCommerceRuleStore::class)->save($this->checkoutRuleScope($register), $input['rule'], $input['version']));
    }

    public function deleteCheckoutRule(Request $r, string $tenant, string $register, string $rule): JsonResponse
    {
        return $this->respond($r, ['version' => ['required', 'regex:/^[a-f0-9]{64}$/D']],
            fn ($input) => app(\App\Services\Pos\PosCommerceRuleStore::class)->delete($this->checkoutRuleScope($register), $rule, $input['version']));
    }

    private function checkoutRuleScope(string $register, bool $write = true): array
    {
        $scope = app(\App\Services\Pos\PosAccess::class)->register($register, $write ? 'admin' : 'read');

        return ['tenant_id' => (int) $scope['tenant_id'], 'actor_id' => (int) $scope['actor_id'],
            'admin' => in_array($scope['role'], ['owner', 'tenant_owner', 'admin'], true)];
    }

    public function countStock(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, ['request_id' => ['required', 'uuid'], 'item_id' => ['required', 'regex:/^[a-f0-9]{32}$/D'],
            'quantity' => ['required', 'integer', 'min:0', 'max:10000000'], 'expected_version' => ['required', 'integer', 'min:0'],
            'physically_confirmed' => ['required', 'boolean']], function ($input) use ($register): array {
                $scope = app(\App\Services\Pos\PosAccess::class)->register($register, 'quote');
                if (! in_array($scope['role'], ['owner', 'tenant_owner', 'admin'], true)) {
                    throw new PosException('inventory_owner_required', 403);
                }
                if (! is_int($input['quantity']) || ! is_int($input['expected_version']) || $input['physically_confirmed'] !== true) {
                    throw new PosException('physical_count_confirmation_required', 422);
                }
                $settings = app(\App\Services\Pos\StockPosCommerce::class)->settings($scope);
                app(\App\Services\Inventory\StockLedger::class)->count($scope['tenant_id'], 'mobile:'.$input['request_id'], $scope['actor_id'],
                    $settings['inventory_pool_id'], [$input['item_id'] => $input['quantity']], 'physical_count', [$input['item_id'] => $input['expected_version']]);
                app(\App\Services\Pos\StockPosCommerce::class)->queuePublications($scope['tenant_id']);

                return $this->pos->catalog($register);
            });
    }

    public function customers(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, ['query' => ['required', 'string', 'min:2', 'max:120', 'regex:/\A[^\p{C}]+\z/u']],
            fn ($input) => app(\App\Services\Pos\PosCustomerDirectory::class)->search($register, $input['query']));
    }

    public function customerCommerce(Request $r, string $tenant, string $register, string $profile): JsonResponse
    {
        return $this->respond($r, [], fn () => app(\App\Services\Pos\PosCustomerDirectory::class)->commerce($register, (int) $profile));
    }

    public function customerEnrollmentSettings(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, [], fn () => app(\App\Services\Pos\PosCustomerEnrollment::class)->settings($register));
    }

    public function walletLinkReview(Request $r, string $tenant, string $register, string $reference): JsonResponse
    {
        return $this->respond($r, [], fn () => app(\App\Services\Pos\PosExistingWalletReview::class)
            ->reviewDetails(app(\App\Services\Pos\PosAccess::class)->register($register), $reference));
    }

    public function recordWalletLinkReview(Request $r, string $tenant, string $register, string $reference): JsonResponse
    {
        return $this->respond($r, ['customer_profile_id' => ['required', 'integer', 'min:1'],
            'evidence' => ['required', 'in:owner_attests_existing_wallet_session,owner_verified_independent_evidence'],
            'confirmed' => ['required', 'boolean'], 'disclosure_hash' => ['required', 'regex:/^[a-f0-9]{64}$/D']], fn ($input) => app(\App\Services\Pos\PosExistingWalletReview::class)
                ->review(app(\App\Services\Pos\PosAccess::class)->register($register, 'admin'), $reference,
                    $input['customer_profile_id'], $input['evidence'], $input['confirmed'] === true, $input['disclosure_hash']));
    }

    public function configureCustomerEnrollment(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, ['version' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'birthday_enabled' => ['required', 'boolean'], 'email_enabled' => ['required', 'boolean'],
            'email_wording' => ['nullable', 'string', 'max:1000', 'regex:/\A[^\p{C}]*\z/u'],
            'sms_enabled' => ['required', 'boolean'], 'sms_wording' => ['nullable', 'string', 'max:1000', 'regex:/\A[^\p{C}]*\z/u'],
            'cashier_create_enabled' => ['sometimes', 'boolean'],
            'confirmed' => ['required', 'boolean']], function ($input) use ($register): array {
                foreach (['birthday_enabled', 'email_enabled', 'sms_enabled', 'confirmed'] as $key) {
                    if (! is_bool($input[$key])) {
                        throw new PosException('invalid_request', 422);
                    }
                }
                if (isset($input['cashier_create_enabled']) && ! is_bool($input['cashier_create_enabled'])) {
                    throw new PosException('invalid_request', 422);
                }

                return app(\App\Services\Pos\PosCustomerEnrollment::class)->configure($register, $input);
            });
    }

    public function enrollCustomer(Request $r, string $tenant, string $register, string $profile): JsonResponse
    {
        return $this->respond($r, ['request_id' => ['required', 'uuid'], 'version' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'birthday' => ['sometimes', 'array:month,day'], 'birthday.month' => ['required_with:birthday', 'integer', 'between:1,12'],
            'birthday.day' => ['required_with:birthday', 'integer', 'between:1,31'], 'email_opt_in' => ['sometimes', 'boolean'],
            'sms_opt_in' => ['sometimes', 'boolean']], function ($input) use ($register, $profile): array {
                foreach (['email_opt_in', 'sms_opt_in'] as $key) {
                    if (isset($input[$key]) && ! is_bool($input[$key])) {
                        throw new PosException('invalid_request', 422);
                    }
                }
                if (isset($input['birthday']) && (! isset($input['birthday']['month'], $input['birthday']['day'])
                    || ! is_int($input['birthday']['month']) || ! is_int($input['birthday']['day']))) {
                    throw new PosException('invalid_request', 422);
                }

                return app(\App\Services\Pos\PosCustomerEnrollment::class)->enroll($register, (int) $profile, $input);
            });
    }

    public function createCustomer(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, ['request_id' => ['required', 'uuid'],
            'first_name' => ['required', 'string', 'max:120', 'regex:/\A[^\p{C}]+\z/u'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/\A[^\p{C}]*\z/u'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/\A[^\p{C}]*\z/u']],
            fn ($input) => app(\App\Services\Pos\PosCustomerDirectory::class)->create($register, $input));
    }

    public function customerRequest(Request $r, string $tenant, string $register, string $requestId): JsonResponse
    {
        return $this->respond($r, [], fn () => app(\App\Services\Pos\PosCustomerDirectory::class)->request($register, $requestId));
    }

    public function approvedQuotes(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->approvedQuotes($register));
    }

    public function authorizeDevice(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->authorizeDevice($register));
    }

    public function requestStatus(Request $r, string $tenant, string $register, string $requestId): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->requestStatus($register, $requestId));
    }

    public function quote(Request $r, string $tenant, string $register): JsonResponse
    {
        return $this->respond($r, [
            'request_id' => ['required', 'uuid'], 'source_order_id' => ['sometimes', 'integer', 'min:1', 'prohibits:items,custom_charge,discounts,addons,reward_redemption'],
            'items' => ['required_without_all:source_order_id,custom_charge', 'array', 'min:1', 'max:50'],
            'custom_charge' => ['sometimes', 'array:description,amount_minor'],
            'custom_charge.description' => ['required_with:custom_charge', 'string', 'min:1', 'max:150', 'regex:/\A[^\p{C}]+\z/u'],
            'custom_charge.amount_minor' => ['required_with:custom_charge', 'integer', 'min:1', 'max:100000000'],
            'items.*' => ['array:item_id,line_id,quantity,revision,components'], 'items.*.item_id' => ['required', 'string', 'max:100'],
            'items.*.line_id' => ['sometimes', 'regex:/^[a-f0-9]{32}$/D', 'distinct'],
            'items.*.components' => ['sometimes', 'array', 'min:1', 'max:100'], 'items.*.components.*' => ['array:item_id,quantity,revision'],
            'items.*.components.*.item_id' => ['required', 'regex:/^[a-f0-9]{32}$/D'],
            'items.*.components.*.quantity' => ['required', 'integer', 'min:1', 'max:9900'], 'items.*.components.*.revision' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'], 'items.*.revision' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'checkout_rules_version' => ['required_with:discounts,addons', 'regex:/^[a-f0-9]{64}$/D'],
            'discounts' => ['sometimes', 'array', 'max:50'], 'discounts.*' => ['array:rule_id,scope,item_id,quantity'],
            'discounts.*.rule_id' => ['required', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/D'],
            'discounts.*.scope' => ['required', 'in:item,sale'], 'discounts.*.item_id' => ['sometimes', 'regex:/^(?:[a-f0-9]{32}|custom)$/D'],
            'discounts.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'addons' => ['sometimes', 'array', 'max:50'], 'addons.*' => ['array:rule_id,item_id,quantity'],
            'addons.*.rule_id' => ['required', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/D'],
            'addons.*.item_id' => ['required', 'regex:/^[a-f0-9]{32}$/D'], 'addons.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'customer_profile_id' => ['sometimes', 'integer', 'min:1'],
            'reward_redemption' => ['sometimes', 'array:proof,amount_minor'],
            'reward_redemption.proof' => ['required_with:reward_redemption', 'string', 'regex:/^everbranch-reward:v1:[A-Za-z0-9_-]{43}$/D'],
            'reward_redemption.amount_minor' => ['required_with:reward_redemption', 'integer', 'min:1', 'max:100000000'],
            'customer' => ['sometimes', 'nullable', 'array:name,email'], 'customer.name' => ['sometimes', 'string', 'max:150'],
            'customer.email' => ['sometimes', 'email:rfc', 'max:254'],
        ], fn ($input) => $this->pos->quote($register, $input));
    }

    public function discard(Request $r, string $tenant, string $register, string $requestId): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->discard($register, $requestId));
    }

    public function leaveForReview(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, ['sdk_attempt_id' => ['required', 'uuid'], 'sdk_flow_ended' => ['required', 'boolean'],
            'no_card_presented' => ['required', 'boolean']], fn ($input) => $this->pos->leaveForReview($register, $sale, $input));
    }

    public function attachCustomer(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, ['request_id' => ['required', 'uuid'], 'customer_profile_id' => ['sometimes', 'integer', 'min:1'],
            'name' => ['sometimes', 'nullable', 'string', 'max:150', 'regex:/\A[^\p{C}]*\z/u'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/\A[^\p{C}]*\z/u'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'reward_evidence' => ['sometimes', 'array:member_at_purchase,prior_confirmed_orders,reason'],
            'reward_evidence.member_at_purchase' => ['required_with:reward_evidence', 'boolean'],
            'reward_evidence.prior_confirmed_orders' => ['required_with:reward_evidence', 'integer', 'min:0', 'max:1000000'],
            'reward_evidence.reason' => ['required_with:reward_evidence', 'string', 'min:5', 'max:500']], function ($input) use ($register, $sale): array {
                $scope = app(\App\Services\Pos\PosAccess::class)->register($register, 'quote');
                $row = $this->pos->sale($scope, $sale);
                $result = app(\App\Services\Pos\PosReceiptCustomers::class)->attach($scope, $row, $this->pos->snapshot($row), $input);

                return [...$result, 'receipt' => $this->pos->receipt($register, $sale)];
            });
    }

    public function start(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->start($register, $sale));
    }

    public function show(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->inspect($register, $sale));
    }

    public function receipt(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, [], fn () => $this->pos->receipt($register, $sale));
    }

    public function receiptEmailStatus(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, [], fn () => app(\App\Services\Pos\PosReceiptEmail::class)->status($register, $sale));
    }

    public function sendReceiptEmail(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, [], fn () => app(\App\Services\Pos\PosReceiptEmail::class)->queue($register, $sale));
    }

    public function reconcile(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, ['payment_id' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_-]{1,100}$/D']],
            fn ($input) => $this->pos->reconcile($register, $sale, $input['payment_id'] ?? null));
    }

    public function refund(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, ['request_id' => ['required', 'uuid'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['required', 'in:requested_by_customer,duplicate,other']], fn ($input) => $this->pos->refund($register, $sale, $input));
    }

    public function refundRewardsReview(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, [], fn () => app(\App\Services\Pos\PosRefundReview::class)->preview(
            app(\App\Services\Pos\PosAccess::class)->register($register, 'admin'), $sale));
    }

    public function refundRewardsReviewStatus(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, [], fn () => app(\App\Services\Pos\PosRefundReview::class)->status(
            app(\App\Services\Pos\PosAccess::class)->register($register, 'admin'), $sale));
    }

    public function approveRefundRewardsReview(Request $r, string $tenant, string $register, string $sale): JsonResponse
    {
        return $this->respond($r, ['request_id' => ['required', 'uuid'], 'review_version' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'reason' => ['required', 'string', 'max:500'], 'confirmed' => ['required', 'boolean'],
            'allocation' => ['required', 'array:lines,tax_minor'], 'allocation.tax_minor' => ['required', 'integer', 'min:0', 'max:100000000'],
            'allocation.lines' => ['present', 'array', 'max:51'], 'allocation.lines.*' => ['array:line_id,units'],
            'allocation.lines.*.line_id' => ['required', 'regex:/^(?:custom|[a-f0-9]{32})$/D'],
            'allocation.lines.*.units' => ['required', 'array', 'min:1', 'max:99'],
            'allocation.lines.*.units.*' => ['required', 'integer', 'min:0', 'max:98']],
            fn ($input) => app(\App\Services\Pos\PosRefundReview::class)->approve(
                app(\App\Services\Pos\PosAccess::class)->register($register, 'admin'), $sale, $input));
    }

    private function respond(Request $request, array $rules, callable $action): JsonResponse
    {
        try {
            $input = $request->all();
            $top = array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'));
            if (array_diff(array_keys($input), $top) || Validator::make($input, $rules)->fails()) {
                throw new PosException('invalid_request', 422);
            }
            foreach (['amount_minor', 'source_order_id', 'customer_profile_id'] as $key) {
                if (isset($input[$key]) && ! is_int($input[$key])) {
                    throw new PosException('invalid_request', 422);
                }
            }
            foreach ($input['items'] ?? [] as $item) {
                if (! is_int($item['quantity'])) {
                    throw new PosException('invalid_request', 422);
                }
                foreach ($item['components'] ?? [] as $part) {
                    if (! is_int($part['quantity'])) {
                        throw new PosException('invalid_request', 422);
                    }
                }
            }
            if (isset($input['custom_charge']) && ! is_int($input['custom_charge']['amount_minor'])) {
                throw new PosException('invalid_request', 422);
            }
            if (isset($input['reward_redemption']) && ! is_int($input['reward_redemption']['amount_minor'])) {
                throw new PosException('invalid_request', 422);
            }
            if (isset($input['reward_evidence']) && (! is_bool($input['reward_evidence']['member_at_purchase']) || ! is_int($input['reward_evidence']['prior_confirmed_orders']))) {
                throw new PosException('invalid_request', 422);
            }
            $body = ['contract_version' => 1, ...$action($input)];
            $status = 200;
        } catch (PosException $error) {
            $body = ['contract_version' => 1, 'error' => ['code' => $error->blocker]];
            $status = $error->status;
        } catch (\App\Services\Inventory\StockException $error) {
            $body = ['contract_version' => 1, 'error' => ['code' => $error->reason]];
            $status = $error->status;
        } catch (\DomainException) {
            $body = ['contract_version' => 1, 'error' => ['code' => 'operation_requires_review']];
            $status = 409;
        }

        return response()->json($body, $status, ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }
}
