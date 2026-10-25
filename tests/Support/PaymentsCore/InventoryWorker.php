<?php

declare(strict_types=1);
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__.'/InventoryFixture.php';

use App\Models\WebsiteCart;
use App\Models\WebsiteOrder;
use App\Services\ManagedWebsite\WebsiteCheckoutService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\PaymentsCore\Inventory\WebsitePaymentInventory;
use App\Services\PaymentsCore\MySqlPaymentJournal;
use App\Services\PaymentsCore\PaymentCore;
use App\Services\PaymentsCore\Quotes\DatabaseQuoteRepository;
use App\Services\PaymentsCore\Quotes\WebsitePaymentCatalog;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentsCore\FixtureAccess;
use Tests\Support\PaymentsCore\InventoryFixture;
use Tests\Support\PaymentsCore\InventoryFixtureAuthority;
use Tests\Support\PaymentsCore\InventoryFixtureProvider;

try {
    InventoryFixture::guard();
    $d = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $provider = new InventoryFixtureProvider;
    $provider->base->nextState = 'processing';
    $core = new PaymentCore(new MySqlPaymentJournal(DB::connection(), app('encrypter'),
        new WebsitePaymentInventory(DB::connection(), new WebsitePaymentCatalog(DB::connection()), app('encrypter'), new InventoryFixtureAuthority($d['tenant']))),
        ['fixture_provider' => $provider], new FixtureAccess($d['tenant']));
    file_put_contents($d['directory'].'/ready-'.getmypid(), 'ready');
    $deadline = microtime(true) + 15;
    while (! file_exists($d['directory'].'/go') && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! file_exists($d['directory'].'/go')) {
        throw new RuntimeException('Inventory race barrier expired.');
    }
    try {
        if (isset($d['cart'])) {
            app()->instance(WebsiteCommerceService::class, new class extends WebsiteCommerceService
            {
                public function __construct() {}

                public function checkoutReadiness(App\Models\Tenant $tenant): array
                {
                    return ['ready' => true, 'blockers' => []];
                }
            });
            $checkout = new class extends WebsiteCheckoutService
            {
                public function ensureSession(App\Models\WebsitePayment $payment): array
                {
                    return ['order' => WebsiteOrder::findOrFail($payment->website_order_id), 'url' => 'https://example.test/fixture'];
                }
            };
            $checkout->begin(WebsiteCart::findOrFail($d['cart']), ['name' => 'Fictional stock racer', 'email' => 'stock-race@example.test',
                'fulfillment_method' => 'pickup'], 'https://example.test/success', 'https://example.test/cancel');
            $result = ['state' => 'website_reserved'];
        } else {
            $quote = (new DatabaseQuoteRepository(DB::connection(), app('encrypter')))->findById($d['tenant'], $d['quote']);
            $row = $core->startQuoted($quote, $d['request']);
            $result = ['state' => $row['state'], 'id' => $row['id']];
        }
    } catch (DomainException|App\Services\PaymentsCore\Quotes\QuoteException|Illuminate\Validation\ValidationException|Symfony\Component\HttpKernel\Exception\HttpException) {
        $result = ['state' => 'blocked'];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(1);
}
