<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\GrowaveClient;
use App\Support\Marketing\MarketingIdentityNormalizer;
use App\Services\Marketing\GrowaveMarketingSyncService;
use Mockery;
use Tests\TestCase;

class GrowaveMarketingSyncServiceTest extends TestCase
{
    public function testCandidateQueryIncludesTenantFilter(): void
    {
        $service = new GrowaveMarketingSyncService(
            Mockery::mock(GrowaveClient::class),
            Mockery::mock(MarketingIdentityNormalizer::class)
        );

        $this->assertStringContainsString(
            'tenant_id',
            (string) $this->invokeCandidateQuery($service, 'retail', 7)->toSql()
        );
    }

    private function invokeCandidateQuery(GrowaveMarketingSyncService $service, ?string $store, ?int $tenantId)
    {
        $method = new \ReflectionMethod($service, 'candidateQuery');
        $method->setAccessible(true);

        return $method->invoke($service, $store, $tenantId, null, false);
    }
}
