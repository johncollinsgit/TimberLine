<?php

namespace App\Console\Commands;

use App\Services\Marketing\Email\SesSubscriptionConfirmation;
use Illuminate\Console\Command;

class ConfirmSesSubscription extends Command
{
    protected $signature = 'email:ses-confirm-subscription {topic : Exact previously approved and configured SNS topic ARN} {--confirm : Perform the approved AWS confirmation; otherwise preview only}';

    protected $description = 'Preview or confirm a fresh authenticated SES SNS subscription without exposing its token';

    public function handle(SesSubscriptionConfirmation $confirmation): int
    {
        $topic = (string) $this->argument('topic');
        if (! $message = $confirmation->pending($topic)) {
            $this->error('No fresh authenticated confirmation is pending for this configured topic. Request confirmation from the reviewed AWS subscription.');

            return self::FAILURE;
        }
        $this->line('Topic: '.$topic);
        $this->line('Authenticated request time: '.$message['Timestamp']);
        if (! $this->option('confirm')) {
            $this->line('Preview only. Review the exact topic and HTTPS endpoint in AWS before using --confirm.');

            return self::SUCCESS;
        }
        try {
            $this->line('Confirmed subscription: '.$confirmation->confirm($topic));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('AWS confirmation was not verified. Check the exact subscription in AWS before another attempt; error details and tokens withheld.');

            return self::FAILURE;
        }
    }
}
