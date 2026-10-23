<?php

namespace App\Proposals;

use Illuminate\Support\Facades\Validator;

class UsageEstimate
{
    public function calculate(array $input): array
    {
        $data = Validator::make($input, [
            'departments' => ['required', 'integer', 'min:1', 'max:3'],
            'low' => ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/'],
            'high' => ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/'],
        ])->validate();
        $cents = static function (string $amount): int {
            $parts = explode('.', $amount);

            return ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
        };
        $low = $cents($data['low']);
        $high = $cents($data['high']);
        if ($high < $low) {
            throw \Illuminate\Validation\ValidationException::withMessages(['high' => 'The upper usage assumption must be at least the lower assumption.']);
        }

        return ['departments' => (int) $data['departments'], 'low_cents' => $low * (int) $data['departments'], 'high_cents' => $high * (int) $data['departments'], 'markup_cents' => 0,
            'notice' => 'Planning assumptions entered by the reviewer, not measured usage, a provider quote, an approved allowance or a spending cap. No payment or billing change.'];
    }
}
