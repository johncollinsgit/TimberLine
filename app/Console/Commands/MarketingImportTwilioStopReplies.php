<?php

namespace App\Console\Commands;

use App\Models\MarketingProfile;
use App\Models\MessagingConversationMessage;
use App\Services\Marketing\MessagingContactChannelStateService;
use App\Services\Marketing\MessagingConversationService;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Support\Collection;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use SplFileObject;

class MarketingImportTwilioStopReplies extends Command
{
    protected $signature = 'marketing:import-stop-replies
        {path : Path to the Twilio CSV export}
        {--tenant= : Tenant ID to attribute these opt-outs to}
        {--reason=twilio_stop_upload : Reason to record on the channel state}
        {--provider-source=twilio_stop_csv : Provider source metadata}
        {--seed-responses : Insert actionable non-STOP inbound replies into the Responses inbox}
        {--store-key=retail : Store key to attach to imported response conversations}
        {--response-status=needs_follow_up : Conversation status for imported actionable replies}
        {--dry-run : Validate the file without persisting anything}';

    protected $description = 'Import Twilio inbound replies, mark STOP contacts unsubscribed, and optionally seed actionable replies into Responses.';

    public function handle(
        MessagingContactChannelStateService $channelStateService,
        MessagingConversationService $conversationService,
        MarketingIdentityNormalizer $identityNormalizer
    ): int {
        $path = trim((string) $this->argument('path'));
        if ($path === '') {
            $this->error('A CSV path is required.');

            return self::FAILURE;
        }

        $tenantOption = $this->option('tenant');
        if ($tenantOption === null) {
            $this->error('Provide --tenant=<id> so we know where to record the opt-outs.');

            return self::FAILURE;
        }

        $tenantId = (int) $tenantOption;
        if ($tenantId <= 0) {
            $this->error('Tenant ID must be a positive integer.');

            return self::FAILURE;
        }

        if (! is_readable($path)) {
            $this->error('The file "' . $path . '" does not exist or is not readable.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason')) ?: 'twilio_stop_upload';
        $providerSource = trim((string) $this->option('provider-source')) ?: 'twilio_stop_csv';
        $seedResponses = (bool) $this->option('seed-responses');
        $storeKey = $this->nullableString($this->option('store-key')) ?? 'retail';
        $responseStatus = $this->normalizedResponseStatus((string) $this->option('response-status'));
        $dryRun = (bool) $this->option('dry-run');

        try {
            $file = new SplFileObject($path);
        } catch (\Throwable $exception) {
            $this->error('Unable to open the CSV file: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $file->setFlags(SplFileObject::READ_CSV);
        $file->setCsvControl(',', '"', "\n");

        $headers = $this->extractHeaders($file);
        if ($headers === []) {
            $this->error('Unable to read the header row from the CSV.');

            return self::FAILURE;
        }

        $summary = [
            'rows' => 0,
            'stop_rows' => 0,
            'stop_processed' => 0,
            'stop_duplicates' => 0,
            'response_seeded' => 0,
            'response_duplicates' => 0,
            'response_skipped' => 0,
            'invalid_phone' => 0,
            'non_inbound' => 0,
        ];
        $seenStopPhones = [];
        $rowNumber = 1;

        while (($row = $file->fgetcsv()) !== false) {
            $rowNumber++;
            if ($row === [null] || $row === false) {
                continue;
            }

            $summary['rows']++;
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), null);
            } elseif (count($row) > count($headers)) {
                $row = array_slice($row, 0, count($headers));
            }

            $record = array_combine($headers, $row) ?: [];
            if ($record === []) {
                continue;
            }

            $direction = strtolower(trim((string) ($record['Direction'] ?? '')));
            if ($direction !== 'inbound') {
                $summary['non_inbound']++;
                continue;
            }

            $from = trim((string) ($record['From'] ?? ''));
            $normalized = $identityNormalizer->toE164($from);
            if ($normalized === null) {
                $summary['invalid_phone']++;
                $this->warn("Row {$rowNumber}: cannot normalize phone {$from}. Skipping.");
                continue;
            }

            $profiles = $this->resolveProfiles($tenantId, $normalized, $identityNormalizer);
            $profile = $profiles->sortByDesc('id')->first();
            $body = $this->normalizeStopKeyword($record['Body'] ?? null);
            $isStop = $body !== null && $this->isStopKeyword($body);
            $occurredAt = $this->asDate($record['SentDate'] ?? null);

            $metadata = [
                'twilio_sid' => $this->nullableString($record['Sid'] ?? null),
                'account_sid' => $this->nullableString($record['AccountSid'] ?? null),
                'body' => $record['Body'] ?? null,
                'direction' => $direction,
                'row_number' => $rowNumber,
            ];

            if ($isStop) {
                $summary['stop_rows']++;

                if (isset($seenStopPhones[$normalized])) {
                    $summary['stop_duplicates']++;
                    continue;
                }

                $seenStopPhones[$normalized] = true;
                $summary['stop_processed']++;

                if (! $dryRun) {
                    $channelStateService->markSmsUnsubscribed(
                    tenantId: $tenantId,
                    profile: $profile,
                    phone: $normalized,
                    reason: $reason,
                    providerSource: $providerSource,
                    metadata: $metadata,
                    occurredAt: $occurredAt
                );

                    $this->applySmsOptOutToProfiles($profiles, $occurredAt);
                }

                continue;
            }

            if (! $seedResponses || ! $this->shouldSeedResponse($record['Body'] ?? null)) {
                $summary['response_skipped']++;
                continue;
            }

            $providerMessageId = $this->nullableString($record['Sid'] ?? null);
            if ($providerMessageId !== null && $this->responseAlreadyImported($providerMessageId)) {
                $summary['response_duplicates']++;
                continue;
            }

            if (! $dryRun) {
                $conversation = $conversationService->findOrCreateSmsConversation(
                    tenantId: $tenantId,
                    storeKey: $storeKey,
                    profile: $profile,
                    phone: $normalized,
                    context: [
                        'source_type' => 'twilio_csv_import',
                        'source_context' => [
                            'provider_source' => $providerSource,
                            'import_type' => 'manual_twilio_csv',
                        ],
                    ]
                );

                $conversationService->appendMessage($conversation, [
                    'marketing_profile_id' => $profile?->id,
                    'channel' => 'sms',
                    'direction' => 'inbound',
                    'provider' => 'twilio',
                    'provider_message_id' => $providerMessageId,
                    'body' => trim((string) ($record['Body'] ?? '')),
                    'normalized_body' => trim((string) ($record['Body'] ?? '')),
                    'from_identity' => $normalized,
                    'to_identity' => $this->nullableString($record['To'] ?? null),
                    'received_at' => $occurredAt,
                    'message_type' => 'normal',
                    'raw_payload' => $record,
                    'metadata' => [
                        'source_label' => 'twilio_csv_import',
                        'provider_source' => $providerSource,
                        'imported_from_csv' => true,
                    ],
                ]);

                $conversation->forceFill([
                    'status' => $responseStatus,
                ])->save();
            }

            $summary['response_seeded']++;
        }

        $this->info('Stop import summary:');
        $this->line('  rows read: ' . $summary['rows']);
        $this->line('  inbound STOP rows: ' . $summary['stop_rows']);
        $this->line('  unique phones marked opted out: ' . $summary['stop_processed']);
        $this->line('  duplicate STOP phones skipped: ' . $summary['stop_duplicates']);
        $this->line('  actionable replies seeded: ' . $summary['response_seeded']);
        $this->line('  actionable replies already present: ' . $summary['response_duplicates']);
        $this->line('  other non-STOP replies skipped: ' . $summary['response_skipped']);
        $this->line('  invalid/missing phones: ' . $summary['invalid_phone']);
        $this->line('  non-inbound rows skipped: ' . $summary['non_inbound']);

        if ($dryRun) {
            $this->line('  dry-run enabled; no rows were persisted.');
        }

        return self::SUCCESS;
    }

    protected function extractHeaders(SplFileObject $file): array
    {
        while (($candidate = $file->fgetcsv()) !== false) {
            if ($candidate === [null] || $candidate === false) {
                continue;
            }

            $normalized = array_map(fn ($value) => trim($this->stripBom((string) $value)), $candidate);
            if (count(array_filter($normalized, fn ($value) => $value !== '')) === 0) {
                continue;
            }

            return $normalized;
        }

        return [];
    }

    protected function normalizeStopKeyword(?string $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        $collapsed = preg_replace('/\s+/', ' ', $normalized);
        if ($collapsed === null) {
            return null;
        }

        return strtoupper($collapsed);
    }

    protected function isStopKeyword(string $value): bool
    {
        return in_array($value, ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'], true);
    }

    protected function shouldSeedResponse(mixed $value): bool
    {
        $body = trim((string) $value);
        if ($body === '') {
            return false;
        }

        $normalized = strtolower($body);

        if (str_contains($body, '?')) {
            return true;
        }

        return preg_match('/\b(do you|can you|could you|is this|are you|when|where|what|why|how|help|verified|available|have)\b/i', $normalized) === 1;
    }

    /**
     * @return Collection<int, MarketingProfile>
     */
    protected function resolveProfiles(
        int $tenantId,
        string $phone,
        MarketingIdentityNormalizer $identityNormalizer
    ): Collection {
        $candidates = $identityNormalizer->phoneMatchCandidates($phone);

        return MarketingProfile::query()
            ->whereIn('normalized_phone', $candidates)
            ->where(function ($query) use ($tenantId) {
                $query->where('tenant_id', $tenantId)
                    ->orWhereNull('tenant_id');
            })
            ->orderBy('id')
            ->get();
    }

    protected function normalizedResponseStatus(string $value): string
    {
        $status = strtolower(trim($value));

        return in_array($status, ['open', 'needs_follow_up'], true)
            ? $status
            : 'needs_follow_up';
    }

    protected function responseAlreadyImported(string $providerMessageId): bool
    {
        return MessagingConversationMessage::query()
            ->where('provider', 'twilio')
            ->where('provider_message_id', $providerMessageId)
            ->exists();
    }

    /**
     * @param Collection<int, MarketingProfile> $profiles
     */
    protected function applySmsOptOutToProfiles(Collection $profiles, ?CarbonImmutable $occurredAt): void
    {
        foreach ($profiles as $profile) {
            $profile->forceFill([
                'accepts_sms_marketing' => false,
                'sms_opted_out_at' => $profile->sms_opted_out_at ?? $occurredAt ?? now(),
            ])->save();
        }
    }

    protected function asDate(mixed $value): ?CarbonImmutable
    {
        $string = $this->nullableString($value);
        if ($string === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function stripBom(string $value): string
    {
        $bom = "\xEF\xBB\xBF";

        if (str_starts_with($value, $bom)) {
            return substr($value, strlen($bom));
        }

        return $value;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
