<?php

namespace App\Http\Controllers;

use App\Mail\WebsiteQuoteReplyMail;
use App\Models\FormSubmission;
use App\Models\Tenant;
use App\Services\ManagedWebsite\WebsiteQuoteAttributionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class WebsiteQuoteController extends Controller
{
    public function index(Request $request, WebsiteQuoteAttributionService $attribution): View
    {
        $tenant = $this->tenant($request);
        $attribution->synchronize($tenant);
        $quotes = FormSubmission::query()
            ->forTenant($tenant)
            ->where('source', 'managed_website_quote')
            ->latest('submitted_at')
            ->paginate(30);

        return view('managed-website.commerce.quotes', compact('quotes', 'tenant'));
    }

    public function reply(Request $request, FormSubmission $submission): RedirectResponse
    {
        $tenant = $this->tenant($request);
        abort_unless((int) $submission->tenant_id === (int) $tenant->id && $submission->source === 'managed_website_quote', 404);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:190'],
            'reply' => ['required', 'string', 'max:5000'],
        ]);

        Mail::to($submission->submitter_email)->send(new WebsiteQuoteReplyMail($data['subject'], $data['reply']));
        $metadata = (array) ($submission->metadata ?? []);
        $metadata['quote_response'] = [
            'subject' => $data['subject'],
            'reply' => $data['reply'],
            'responded_at' => now()->toIso8601String(),
            'responded_by_user_id' => $request->user()?->id,
        ];
        $submission->forceFill(['status' => 'responded', 'metadata' => $metadata])->save();

        return back()->with('status', 'Quote response sent and recorded.');
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }
}
