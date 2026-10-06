<?php

namespace App\Jobs;

use App\Models\FieldServiceJobNotification;
use App\Services\Mobile\EverbranchApnsService;
use App\Services\Mobile\EverbranchFcmService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendFieldServicePushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public int $notificationId) {}

    public function handle(EverbranchApnsService $apns, EverbranchFcmService $fcm): void
    {
        $notification = FieldServiceJobNotification::query()->find($this->notificationId);
        if (! $notification || $notification->channel !== 'push' || $notification->status === 'sent') {
            return;
        }
        $result = collect([$apns->send($notification), $fcm->send($notification)])
            ->reduce(fn (array $totals, array $provider): array => [
                'sent' => $totals['sent'] + (int) $provider['sent'],
                'failed' => $totals['failed'] + (int) $provider['failed'],
                'skipped' => $totals['skipped'] + (int) $provider['skipped'],
            ], ['sent' => 0, 'failed' => 0, 'skipped' => 0]);
        $sent = (int) $result['sent'] > 0;
        $notification->forceFill([
            'status' => $sent ? 'sent' : ((int) $result['failed'] > 0 ? 'failed' : 'skipped'),
            'sent_at' => $sent ? now() : null,
            'failure_code' => $sent ? null : ((int) $result['failed'] > 0 ? 'push_failed' : 'no_ready_device'),
        ])->save();
    }
}
