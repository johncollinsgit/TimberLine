<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\GrowaveCustomerMetafieldParser;
use App\Support\Marketing\MarketingIdentityNormalizer;
use App\Services\Marketing\MarketingProfileSyncService;
use App\Services\Marketing\ShopifyCustomerMetafieldSyncService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ShopifyCustomerMetafieldSyncServiceTest extends TestCase
{
    private function service(): ShopifyCustomerMetafieldSyncService
    {
        return new ShopifyCustomerMetafieldSyncService(
            Mockery::mock(GrowaveCustomerMetafieldParser::class),
            Mockery::mock(MarketingIdentityNormalizer::class),
            Mockery::mock(MarketingProfileSyncService::class)
        );
    }

    public function testTenantIdFromStoreRequiresTenant(): void
    {
        $service = $this->service();
        $method = new \ReflectionMethod($service, 'tenantIdFromStore');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);

        $method->invoke($service, []);
    }

    public function testTenantIdFromStoreReturnsTenant(): void
    {
        $service = $this->service();
        $method = new \ReflectionMethod($service, 'tenantIdFromStore');
        $method->setAccessible(true);

        $result = $method->invoke($service, ['tenant_id' => '42']);

        $this->assertSame(42, $result);
    }
}
