<?php

namespace App\Console\Commands;

use Aws\Credentials\Credentials;
use Aws\SesV2\SesV2Client;
use Illuminate\Console\Command;

class SesMigrationReadiness extends Command
{
    protected $signature = 'email:ses-readiness {--identity=* : Existing identities to inspect, without creating them}';

    protected $description = 'Read SES account readiness and exact existing identity DNS requirements; never sends or provisions';

    public function handle(): int
    {
        $region = (string) config('services.ses.region');
        $report = ['region' => $region, 'current_platform_mailer' => config('mail.default'),
            'tenant_sending_enabled' => (bool) config('ses_migration.tenant_enabled'), 'identities' => [], 'blockers' => []];
        if (! filled(config('services.ses.key')) || ! filled(config('services.ses.secret'))) {
            $report['blockers'][] = 'Existing SES read credentials are not configured. No AWS account/identity verification performed.';
        } else {
            try {
                $client = $this->client();
                $account = $client->getAccount()->toArray();
                $report['account'] = array_intersect_key($account, array_flip(['ProductionAccessEnabled', 'SendingEnabled', 'EnforcementStatus', 'SendQuota', 'SuppressionAttributes']));
                if (! ($account['ProductionAccessEnabled'] ?? false)) {
                    $report['blockers'][] = 'SES sandbox: production approval is required before customer delivery.';
                }
                if (! ($account['SendingEnabled'] ?? false)) {
                    $report['blockers'][] = 'AWS account sending is disabled.';
                }
                foreach ((array) $this->option('identity') as $identity) {
                    $identity = strtolower(trim((string) $identity));
                    if (! filter_var('probe@'.$identity, FILTER_VALIDATE_EMAIL)) {
                        $report['blockers'][] = 'Invalid identity domain supplied.';

                        continue;
                    }
                    $state = $client->getEmailIdentity(['EmailIdentity' => $identity])->toArray();
                    $report['identities'][$identity] = [
                        'verified_for_sending' => (bool) ($state['VerifiedForSendingStatus'] ?? false),
                        'dkim_status' => data_get($state, 'DkimAttributes.Status'),
                        'dns_records' => array_map(fn ($token) => [
                            'type' => 'CNAME', 'name' => $token.'._domainkey.'.$identity,
                            'value' => $token.'.dkim.amazonses.com', 'proxy' => false,
                        ], (array) data_get($state, 'DkimAttributes.Tokens', [])),
                        'mail_from' => $state['MailFromAttributes'] ?? [],
                    ];
                    if (! ($state['VerifiedForSendingStatus'] ?? false)) {
                        $report['blockers'][] = $identity.' is not verified for SES sending.';
                    }
                }
            } catch (\Throwable) {
                $report['blockers'][] = 'SES read failed; verify existing credentials, region and identity existence. Provider error details withheld.';
            }
        }
        $report['blockers'][] = 'Account-specific pricing plan, tenant fees, identity/configuration-set associations and signed-event acceptance require recorded review.';
        $report['blockers'][] = 'Actual simulator delivery and application event proof are required before cutover.';
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::FAILURE;
    }

    protected function client(): SesV2Client
    {
        return new SesV2Client(['version' => 'latest', 'region' => config('services.ses.region'),
            'credentials' => new Credentials(config('services.ses.key'), config('services.ses.secret'), config('services.ses.token')),
            'retries' => 0, 'http' => ['connect_timeout' => 5, 'timeout' => 20]]);
    }
}
