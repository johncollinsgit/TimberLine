<?php

namespace App\Proposals;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Read-only illustrative arithmetic over an exact proposal payload; never a quote or provider operation. */
class FinancialScenario
{
    public const REFERENCE = 'provider-baselines-2026-10-23-v1';

    public const RATE_RECEIPT_SHA256 = '315a38693d398bf723600fadea37a67bb4dc234357165c7d97c117f28929adfe';

    public const MODELS = ['gpt-6-luna' => [100, 500], 'gpt-6.1-sol' => [2000, 10000], 'gpt-6-astra' => [10000, 50000]];

    public const SOURCES = [
        'openai' => 'https://developers.openai.com/api/docs/pricing',
        'sms' => 'https://www.twilio.com/en-us/sms/pricing/us',
        'email' => 'https://aws.amazon.com/ses/pricing/',
    ];

    public static function rateFingerprint(): string
    {
        return hash('sha256', json_encode([self::REFERENCE, self::RATE_RECEIPT_SHA256, self::MODELS, 50000000, 11800000, 13300000, 1150000000, 100000, 120000], JSON_THROW_ON_ERROR));
    }

    public function profile(string $name): array
    {
        $volumes = ['light' => [100, 2000, 500, 30, 100, 1000, '0.100'],
            'moderate' => [500, 4000, 1000, 120, 500, 5000, '1.000'],
            'busy' => [2000, 8000, 1500, 600, 2000, 20000, '5.000']];
        if (! isset($volumes[$name])) {
            throw ValidationException::withMessages(['profile' => 'Choose an illustrative Light, Moderate or Busy profile.']);
        }
        [$questions, $input, $output, $minutes, $segments, $emails, $attachments] = $volumes[$name];

        return ['label' => '', 'profile' => $name, 'ai' => true, 'model' => 'gpt-6-luna', 'questions' => $questions,
            'input_tokens' => $input, 'output_tokens' => $output, 'voice' => true, 'voice_minutes' => $minutes,
            'sms' => true, 'sms_segments' => $segments, 'email' => true, 'emails' => $emails, 'attachment_gb' => $attachments, 'method' => null];
    }

    public function item(array $payload, string $id): array
    {
        foreach ($payload['items'] ?? [] as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }
        throw ValidationException::withMessages(['service_id' => 'Choose an offered service from this exact saved proposal.']);
    }

    /** Finite approved recognizer: an altered/new offer stays separate and its combined price remains pending. */
    public function capacity(array $payload, array $item): ?int
    {
        if (($payload['client']['reference'] ?? null) !== 'lees-mcrae-college'
            || empty($payload['client']['id']) || ($item['client_id'] ?? null) !== $payload['client']['id']
            || ($item['exclusive_group'] ?? null) !== 'workspace-choice') {
            return null;
        }
        $options = ['One workspace' => [1, 300000, 100000], 'Three-workspace bundle' => [3, 700000, 250000]];
        $option = $options[$item['name'] ?? ''] ?? null;
        $charges = $item['charges'] ?? null;
        if (! $option || ! is_array($charges) || ! array_is_list($charges) || count($charges) !== 2) {
            return null;
        }
        foreach ([['One-time setup', $option[1], 'one_time'], ['Monthly service', $option[2], 'month']] as $index => [$label, $amount, $cadence]) {
            $charge = $charges[$index];
            if (! is_array($charge) || count($charge) !== 3 || ($charge['label'] ?? null) !== $label
                || ($charge['amount_cents'] ?? null) !== $amount || ($charge['cadence'] ?? null) !== $cadence) {
                return null;
            }
        }

        return $option[0];
    }

    public function prepare(array $payload, array $input): array
    {
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:60'], 'service_id' => ['required', 'uuid'],
            'department_count' => ['required', 'integer', 'between:1,3'], 'profile' => ['required', Rule::in(['light', 'moderate', 'busy'])],
            'management' => ['nullable', Rule::in(['university', 'department'])]])->validate();
        $item = $this->item($payload, $data['service_id']);
        $count = (int) $data['department_count'];
        $capacity = $this->capacity($payload, $item);
        if ($capacity !== null && $count > $capacity) {
            throw ValidationException::withMessages(['department_count' => 'This offered option has fewer workspaces than this planning scenario.']);
        }
        $departments = [];
        for ($i = 0; $i < $count; $i++) {
            $departments[] = array_replace($this->profile($data['profile']), ['label' => 'Department '.($i + 1)]);
        }
        $terms = $item['terms_months'] ?? [];

        return ['name' => $data['name'], 'service_id' => $item['id'], 'term_months' => count($terms) === 1 ? $terms[0] : null,
            'management' => $data['management'] ?? null, 'method' => null, 'phone_numbers' => null, 'departments' => $departments];
    }

    public function normalize(array $payload, array $input): array
    {
        $fields = 'label,profile,ai,model,questions,input_tokens,output_tokens,voice,voice_minutes,sms,sms_segments,email,emails,attachment_gb,method';
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:60'], 'service_id' => ['required', 'uuid'], 'term_months' => ['nullable', 'integer'],
            'management' => ['nullable', Rule::in(['university', 'department'])], 'method' => ['nullable', Rule::in(['prepaid', 'actual'])],
            'phone_numbers' => ['nullable', 'integer', 'between:0,100'], 'departments' => ['required', 'array', 'list', 'min:1', 'max:3'],
            'departments.*' => ['required', 'array:'.$fields], 'departments.*.label' => ['required', 'string', 'max:60'],
            'departments.*.profile' => ['required', Rule::in(['light', 'moderate', 'busy', 'custom'])],
            'departments.*.model' => ['required', Rule::in(array_keys(self::MODELS))],
            'departments.*.questions' => ['required', 'integer', 'between:0,1000000'],
            'departments.*.input_tokens' => ['required', 'integer', 'between:0,100000'], 'departments.*.output_tokens' => ['required', 'integer', 'between:0,100000'],
            'departments.*.voice_minutes' => ['required', 'integer', 'between:0,1000000'],
            'departments.*.sms_segments' => ['required', 'integer', 'between:0,100000000'], 'departments.*.emails' => ['required', 'integer', 'between:0,1000000000'],
            'departments.*.attachment_gb' => ['required', 'string', 'regex:/^\d{1,6}(\.\d{1,3})?$/D'],
            'departments.*.method' => ['nullable', Rule::in(['prepaid', 'actual'])],
            ...collect(['ai', 'voice', 'sms', 'email'])->mapWithKeys(fn ($key) => ['departments.*.'.$key => ['required', 'boolean']])->all(),
        ])->validate();
        $item = $this->item($payload, $data['service_id']);
        $capacity = $this->capacity($payload, $item);
        if ($capacity !== null && count($data['departments']) > $capacity) {
            throw ValidationException::withMessages(['departments' => 'The department count exceeds this exact offered option.']);
        }
        $data['term_months'] = isset($data['term_months']) ? (int) $data['term_months'] : null;
        app(ProposalService::class)->selection($payload, [['id' => $item['id'], 'term_months' => $data['term_months']]]);
        $data['management'] ??= null;
        $data['method'] = $data['management'] === 'university' ? ($data['method'] ?? null) : null;
        $data['phone_numbers'] = isset($data['phone_numbers']) ? (int) $data['phone_numbers'] : null;
        foreach ($data['departments'] as &$row) {
            foreach (['questions', 'input_tokens', 'output_tokens', 'voice_minutes', 'sms_segments', 'emails'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            foreach (['ai', 'voice', 'sms', 'email'] as $key) {
                $row[$key] = (bool) $row[$key];
            }
            $row['method'] = $data['management'] === 'department' ? ($row['method'] ?? null) : null;
        }
        unset($row);

        return $data;
    }

    public function calculate(array $payload, array $input): array
    {
        abort_unless(PHP_INT_SIZE === 8, 503, 'Planning calculations require 64-bit arithmetic.');
        $data = $this->normalize($payload, $input);
        $item = $this->item($payload, $data['service_id']);
        $low = $high = 0;
        $departments = [];
        $pending = ['Selected-provider extras and taxes are unpriced; an all-in total is pending.'];
        $sms = false;
        foreach ($data['departments'] as $row) {
            $lines = [];
            $add = function (string $label, int $lo, int $hi, string $source) use (&$lines, &$low, &$high): void {
                $lines[] = ['label' => $label, 'low_nano' => $lo, 'high_nano' => $hi, 'source' => self::SOURCES[$source]];
                $low += $lo;
                $high += $hi;
            };
            if ($row['ai']) {
                [$in, $out] = self::MODELS[$row['model']];
                $cost = $row['questions'] * ($row['input_tokens'] * $in + $row['output_tokens'] * $out);
                $add('AI backend text · '.$row['model'], $cost, $cost, 'openai');
            }
            if ($row['voice']) {
                $cost = $row['voice_minutes'] * 50000000;
                $add('Paid voice session minutes · backend text separate', $cost, $cost, 'openai');
                $pending[] = 'Voice tools, any telephony and the final backend architecture are not priced.';
                if (! $row['ai'] || $row['questions'] === 0 || $row['input_tokens'] === 0 || $row['output_tokens'] === 0) {
                    $pending[] = $row['label'].': voice backend text assumptions are missing.';
                }
            }
            if ($row['sms']) {
                $sms = true;
                $pending[] = 'SMS registration/A2P, inbound messages, failures and optional services remain unpriced.';
                $add('Outbound SMS segments · base plus carrier range', $row['sms_segments'] * 11800000, $row['sms_segments'] * 13300000, 'sms');
            }
            if ($row['email']) {
                $parts = explode('.', $row['attachment_gb']);
                $milliGb = (int) $parts[0] * 1000 + (int) str_pad($parts[1] ?? '', 3, '0');
                $cost = $row['emails'] * 100000 + $milliGb * 120000;
                $add('Email + attachment data · SES reference only', $cost, $cost, 'email');
                $pending[] = 'Email provider readiness is unverified; SES is a reference baseline and another provider may differ.';
            }
            $departments[] = ['label' => $row['label'], 'method' => $row['method'], 'lines' => $lines,
                'low_nano' => array_sum(array_column($lines, 'low_nano')), 'high_nano' => array_sum(array_column($lines, 'high_nano'))];
        }
        $numbers = null;
        if ($sms) {
            if ($data['phone_numbers'] === null) {
                $pending[] = 'Phone-number rental quantity is not selected; reference $1.15 per number/month.';
            } else {
                $numbers = $data['phone_numbers'] * 1150000000;
                $low += $numbers;
                $high += $numbers;
            }
        }
        $capacity = $this->capacity($payload, $item);
        $selection = app(ProposalService::class)->selection($payload, [['id' => $item['id'], 'term_months' => $data['term_months']]]);
        $completeScope = $capacity !== null && count($data['departments']) === $capacity;
        $monthly = $selection['totals_cents']['month'] ?? null;

        return ['input' => $data, 'reference' => self::REFERENCE, 'verified_on' => '2026-10-23', 'sources' => self::SOURCES,
            'service' => $selection, 'capacity' => $capacity, 'departments' => $departments, 'usage_low_nano' => $low, 'usage_high_nano' => $high,
            'number_rental_nano' => $numbers, 'combined_low_nano' => $completeScope && $monthly !== null ? $monthly * 10000000 + $low : null,
            'combined_high_nano' => $completeScope && $monthly !== null ? $monthly * 10000000 + $high : null,
            'combined_scope' => $completeScope ? 'Full-activation quoted software plus calculated usage subtotal; unpriced extras remain pending.' : 'Combined software total pending: no matching fully modeled package scope. Two departments are not an independent priced package.',
            'all_in_total' => null, 'pending' => array_values(array_unique($pending)), 'markup_nano' => 0];
    }

    public static function money(int $nano): string
    {
        if ($nano > 0 && $nano < 10000000) {
            return '<$0.01';
        }
        $cents = intdiv($nano + 5000000, 10000000);

        return '$'.number_format(intdiv($cents, 100)).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
