<?php

namespace App\Services\Operations;

use App\Models\OperatorAlertLog;
use App\Services\Marketing\TwilioSmsService;
use Illuminate\Support\Facades\Cache;

class OperatorAlertService
{
    public function __construct(private TwilioSmsService $sms) {}

    /** @param array<string,mixed> $context */
    public function notify(string $eventKey, string $message, array $context = []): void
    {
        $destination = preg_replace('/\D+/', '', (string) config('everbranch.operator_alert_phone', ''));
        if (strlen((string) $destination) < 10) return;
        $dedupe = (string) ($context['dedupe_key'] ?? sha1($eventKey.'|'.$message));
        if (! Cache::add('operator-alert:'.$dedupe, true, now()->addMinutes(10))) return;
        $result = $this->sms->sendSms($destination, $message, [
            'source_type' => 'operator_alert',
            'source_id' => $context['target_id'] ?? null,
            'idempotency_key' => 'operator-alert:'.$dedupe,
        ]);
        OperatorAlertLog::query()->create([
            'event_key' => $eventKey, 'dedupe_key' => $dedupe, 'tenant_id' => $context['tenant_id'] ?? null,
            'target_type' => $context['target_type'] ?? null, 'target_id' => $context['target_id'] ?? null,
            'destination' => $destination, 'status' => ($result['success'] ?? false) ? 'sent' : 'failed',
            'message' => $message, 'metadata' => ['provider' => $result['provider'] ?? null, 'error' => $result['error_code'] ?? null],
        ]);
    }
}
