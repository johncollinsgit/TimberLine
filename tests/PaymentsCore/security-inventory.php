<?php

declare(strict_types=1);

// Independent synthetic integration checks. No application .env, network or DDL.
$root = dirname(__DIR__, 2);
if (getenv('APP_ENV') !== 'testing' || getenv('PAYMENTS_CORE_DISPOSABLE_MYSQL') !== '1'
    || getenv('PAYMENTS_INVENTORY_DISPOSABLE_MYSQL') !== '1') {
    fwrite(STDERR, "Explicit disposable inventory testing environment is required.\n");
    exit(2);
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->bootstrapWith([
    Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
    Illuminate\Foundation\Bootstrap\HandleExceptions::class,
    Illuminate\Foundation\Bootstrap\RegisterFacades::class,
    Illuminate\Foundation\Bootstrap\SetRequestForConsole::class,
    Illuminate\Foundation\Bootstrap\RegisterProviders::class,
    Illuminate\Foundation\Bootstrap\BootProviders::class,
]);

use App\Models\TenantSite;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrderLine;
use App\Models\WebsiteProductVariant;
use App\Services\ManagedWebsite\WebsitePaymentService;
use App\Services\PaymentsCore\Inventory\InventoryUnavailableException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\PaymentsCore\InventoryFixture;

require_once $root.'/tests/Support/PaymentsCore/InventoryFixture.php';
InventoryFixture::guard();
$fixtures = [];
$results = [];
$faultAfterHold = false;
DB::listen(function (QueryExecuted $query) use (&$faultAfterHold): void {
    if ($faultAfterHold && str_contains(strtolower($query->sql), 'insert into `website_inventory_reservations`')) {
        $faultAfterHold = false;
        throw new RuntimeException('Independent failure after actual hold insertion.');
    }
});

function independentInventoryAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function independentInventoryFixture(int $stock = 2): InventoryFixture
{
    global $fixtures;
    $fixture = InventoryFixture::make($stock);
    $fixtures[] = $fixture;

    return $fixture;
}
function independentInventoryCase(string $name, callable $operation): void
{
    global $results;
    try {
        $operation();
        $results[] = ['case' => $name, 'result' => 'PASS'];
    } catch (Throwable $error) {
        $results[] = ['case' => $name, 'result' => 'FAIL', 'reason' => $error->getMessage()];
    }
}
function independentInventoryHolds(InventoryFixture $fixture, string $operation): Illuminate\Database\Query\Builder
{
    return DB::table('website_inventory_reservations')->where('tenant_id', $fixture->binding->tenantId)->where('payment_core_operation_id', $operation);
}

try {
    independentInventoryCase('real local MySQL 8.4 REPEATABLE READ is exercised', function (): void {
        independentInventoryAssert(str_starts_with(DB::selectOne('SELECT VERSION() AS v')->v, '8.4.'), 'MySQL 8.4 required.');
        independentInventoryAssert(DB::selectOne('SELECT @@transaction_isolation AS v')->v === 'REPEATABLE-READ', 'REPEATABLE READ required.');
    });
    independentInventoryCase('failure after hold insertion rolls back every intent hold and event', function () use (&$faultAfterHold): void {
        $f = independentInventoryFixture();
        $quote = $f->quote();
        $request = (string) Str::uuid();
        $faultAfterHold = true;
        try {
            $f->core->startQuoted($quote, $request);
            throw new LogicException('Injected reservation failure was ignored.');
        } catch (RuntimeException $error) {
            independentInventoryAssert($error->getMessage() === 'Independent failure after actual hold insertion.', 'Unexpected fault: '.$error->getMessage());
        } finally {
            $faultAfterHold = false;
        }
        independentInventoryAssert($f->journal->findByRequest($f->binding->tenantId, $request) === null, 'Intent survived rollback.');
        independentInventoryAssert(DB::table('website_inventory_reservations')->where('tenant_id', $f->binding->tenantId)->count() === 0, 'Hold survived rollback.');
        independentInventoryAssert($f->provider->base->calls($f->binding->tenantId) === 0, 'Provider ran before durable atomic reservation.');
    });
    independentInventoryCase('removed original site retains canonical effect and enters inventory review', function (): void {
        $f = independentInventoryFixture();
        $f->provider->base->loseResponse = true;
        $row = $f->core->startQuoted($f->quote(), (string) Str::uuid());
        TenantSite::query()->whereKey($f->pos->site->id)->delete();
        $review = $f->core->recover($f->binding, $row['id']);
        independentInventoryAssert($review['state'] === 'review' && $review['inventory_payment_state'] === 'succeeded'
            && $review['provider_id'] !== null && $review['version'] === 1, 'Missing site discarded canonical payment identity.');
        independentInventoryAssert(DB::table('website_inventory_movements')->where('tenant_id', $row['tenant_id'])->count() === 0, 'Missing site moved inventory.');
    });
    independentInventoryCase('one global canonical effect cannot decrement stock for a second tenant', function (): void {
        $a = independentInventoryFixture();
        $b = independentInventoryFixture();
        $a->provider->base->fixedEffect = $b->provider->base->fixedEffect = 'independent_shared_inventory_effect';
        $first = $a->core->startQuoted($a->quote(), (string) Str::uuid());
        $second = $b->core->startQuoted($b->quote(), (string) Str::uuid());
        independentInventoryAssert($first['state'] === 'succeeded' && $second['state'] === 'review', 'Canonical effect credited twice.');
        independentInventoryAssert($a->pos->variant->fresh()->inventory_quantity === 1 && $b->pos->variant->fresh()->inventory_quantity === 2, 'Second binding moved stock.');
        independentInventoryAssert(independentInventoryHolds($b, $second['id'])->where('status', 'active')->whereNull('expires_at')->count() === 1, 'Quarantined hold was released.');
        independentInventoryAssert(DB::table('website_inventory_movements')->where('tenant_id', $b->binding->tenantId)->count() === 0, 'Duplicate effect created a movement.');
    });
    independentInventoryCase('unavailable second line cannot commit any first-line stock change', function (): void {
        $f = independentInventoryFixture();
        $second = WebsiteProductVariant::create(['tenant_id' => $f->binding->tenantId, 'website_product_id' => $f->pos->product->id,
            'title' => 'Independent second item', 'sku' => 'INDEPENDENT-SECOND', 'price_cents' => 100, 'inventory_quantity' => 2, 'is_available' => true]);
        $f->provider->base->loseResponse = true;
        $row = $f->core->startQuoted($f->quote(variantIds: [$f->pos->variant->id, $second->id]), (string) Str::uuid());
        $second->update(['inventory_quantity' => null]);
        $review = $f->core->recover($f->binding, $row['id']);
        independentInventoryAssert($review['state'] === 'review' && $review['provider_id'] !== null, 'Unavailable stock lost canonical identity.');
        independentInventoryAssert($f->pos->variant->fresh()->inventory_quantity === 2, 'First line changed during partial review.');
        independentInventoryAssert(independentInventoryHolds($f, $row['id'])->where('status', 'active')->count() === 2, 'Partial review released a hold.');
        independentInventoryAssert(DB::table('website_inventory_movements')->where('tenant_id', $row['tenant_id'])->count() === 0, 'Partial review wrote a movement.');
        $second->update(['inventory_quantity' => 2]);
        independentInventoryAssert($f->core->recover($f->binding, $row['id'])['state'] === 'succeeded', 'Same canonical evidence could not repair stock review.');
        $f->core->recover($f->binding, $row['id']);
        independentInventoryAssert($f->pos->variant->fresh()->inventory_quantity === 1 && $second->fresh()->inventory_quantity === 1, 'Repair decremented stock incorrectly.');
    });
    independentInventoryCase('legacy Website settlement counts unknown core holds with NULL order and expiry', function (): void {
        $f = independentInventoryFixture();
        $f->provider->base->loseResponse = true;
        $row = $f->core->startQuoted($f->quote(quantity: 2), (string) Str::uuid());
        $order = $f->pos->websiteOrder();
        WebsiteOrderLine::create(['tenant_id' => $f->binding->tenantId, 'website_order_id' => $order->id,
            'website_product_variant_id' => $f->pos->variant->id, 'title' => 'Independent late Website item', 'product_type' => 'physical',
            'quantity' => 1, 'unit_price_cents' => 2500, 'line_total_cents' => 2500, 'snapshot' => ['track_inventory' => true]]);
        WebsiteInventoryReservation::create(['tenant_id' => $f->binding->tenantId, 'website_product_variant_id' => $f->pos->variant->id,
            'website_order_id' => $order->id, 'quantity' => 1, 'status' => 'released', 'expires_at' => now()->subYear()]);
        $committed = DB::transaction(fn () => (new WebsitePaymentService)->commitInventory($order));
        independentInventoryAssert($committed === false && $f->pos->variant->fresh()->inventory_quantity === 2, 'Legacy Website ignored a NULL-owner unknown hold.');
        independentInventoryAssert(independentInventoryHolds($f, $row['id'])->where('status', 'active')->whereNull('expires_at')->count() === 1, 'Unknown hold acquired a TTL or was released.');
    });
    independentInventoryCase('revoked settlement authority retains canonical result and rejects contradictory terminal evidence', function (): void {
        $f = independentInventoryFixture();
        $f->provider->base->loseResponse = true;
        $row = $f->core->startQuoted($f->quote(), (string) Str::uuid());
        $f->authority->enabled = false;
        $review = $f->core->recover($f->binding, $row['id']);
        independentInventoryAssert($review['state'] === 'review' && $review['inventory_payment_state'] === 'succeeded' && $review['provider_id'] !== null, 'Revocation lost canonical payment evidence.');
        $f->journal->apply($row['tenant_id'], $row['id'], $f->provider->base->evidence($row, 'failed', 2));
        independentInventoryAssert(independentInventoryHolds($f, $row['id'])->value('status') === 'active', 'Contradictory evidence released a paid hold.');
        $f->authority->enabled = true;
        independentInventoryAssert($f->core->recover($f->binding, $row['id'])['state'] === 'succeeded', 'Original evidence could not settle after authority restored.');
    });
    independentInventoryCase('unknown refunds reserve budget and never restock paid merchandise', function (): void {
        $f = independentInventoryFixture();
        $paid = $f->core->startQuoted($f->quote(), (string) Str::uuid());
        $f->provider->base->loseResponse = true;
        $request = (string) Str::uuid();
        $refund = $f->core->refund($f->binding, $paid['id'], $request, 1000, 'other');
        independentInventoryAssert($refund['state'] === 'unknown', 'Lost refund response became final.');
        try {
            $f->core->refund($f->binding, $paid['id'], (string) Str::uuid(), 1501, 'other');
            throw new LogicException('Unknown refund budget was reused.');
        } catch (DomainException) {
            // Unknown money movement continues to reserve its original budget.
        }
        $f->core->recover($f->binding, $refund['id']);
        independentInventoryAssert($f->pos->variant->fresh()->inventory_quantity === 1, 'Refund restocked merchandise.');
        independentInventoryAssert(DB::table('website_inventory_movements')->where('tenant_id', $paid['tenant_id'])->count() === 1, 'Refund created another stock movement.');
    });
    independentInventoryCase('production environment cannot dispatch through an injected inventory fixture', function (): void {
        $f = independentInventoryFixture();
        $quote = $f->quote();
        app()->detectEnvironment(fn () => 'production');
        try {
            try {
                $f->core->startQuoted($quote, (string) Str::uuid());
                throw new LogicException('Production accepted a fixture inventory collection.');
            } catch (InventoryUnavailableException) {
                // Even explicit test composition must not make production collect.
            }
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
        independentInventoryAssert(DB::table('payment_core_operations')->where('tenant_id', $f->binding->tenantId)->count() === 0, 'Production retained a new intent.');
        independentInventoryAssert($f->provider->base->calls($f->binding->tenantId) === 0, 'Production called provider.');
    });
} finally {
    app()->detectEnvironment(fn () => 'testing');
    foreach (array_reverse($fixtures) as $fixture) {
        $fixture->cleanup();
    }
    Http::assertNothingSent();
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(count(array_filter($results, fn ($row) => $row['result'] !== 'PASS')) ? 1 : 0);
