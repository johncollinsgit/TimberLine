<?php

namespace App\Console\Commands;

use App\Jobs\Trajectory\SendReviewEmail;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Space;
use App\Models\User;
use App\Services\Trajectory\FinanceAccess;
use App\Services\Trajectory\ReviewEmailService;
use Illuminate\Console\Command;

class TrajectoryReviewEmails extends Command
{
    protected $signature = 'trajectory:review-emails {--user= : Existing recipient email; requires --space} {--space= : Finance space ID} {--frequency= : off, daily, or weekly; only for an explicitly authorized recipient} {--timezone= : IANA timezone} {--send-now : Send one due-queue digest now for --user and --space}';

    protected $description = 'Queue opted-in private transaction review emails, or configure one authorized recipient';

    public function handle(ReviewEmailService $emails, FinanceAccess $access): int
    {
        if ($this->option('user') || $this->option('space') || $this->option('frequency') || $this->option('send-now') || $this->option('timezone')) {
            if (! $this->option('user') || ! $this->option('space')) {
                $this->error('Specify both --user and --space.');

                return self::FAILURE;
            }
            $user = User::where('email', $this->option('user'))->firstOrFail();
            $space = Space::findOrFail($this->option('space'));
            $access->authorize($user, $space);
            if ($this->option('frequency')) {
                $emails->save($user, $space, $this->option('frequency'), $this->option('timezone') ?: $space->timezone);
            }
            $this->info('Frequency: '.$emails->preferences($user, $space)['frequency']);
            $this->info('Provider: '.($emails->deliveryReady() ? 'ready' : 'unavailable'));
            if ($this->option('send-now')) {
                $status = $emails->send($user->id, $space->id, true);
                $this->info('Email: '.$status);

                return in_array($status, ['accepted', 'skipped'], true) ? self::SUCCESS : self::FAILURE;
            }

            return self::SUCCESS;
        }
        $queued = 0;
        $latest = Event::selectRaw('MAX(id)')->where('action', 'review_email_preference')->groupBy('space_id', 'actor_id');
        foreach (Event::whereIn('id', $latest)->cursor() as $event) {
            $user = User::find($event->actor_id);
            $space = Space::find($event->space_id);
            if ($user && $space && $access->allows($user, $space) && $emails->due($emails->preferences($user, $space))) {
                SendReviewEmail::dispatch($user->id, $space->id);
                $queued++;
            }
        }
        $this->info("Review email jobs queued: {$queued}");

        return self::SUCCESS;
    }
}
