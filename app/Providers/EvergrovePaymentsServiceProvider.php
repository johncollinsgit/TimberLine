<?php

namespace App\Providers;

use App\Services\PaymentsCore\ClosedPaymentAccess;
use App\Services\PaymentsCore\Mobile\MobilePaymentAccess;
use App\Services\PaymentsCore\Mobile\MobilePaymentService;
use App\Services\PaymentsCore\Mobile\MobileRegisterDirectory;
use App\Services\PaymentsCore\MySqlPaymentJournal;
use App\Services\PaymentsCore\PaymentAccess;
use App\Services\PaymentsCore\PaymentCore;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\PaymentsCore\Quotes\DatabaseQuoteRepository;
use App\Services\PaymentsCore\Quotes\PaymentCatalog;
use App\Services\PaymentsCore\Quotes\PaymentQuoteService;
use App\Services\PaymentsCore\Quotes\QuoteRepository;
use App\Services\PaymentsCore\Quotes\ServiceItemPolicy;
use App\Services\PaymentsCore\Quotes\TaxPolicy;
use App\Services\PaymentsCore\Quotes\UnavailableServiceItemPolicy;
use App\Services\PaymentsCore\Quotes\UnavailableTaxPolicy;
use App\Services\PaymentsCore\Quotes\WebsitePaymentCatalog;
use App\Services\PaymentsCore\UnavailablePaymentJournal;
use Illuminate\Support\ServiceProvider;

final class EvergrovePaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentJournal::class, function ($app): PaymentJournal {
            $database = $app['db']->connection();

            return $database->getDriverName() === 'mysql'
                ? new MySqlPaymentJournal($database, $app['encrypter'])
                : new UnavailablePaymentJournal;
        });
        $this->app->bind(PaymentAccess::class, ClosedPaymentAccess::class);
        $this->app->bind(PaymentCore::class, fn ($app) => new PaymentCore($app->make(PaymentJournal::class)));
        $this->app->bind(ServiceItemPolicy::class, UnavailableServiceItemPolicy::class);
        $this->app->bind(TaxPolicy::class, UnavailableTaxPolicy::class);
        $this->app->bind(WebsitePaymentCatalog::class, fn ($app) => new WebsitePaymentCatalog(
            $app['db']->connection(), $app->make(ServiceItemPolicy::class),
        ));
        $this->app->bind(PaymentCatalog::class, fn ($app) => $app->make(WebsitePaymentCatalog::class));
        $this->app->bind(QuoteRepository::class, fn ($app) => new DatabaseQuoteRepository($app['db']->connection(), $app['encrypter']));
        $this->app->bind(PaymentQuoteService::class, fn ($app) => new PaymentQuoteService(
            $app->make(PaymentCatalog::class), $app->make(QuoteRepository::class), $app->make(TaxPolicy::class),
        ));
        $this->app->bind(MobilePaymentService::class, function ($app): MobilePaymentService {
            $registers = $app->make(MobileRegisterDirectory::class);
            $access = new MobilePaymentAccess($app['request'], $registers);
            $journal = $app->make(PaymentJournal::class);

            // No real or simulated provider is registered by application config.
            // Every core check uses this request's same fresh authorizer.
            return new MobilePaymentService(new PaymentCore($journal, [], $access), $journal,
                $app->make(PaymentQuoteService::class), $app->make(WebsitePaymentCatalog::class), $access, $registers);
        });
    }
}
