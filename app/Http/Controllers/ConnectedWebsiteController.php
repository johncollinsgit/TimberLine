<?php

namespace App\Http\Controllers;

use App\Models\FormSubmission;
use App\Models\Tenant;
use App\Models\TenantForm;
use App\Models\TenantSite;
use App\Services\ManagedWebsite\ConnectedWebsiteService;
use App\Services\ManagedWebsite\ManagedWebsiteAccessService;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ConnectedWebsiteController extends Controller
{
    private function site(Request $request): TenantSite
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return TenantSite::query()->forTenant($tenant)->firstOrFail();
    }

    public function index(Request $request, ConnectedWebsiteService $service)
    {
        $site = $this->site($request);
        $service->assertEditor($site, $request->user());

        return response()->view('managed-website.connected', [
            'site' => $site, 'tenant' => $site->tenant, 'manifest' => $service->manifest(),
            'content' => $service->content($site, $site->draft_site_version_id),
            'previewUrl' => $service->previewUrl($site, $request->user()),
            'publicUrl' => ConnectedWebsiteService::ORIGIN,
            'canPublish' => app(ManagedWebsiteAccessService::class)->canPublish($site->tenant, $request->user()) && app(ManagedWebsiteService::class)->publishingEnabled(),
            'history' => $site->siteVersions()->where('status', 'published')->latest('id')->limit(20)->get()->filter(fn ($version) => data_get($version->settings, 'connected_renderer') === ConnectedWebsiteService::RENDERER),
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function save(Request $request, ConnectedWebsiteService $service)
    {
        $site = $this->site($request);
        $data = $request->validate(['version' => ['required', 'integer'], 'content' => ['required', 'array']]);
        $service->save($site, $data['content'], $request->user(), $data['version']);

        return back()->with('status', 'Draft saved. Preview it before publishing.');
    }

    public function publish(Request $request, ConnectedWebsiteService $service)
    {
        $site = $this->site($request);
        $version = $request->validate(['version' => ['required', 'integer']])['version'];
        $service->publish($site, $request->user(), $version);

        return back()->with('status', 'Published to your live website.');
    }

    public function restore(Request $request, ConnectedWebsiteService $service)
    {
        $site = $this->site($request);
        $service->assertEditor($site, $request->user());
        $data = $request->validate(['version' => ['required', 'integer'], 'restore_version' => ['required', 'integer']]);
        $content = $service->content($site, $data['restore_version']);
        $service->save($site, $content, $request->user(), $data['version']);

        return back()->with('status', 'Version restored as a draft. Preview and publish when ready.');
    }

    private function publicSite(): TenantSite
    {
        $tenant = Tenant::query()->where('slug', ConnectedWebsiteService::SLUG)->firstOrFail();

        return TenantSite::query()->forTenant($tenant)->firstOrFail();
    }

    public function content(Request $request, ConnectedWebsiteService $service)
    {
        $site = $this->publicSite();
        if ($request->has('preview')) {
            $token = $request->validate(['preview' => ['required', 'string', 'max:4096']])['preview'];
            $content = $service->previewContent($site, $token);

            return response()->json(['renderer' => ConnectedWebsiteService::RENDERER, 'content' => $content, 'catalog' => $service->catalog($site, true), 'collections' => $service->collections($site, true), 'presentation' => $service->presentation($site, $site->draft_site_version_id, true), 'preview' => true])
                ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
        }
        $service->assertAvailable($site);

        return response()->json(['renderer' => ConnectedWebsiteService::RENDERER, 'content' => $service->content($site, $site->published_site_version_id), 'catalog' => $service->catalog($site), 'collections' => $service->collections($site), 'presentation' => $service->presentation($site, $site->published_site_version_id), 'version' => $site->published_site_version_id])
            ->header('Cache-Control', 'no-store');
    }

    public function inquire(Request $request, ConnectedWebsiteService $service)
    {
        $site = $this->publicSite();
        $service->assertAvailable($site);
        abort_if($request->has('preview') || $request->has('__preview'), 403);
        $data = $request->validate([
            'requestId' => ['required', 'uuid'], 'type' => ['required', Rule::in(['quote', 'wholesale', 'affiliate'])],
            'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email:rfc', 'max:190'],
            'company' => ['required_unless:type,quote', 'nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:60'], 'website' => ['nullable', 'url:http,https', 'max:500'],
            'businessType' => ['nullable', 'string', 'max:120'], 'notes' => ['required', 'string', 'max:3000'],
            'productSlug' => ['required_if:type,quote', 'nullable', function (string $attribute, mixed $value, \Closure $fail) use ($service, $site): void {
                if ($value === null || ! $service->activeProduct($site, (string) $value)) {
                    $fail('The selected product is unavailable.');
                }
            }],
            'quantity' => ['required_if:type,quote', 'nullable', 'integer', 'between:1,100'],
            'finish' => ['required_if:type,quote', 'nullable', 'string', 'max:120'],
            'affiliateCode' => ['nullable', 'string', 'max:80'],
        ]);
        DB::transaction(function () use ($site, $data, $service) {
            $site = TenantSite::query()->lockForUpdate()->findOrFail($site->id);
            $service->assertAvailable($site);
            $source = $data['type'] === 'quote' ? 'managed_website_quote' : 'managed_website';
            $key = 'connected:'.$data['requestId'];
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = FormSubmission::query()->forTenantId($site->tenant_id)->where('source_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals((string) data_get($existing->metadata, 'request_hash'), $hash), 409, 'Use a new request ID for different details.');

                return;
            }
            $payload = $data + ['message' => $data['notes'], 'service_needed' => $data['productSlug'] ?? $data['type']];
            $form = TenantForm::query()->firstOrCreate(
                ['tenant_id' => $site->tenant_id, 'slug' => 'connected-website-'.$data['type']],
                ['name' => 'Website '.$data['type'].' request', 'status' => 'active', 'channel' => 'website', 'schema' => array_keys($data), 'settings' => ['connected_renderer' => ConnectedWebsiteService::RENDERER]]
            );
            FormSubmission::query()->create([
                'tenant_form_id' => $form->id,
                'tenant_id' => $site->tenant_id, 'source' => $source, 'source_key' => $key, 'status' => 'submitted', 'submitted_at' => now(),
                'submitter_name' => $data['name'], 'submitter_email' => $data['email'], 'submitter_phone' => $data['phone'] ?? null, 'submitter_company' => $data['company'] ?? null,
                'payload' => $payload, 'normalized_payload' => $payload, 'metadata' => ['renderer' => ConnectedWebsiteService::RENDERER, 'site_id' => $site->id, 'request_hash' => $hash],
            ]);
        });

        return response()->json(['ok' => true], 201)->header('Cache-Control', 'no-store');
    }
}
