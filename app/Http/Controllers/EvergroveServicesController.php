<?php

namespace App\Http\Controllers;

use App\Models\ServiceInquiry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EvergroveServicesController extends Controller
{
    public function home(): View
    {
        return view('evergrove.home', [
            'content' => (array) config('evergrove', []),
            'tools' => (array) config('evergrove.tools', []),
        ]);
    }

    public function book(Request $request): View
    {
        if (! $request->session()->has('consultation_token')) {
            $request->session()->put('consultation_token', (string) Str::uuid());
        }
        if (! $request->session()->has('hurdle_token')) {
            $request->session()->put('hurdle_token', (string) Str::uuid());
        }
        $configuredUrl = trim((string) config('services.google_booking.url', ''));
        $scheme = strtolower((string) parse_url($configuredUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($configuredUrl, PHP_URL_HOST));
        $bookingUrl = filter_var($configuredUrl, FILTER_VALIDATE_URL) !== false && $scheme === 'https'
            && in_array($host, ['calendar.google.com', 'calendar.app.google'], true)
            ? $configuredUrl
            : null;

        return view('evergrove.book', [
            'bookingUrl' => $bookingUrl,
            'content' => (array) config('evergrove', []),
            'briefSaved' => (bool) $request->session()->get('consultation_saved', false),
            'submissionToken' => $request->session()->get('consultation_token'),
            'hurdleToken' => $request->session()->get('hurdle_token'),
        ]);
    }

    public function submitHurdle(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('hurdle', [
            'hurdle_token' => ['required', 'string', 'uuid'],
            'contact_fax' => ['nullable', 'string', 'max:0'],
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'pain_point' => ['required', 'string', 'max:3000'],
        ]);
        abort_unless(hash_equals((string) $request->session()->get('hurdle_token', ''), $validated['hurdle_token']), 419);
        ServiceInquiry::query()->create([
            'name' => trim($validated['name']),
            'email' => strtolower(trim($validated['email'])),
            'company' => isset($validated['company']) ? trim($validated['company']) : null,
            'pain_point' => trim($validated['pain_point']),
            'source_page' => 'evergrove/book/hurdle',
            'status' => 'new',
        ]);
        $request->session()->put('hurdle_token', (string) Str::uuid());

        return redirect()->route('evergrove.book')->withFragment('business-hurdle')
            ->with('hurdle_saved', 'Thanks—your note is in. I’ll follow up by email to talk through your business hurdle.');
    }

    public function submitBrief(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'submission_token' => ['required', 'string', 'uuid'],
            'contact_fax' => ['nullable', 'string', 'max:0'],
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'company' => ['required', 'string', 'max:190'],
            'business_type' => ['required', 'string', 'max:190'],
            'website' => ['nullable', 'url:http,https', 'max:300'],
            'business_size' => ['nullable', Rule::in(array_keys((array) config('evergrove.business_sizes', [])))],
            'goals' => ['required', 'array', 'min:1', 'max:6'],
            'goals.*' => ['required', 'string', 'distinct', Rule::in(['website', 'operations', 'automation', 'customers', 'ai', 'guidance'])],
            'pain_point' => ['required', 'string', 'max:3000'],
            'desired_outcome' => ['required', 'string', 'max:1000'],
            'current_tools' => ['nullable', 'string', 'max:1000'],
            'timeline' => ['required', Rule::in(array_keys((array) config('evergrove.timeline_options', [])))],
            'budget_range' => ['nullable', Rule::in(array_keys((array) config('evergrove.budget_ranges', [])))],
        ]);
        abort_unless(hash_equals((string) $request->session()->get('consultation_token', ''), $validated['submission_token']), 419);

        if (! $request->session()->get('consultation_saved', false)) {
            ServiceInquiry::query()->create([
                'name' => trim($validated['name']),
                'email' => strtolower(trim($validated['email'])),
                'company' => trim($validated['company']),
                'website' => $validated['website'] ?? null,
                'business_size' => $validated['business_size'] ?? null,
                'current_tools' => $validated['current_tools'] ?? null,
                'timeline' => $validated['timeline'],
                'budget_range' => $validated['budget_range'] ?? null,
                'pain_point' => 'Business type: '.trim($validated['business_type'])."\n\nLooking for: ".implode(', ', $validated['goals'])."\n\nBiggest bottleneck: ".trim($validated['pain_point'])."\n\nA successful outcome: ".trim($validated['desired_outcome']),
                'calculator_payload' => ['type' => 'consultation_brief', 'business_type' => trim($validated['business_type']), 'goals' => $validated['goals'], 'desired_outcome' => trim($validated['desired_outcome'])],
                'source_page' => 'evergrove/book',
                'status' => 'new',
            ]);
            $request->session()->put('consultation_saved', true);
        }

        return redirect()->route('evergrove.book')->withFragment('choose-time');
    }

    public function projectEstimate(): View
    {
        return $this->tool('project_estimate');
    }

    public function aiRoi(): View
    {
        return $this->tool('ai_roi');
    }

    public function automationSavings(): View
    {
        return $this->tool('automation_savings');
    }

    public function lander(Request $request): RedirectResponse
    {
        return redirect()->to('/', 301);
    }

    protected function tool(string $key): View
    {
        $tools = (array) config('evergrove.tools', []);
        abort_unless(isset($tools[$key]) && is_array($tools[$key]), 404);

        return view('evergrove.tool', [
            'content' => (array) config('evergrove', []),
            'toolKey' => $key,
            'tool' => (array) $tools[$key],
        ]);
    }
}
