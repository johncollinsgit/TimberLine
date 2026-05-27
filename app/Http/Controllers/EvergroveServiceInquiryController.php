<?php

namespace App\Http\Controllers;

use App\Models\ServiceInquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EvergroveServiceInquiryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $businessSizeKeys = array_keys((array) config('evergrove.business_sizes', []));
        $timelineKeys = array_keys((array) config('evergrove.timeline_options', []));
        $budgetKeys = array_keys((array) config('evergrove.budget_ranges', []));

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'website' => ['nullable', 'url', 'max:300'],
            'business_size' => ['nullable', 'string', Rule::in($businessSizeKeys)],
            'current_tools' => ['nullable', 'string', 'max:1000'],
            'pain_point' => ['nullable', 'string', 'max:5000'],
            'timeline' => ['nullable', 'string', Rule::in($timelineKeys)],
            'budget_range' => ['nullable', 'string', Rule::in($budgetKeys)],
            'source_page' => ['nullable', 'string', 'max:120'],
            'calculator_payload' => ['nullable'],
        ]);

        ServiceInquiry::query()->create([
            'name' => $this->text($validated['name'] ?? '', 190),
            'email' => strtolower($this->text($validated['email'] ?? '', 190)),
            'company' => $this->nullableText($validated['company'] ?? null, 190),
            'website' => $this->nullableText($validated['website'] ?? null, 300),
            'business_size' => $this->nullableText($validated['business_size'] ?? null, 80),
            'current_tools' => $this->nullableText($validated['current_tools'] ?? null, 1000),
            'pain_point' => $this->nullableText($validated['pain_point'] ?? null, 5000),
            'timeline' => $this->nullableText($validated['timeline'] ?? null, 80),
            'budget_range' => $this->nullableText($validated['budget_range'] ?? null, 80),
            'source_page' => $this->nullableText($validated['source_page'] ?? $request->path(), 120),
            'calculator_payload' => $this->calculatorPayload($request->input('calculator_payload')),
            'status' => 'new',
        ]);

        return back()->with('status', 'Thanks. Evergrove has your notes and will follow up shortly.');
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function calculatorPayload(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (strlen($value) > 12000) {
            return ['truncated' => true];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function text(mixed $value, int $limit): string
    {
        return Str::limit(trim((string) $value), $limit, '');
    }

    protected function nullableText(mixed $value, int $limit): ?string
    {
        $text = $this->text($value, $limit);

        return $text !== '' ? $text : null;
    }
}
