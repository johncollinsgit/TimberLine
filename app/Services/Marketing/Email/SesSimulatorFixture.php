<?php

namespace App\Services\Marketing\Email;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** An authenticated simulator lane uses a private, customer-free database. */
class SesSimulatorFixture
{
    public const TENANT_ID = 900000001;

    public const RECIPIENTS = ['success@simulator.amazonses.com', 'bounce@simulator.amazonses.com', 'complaint@simulator.amazonses.com'];

    public function matches(array $event): bool
    {
        return $this->candidate($event)
            && data_get($event, 'mail.tags.ses:tenant-name') === ['everbranch-tenant-'.self::TENANT_ID]
            && data_get($event, 'mail.tags.ses:configuration-set') === ['everbranch-marketing'];
    }

    public function candidate(array $event): bool
    {
        return (bool) config('ses_migration.simulator_fixture_enabled')
            && data_get($event, 'mail.tags.tenant_id') === [(string) self::TENANT_ID]
            && data_get($event, 'mail.source') === 'no-reply@theeverbranch.com'
            && count((array) data_get($event, 'mail.destination', [])) === 1
            && in_array(data_get($event, 'mail.destination.0'), self::RECIPIENTS, true);
    }

    /** Called only behind the production SNS signature/account/topic boundary. */
    public function handle(array $event, string $messageId, SesDeliveryEvents $events): array
    {
        return $this->run(function () use ($event, $messageId, $events): array {
            DB::table('cache')->updateOrInsert(['key' => 'ses-simulator:event:'.hash('sha256', $messageId)], [
                'value' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode($event, JSON_THROW_ON_ERROR)),
                'expiration' => 2147483647,
            ]);

            // Keep unmatched authenticated simulator evidence for inspection;
            // its native/configuration binding still fails closed for delivery.
            return $this->matches($event) ? $events->handle($event, $messageId) : ['matched' => 0, 'updated' => 0];
        });
    }

    /** Never changes the production default permanently or loads customer rows. */
    public function run(callable $operation): mixed
    {
        if (! config('ses_migration.simulator_fixture_enabled') || DB::transactionLevel() !== 0) {
            throw new RuntimeException('Simulator fixture is disabled or the caller is in a transaction.');
        }
        $file = storage_path('app/private/ses-simulator.sqlite');
        $directory = realpath(storage_path('app/private'));
        if (! $directory || ! is_file($file) || is_link($file) || realpath($file) !== $directory.'/ses-simulator.sqlite'
            || filesize($file) > 33554432 || (fileperms($file) & 0077) !== 0) {
            throw new RuntimeException('A reviewed private simulator database is required.');
        }
        $previous = DB::getDefaultConnection();
        config()->set('database.connections.ses_simulator', ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000]);
        DB::setDefaultConnection('ses_simulator');
        Schema::clearResolvedInstance('db.schema');
        try {
            $tenants = DB::table('tenants')->get(['id', 'slug']);
            if ($tenants->count() !== 1 || (int) $tenants[0]->id !== self::TENANT_ID || $tenants[0]->slug !== 'ses-simulator-fixture') {
                throw new RuntimeException('Simulator database must contain only the approved fictional tenant.');
            }

            return $operation();
        } finally {
            DB::setDefaultConnection($previous);
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
