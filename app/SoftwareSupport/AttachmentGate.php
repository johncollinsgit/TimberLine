<?php

namespace App\SoftwareSupport;

use Illuminate\Support\Facades\Storage;

class AttachmentGate
{
    public function review(Record $attachment): void
    {
        abort_unless($attachment->kind === 'attachment', 404);
        $bytes = $this->bytes($attachment);
        $evidence = ['status' => 'quarantined', 'reason' => 'scanner_unavailable'];
        if ($this->configured()) {
            try {
                $verdict = app(AttachmentScanner::class)->inspect($bytes);
                $valid = in_array($verdict['status'] ?? '', ['clean', 'infected'], true)
                    && hash_equals($attachment->payload['sha256'], (string) ($verdict['sha256'] ?? ''))
                    && ($verdict['scanner'] ?? '') === config('software_support.scanner_id')
                    && is_string($verdict['definitions'] ?? null) && preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', $verdict['definitions']);
                if ($valid) {
                    $evidence = ['status' => $verdict['status'], 'sha256' => $verdict['sha256'], 'scanner' => $verdict['scanner'],
                        'definitions' => $verdict['definitions'], 'policy' => config('software_support.scanner_policy'), 'reviewed_at' => now()->toIso8601String()];
                }
            } catch (\Throwable) {
                // Persist a bounded state; provider errors can contain secrets or private filenames.
                $evidence = ['status' => 'quarantined', 'reason' => 'scanner_failed'];
            }
        }
        $attachment->update(['payload' => [...$attachment->payload, 'scan' => $evidence]]);
    }

    public function download(Record $attachment): string
    {
        $bytes = $this->bytes($attachment);
        $scan = $attachment->payload['scan'] ?? [];
        try {
            $reviewedAt = \Carbon\CarbonImmutable::parse($scan['reviewed_at'] ?? '1970-01-01T00:00:00Z');
            $fresh = $reviewedAt->timestamp <= now()->timestamp + 5
                && $reviewedAt->timestamp >= now()->timestamp - max(1, min(86400, (int) config('software_support.scan_max_age_seconds', 3600)));
        } catch (\Throwable) {
            $fresh = false;
        }
        abort_unless($this->configured() && ($scan['status'] ?? '') === 'clean'
            && $fresh
            && ($scan['scanner'] ?? '') === config('software_support.scanner_id')
            && ($scan['policy'] ?? '') === config('software_support.scanner_policy')
            && hash_equals($attachment->payload['sha256'], (string) ($scan['sha256'] ?? '')), 423, 'Attachment is quarantined pending security review.');

        return $bytes;
    }

    private function configured(): bool
    {
        return (bool) config('software_support.scanner_enabled', false)
            && is_string(config('software_support.scanner_id')) && config('software_support.scanner_id') !== ''
            && is_string(config('software_support.scanner_policy')) && config('software_support.scanner_policy') !== ''
            && app()->bound(AttachmentScanner::class);
    }

    private function bytes(Record $attachment): string
    {
        abort_unless($attachment->kind === 'attachment', 404);
        $path = 'private/software-support/'.$attachment->id;
        abort_unless(Storage::disk('local')->exists($path), 404);
        $bytes = Storage::disk('local')->get($path);
        abort_unless(hash_equals($attachment->payload['sha256'], hash('sha256', $bytes)), 503, 'Attachment integrity check failed.');

        return $bytes;
    }
}
