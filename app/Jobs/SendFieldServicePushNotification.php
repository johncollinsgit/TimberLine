<?php

namespace App\Jobs;

use App\Models\FieldServiceJobNotification;
use App\Services\Mobile\EverbranchApnsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendFieldServicePushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public int $notificationId) {}

    public function handle(EverbranchApnsService $apns): void
    {
        $notification = FieldServiceJobNotification::query()->find($this->notificationId);
        if (! $notification || $notification->channel !== 'push' || $notification->status === 'sent') {
            return;
        }
        $result = $apns->send($notification);
        $sent = (int) $result['sent'] > 0;
        $notification->forceFill([
            'status' => $sent ? 'sent' : ((int) $result['failed'] > 0 ? 'failed' : 'skipped'),
            'sent_at' => $sent ? now() : null,
            'failure_code' => $sent ? null : ((int) $result['failed'] > 0 ? 'apns_failed' : 'no_ready_device'),
        ])->save();
    }
}
