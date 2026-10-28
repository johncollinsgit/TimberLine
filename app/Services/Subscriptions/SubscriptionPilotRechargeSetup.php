<?php

namespace App\Services\Subscriptions;

use App\Models\IntegrationConnection;
use App\Models\ShopifyStore;
use App\Models\SubscriptionRechargeConnection;
use App\Models\SubscriptionRechargeCredentialRevision;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionPilotRechargeSetup
{
    public function connect(int $tenantId,User $actor,int $storeId,string $token): array
    {
        app(SubscriptionAccessService::class)->assertManager($tenantId,$actor);
        abort_unless(app(\App\Services\Billing\TenantPaymentSetupAccess::class)->allows(Tenant::findOrFail($tenantId),$actor),403);
        abort_unless(SubscriptionRechargeOperationService::enabled($tenantId) && config('subscriptions.pilot_setup_enabled')
            && in_array($tenantId,config('subscriptions.pilot_tenant_ids',[]),true),423,'The controlled read-only connection is not enabled.');
        abort_unless(preg_match('/^[\x21-\x7e]{16,4096}$/D',$token),422,'Enter a private read-only Recharge API token.');
        $store=ShopifyStore::query()->forTenantId($tenantId)->findOrFail($storeId);
        $candidate=new SubscriptionRechargeConnection(['id'=>0,'tenant_id'=>$tenantId,'api_version'=>SubscriptionRechargeTransport::API_VERSION]);
        $revision=new SubscriptionRechargeCredentialRevision(['tenant_id'=>$tenantId,'connection_id'=>0,'credentials'=>['access_token'=>$token]]);
        $transport=app(SubscriptionRechargeTransport::class);
        $identity=$transport->read($candidate,$revision,'store')['store']??[];
        abort_unless(($identity['identifier']??null)===$store->shop_domain && ($identity['external_platform']??null)==='shopify'
            && preg_match('/^[1-9][0-9]*$/D',(string)($identity['id']??'')),409,'This credential does not belong to the selected Shopify store.');
        $information=$transport->read($candidate,$revision,'token_information')['token_information']??[];
        $scopes=$information['scopes']??null;
        abort_unless(is_array($scopes) && array_is_list($scopes) && !array_diff(['read_store','read_customers','read_subscriptions','read_orders'],$scopes)
            && collect($scopes)->every(fn($scope)=>is_string($scope) && preg_match('/^read_[a-z_]+$/D',$scope)),422,'Use a read-only token with store, customer, subscription and order access.');
        $source=DB::transaction(function()use($tenantId,$actor,$store,$token,$scopes) {
            Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            abort_unless(app(\App\Services\Billing\TenantPaymentSetupAccess::class)->allows(Tenant::findOrFail($tenantId),$actor->fresh()),403);
            $currentStore=ShopifyStore::query()->forTenantId($tenantId)->findOrFail($store->id);
            abort_unless($currentStore->shop_domain===$store->shop_domain,409,'The original Shopify store changed during connection.');
            $source=IntegrationConnection::query()->forTenant($tenantId)->firstOrNew(['tenant_id'=>$tenantId,'provider'=>'recharge','external_account_id'=>'subscription-pilot:'.$store->shop_domain]);
            $source->fill(['status'=>'connected','access_token'=>$token,'scopes'=>$scopes,'connected_by_user_id'=>$actor->id,
                'connected_at'=>now(),'metadata'=>['purpose'=>'subscription_pilot_read_only','shopify_store_id'=>$store->id]])->save();
            app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenantId,$actor->id,'subscriptions.pilot.recharge_credential_saved',
                targetType:'integration_connection',targetId:$source->id,result:['scope'=>'read_only','shopify_store_id'=>$store->id]);
            return $source;
        });
        return app(SubscriptionRechargeReadService::class)->verifyConnection($tenantId,$actor,$source->id,
            ['provider_store_id'=>(string)$identity['id'],'identifier'=>$store->shop_domain,'platform'=>'shopify'],(string)Str::uuid());
    }
}
