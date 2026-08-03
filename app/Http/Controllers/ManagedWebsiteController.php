<?php

namespace App\Http\Controllers;

use App\Models\FormSubmission;
use App\Models\Tenant;
use App\Models\TenantForm;
use App\Models\TenantSite;
use App\Models\TenantSiteDomain;
use App\Models\TenantSiteMedia;
use App\Models\TenantSitePage;
use App\Models\TenantSitePageVersion;
use App\Models\TenantSiteVersion;
use App\Services\ManagedWebsite\ManagedWebsiteAccessService;
use App\Services\ManagedWebsite\ManagedWebsiteDomainService;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use App\Services\ManagedWebsite\WebsitePilotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ManagedWebsiteController extends Controller
{
    public function index(Request $request, ManagedWebsiteService $websites, ManagedWebsiteDomainService $domains, WebsitePilotService $pilot, ManagedWebsiteAccessService $access): View
    {
        $tenant = $this->tenant($request);
        $site = TenantSite::query()->forTenant($tenant)->with(['pages.draftVersion', 'pages.publishedVersion', 'draftSiteVersion', 'publishedSiteVersion', 'domains', 'setup'])->first();
        $setup = $site?->setup;
        $checklist = $pilot->checklist($site, $setup);

        return view('managed-website.index', [
            'tenant' => $tenant,
            'site' => $site,
            'pages' => $site?->pages ?? collect(),
            'templates' => $this->templates(),
            'isEditorEnabled' => $websites->editorEnabledFor($tenant),
            'isPublishingEnabled' => $websites->publishingEnabled(),
            'isPublicRenderEnabled' => $websites->publicRenderingEnabled(),
            'themes' => $websites->themes(),
            'domainsEnabled' => $domains->enabledFor($tenant),
            'domainTarget' => $domains->connectionTarget(),
            'domainConnectionRecords' => $site?->domains
                ? $site->domains->mapWithKeys(fn (TenantSiteDomain $domain): array => [$domain->id => $domains->connectionRecords($domain)])
                : collect(),
            'publicUrl' => $site ? $domains->publicUrl($site) : null,
            'platformUrl' => $site ? $domains->platformUrl($site) : null,
            'setup' => $setup,
            'checklist' => $checklist,
            'nextChecklistItem' => collect($checklist)->firstWhere('complete', false),
            'canPublish' => $access->canPublish($tenant, $request->user()),
        ]);
    }

    public function saveSetup(Request $request, ManagedWebsiteService $websites, WebsitePilotService $pilot, WebsiteCommerceService $commerce): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        $data = $request->validate([
            'contact_name' => ['nullable', 'string', 'max:190'],
            'contact_email' => ['nullable', 'email:rfc,dns', 'max:190'],
            'contact_phone' => ['nullable', 'string', 'max:80'],
            'hours' => ['nullable', 'string', 'max:500'],
            'service_area' => ['nullable', 'string', 'max:500'],
            'service_title' => ['nullable', 'string', 'max:190'],
            'service_description' => ['nullable', 'string', 'max:8000'],
        ]);
        $site = $websites->createSite($tenant, $request->user());
        $websites->applyTheme($site, 'collins-electric', $request->user());
        $pilot->saveSetup($tenant, $site, $data, $request->user());
        if (filled($data['service_title'] ?? null)) {
            $commerce->saveProduct($site, [
                'title' => $data['service_title'], 'description' => $data['service_description'] ?? '', 'product_type' => 'quote',
                'status' => 'active', 'price' => '0', 'track_inventory' => false, 'is_available' => true,
            ]);
        }

        return redirect()->route('managed-website.index')->with('status', 'Your Website setup was saved. You can pick up where you left off anytime.');
    }

    public function leads(Request $request): View
    {
        $tenant = $this->tenant($request);
        $submissions = FormSubmission::query()
            ->forTenant($tenant)
            ->whereIn('source', ['managed_website', 'managed_website_quote'])
            ->with('form')
            ->latest('submitted_at')
            ->paginate(30);

        return view('managed-website.leads', compact('tenant', 'submissions'));
    }

    public function markMobilePreviewed(Request $request, ManagedWebsiteService $websites, WebsitePilotService $pilot): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        $pilot->markMobilePreviewed($tenant, $request->user());

        return back()->with('status', 'Mobile preview marked as checked.');
    }

    public function editor(Request $request, TenantSitePage $page, ManagedWebsiteService $websites): View
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        abort_unless((int) $page->tenant_id === (int) $tenant->id, 404);
        $site = TenantSite::query()->forTenant($tenant)->with(['pages.draftVersion', 'pages.publishedVersion', 'draftSiteVersion', 'publishedSiteVersion'])->findOrFail($page->tenant_site_id);

        return view('managed-website.editor', compact('tenant', 'site', 'page') + [
            'pages' => $site->pages,
            'theme' => $websites->siteVersion($site, true),
            'isPublishingEnabled' => $websites->publishingEnabled(),
        ]);
    }

    public function saveTheme(Request $request, ManagedWebsiteService $websites): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        $site = TenantSite::query()->forTenant($tenant)->firstOrFail();
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'navigation' => ['required', 'array', 'max:10'],
            'seo' => ['nullable', 'array'],
        ]);
        $version = $websites->saveSiteDraft($site, $data, $request->user());

        return response()->json(['saved_at' => $version->created_at->toIso8601String(), 'version_number' => $version->version_number, 'settings' => $version->settings, 'navigation' => $version->navigation]);
    }

    public function preview(Request $request, TenantSitePage $page, ManagedWebsiteService $websites)
    {
        return $this->draftPreviewResponse($request, $page, $websites, false);
    }

    public function previewSite(Request $request, TenantSitePage $page, ManagedWebsiteService $websites)
    {
        return $this->draftPreviewResponse($request, $page, $websites, true);
    }

    protected function draftPreviewResponse(Request $request, TenantSitePage $page, ManagedWebsiteService $websites, bool $interactive)
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        abort_unless((int) $page->tenant_id === (int) $tenant->id, 404);
        $site = TenantSite::query()->forTenant($tenant)->with(['draftSiteVersion', 'pages'])->findOrFail($page->tenant_site_id);
        $payload = $websites->draftPage($site, $page);
        abort_unless($payload !== null, 404);

        $response = response()
            ->view('managed-website.public', $payload + [
                'tenant' => $tenant,
                'previewMode' => $interactive ? 'site' : 'editor',
                'previewLinks' => $this->draftPreviewLinks($site),
                'editorUrl' => route('managed-website.editor', ['page' => $page]),
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'same-origin');

        return $interactive
            ? $response->header('Content-Security-Policy', "frame-ancestors 'none'")->header('X-Frame-Options', 'DENY')
            : $response->header('Content-Security-Policy', "frame-ancestors 'self'")->header('X-Frame-Options', 'SAMEORIGIN');
    }

    public function thumbnailSource(TenantSiteVersion $siteVersion, TenantSitePageVersion $pageVersion)
    {
        abort_unless((int) $siteVersion->tenant_id === (int) $pageVersion->tenant_id && (int) $siteVersion->tenant_site_id === (int) $pageVersion->tenant_site_id, 404);
        $site = TenantSite::query()->findOrFail($siteVersion->tenant_site_id);
        $page = TenantSitePage::query()->findOrFail($pageVersion->tenant_site_page_id);
        abort_unless((int) $site->draft_site_version_id === (int) $siteVersion->id && (int) $page->draft_version_id === (int) $pageVersion->id, 404);

        return response()
            ->view('managed-website.public', [
                'tenant' => Tenant::query()->findOrFail($site->tenant_id),
                'site' => $site,
                'page' => $page,
                'version' => $pageVersion,
                'theme' => $siteVersion,
                'isDraftPreview' => true,
                'previewMode' => 'thumbnail',
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Security-Policy', "frame-ancestors 'none'")
            ->header('X-Frame-Options', 'DENY')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function showThumbnail(Request $request, TenantSiteVersion $siteVersion)
    {
        $tenant = $this->tenant($request);
        abort_unless((int) $siteVersion->tenant_id === (int) $tenant->id && filled($siteVersion->thumbnail_path), 404);
        abort_unless(Storage::disk('local')->exists((string) $siteVersion->thumbnail_path), 404);

        return Storage::disk('local')->response((string) $siteVersion->thumbnail_path, 'website-draft.png', ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
    }

    public function media(Request $request, ManagedWebsiteService $websites): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        $site = TenantSite::query()->forTenant($tenant)->firstOrFail();
        $media = $site->media()->latest()->get()->map(fn (TenantSiteMedia $item): array => $this->mediaPayload($item));

        return response()->json(['media' => $media]);
    }

    public function storeMedia(Request $request, ManagedWebsiteService $websites): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        $site = TenantSite::query()->forTenant($tenant)->firstOrFail();
        $maxKilobytes = max(1024, (int) ceil(((int) config('managed_website.media_max_bytes', 10485760)) / 1024));
        $data = $request->validate([
            'image' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/avif', 'max:'.$maxKilobytes],
            'alt_text' => ['nullable', 'string', 'max:500'],
        ]);
        $file = $data['image'];
        $extension = strtolower($file->extension() ?: 'bin');
        $path = 'tenant-site-media/'.$tenant->id.'/'.Str::uuid().'.'.$extension;
        $disk = 'local';
        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));
        $media = TenantSiteMedia::query()->create([
            'tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'uploaded_by_user_id' => $request->user()?->id,
            'storage_disk' => $disk, 'storage_path' => $path, 'file_name' => basename($file->getClientOriginalName()),
            'mime_type' => $file->getMimeType(), 'file_size' => $file->getSize(), 'checksum' => hash_file('sha256', $file->getRealPath()),
            'kind' => 'image', 'source' => 'upload', 'alt_text' => strip_tags((string) ($data['alt_text'] ?? '')),
        ]);
        $websites->recordEvent($site, null, $request->user(), 'site.media_uploaded', ['media_id' => $media->id]);

        return response()->json(['media' => $this->mediaPayload($media)], 201);
    }

    public function showMedia(Request $request, TenantSiteMedia $media)
    {
        $tenant = $request->attributes->get('current_tenant') ?? $request->attributes->get('host_tenant');
        abort_unless($tenant instanceof Tenant && (int) $tenant->id === (int) $media->tenant_id, 404);
        $site = TenantSite::query()->forTenant($tenant)->findOrFail($media->tenant_site_id);
        abort_unless($request->user() || ($site->public_enabled && $site->status === 'published'), 404);
        abort_unless(Storage::disk($media->storage_disk)->exists($media->storage_path), 404);

        return Storage::disk($media->storage_disk)->response($media->storage_path, $media->file_name, ['Content-Type' => $media->mime_type, 'Cache-Control' => 'public, max-age=86400']);
    }

    public function saveEditor(Request $request, TenantSitePage $page, ManagedWebsiteService $websites): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        abort_unless((int) $page->tenant_id === (int) $tenant->id, 404);
        $site = TenantSite::query()->forTenant($tenant)->findOrFail($page->tenant_site_id);
        $data = $request->validate(['title' => ['required', 'string', 'max:190'], 'blocks' => ['required', 'array', 'max:40'], 'seo' => ['nullable', 'array']]);
        $version = $websites->saveDraft($site, $page, $data, $request->user());

        return response()->json(['saved_at' => $version->created_at->toIso8601String(), 'version_number' => $version->version_number, 'blocks' => $version->blocks]);
    }

    public function applyTheme(Request $request, ManagedWebsiteService $websites): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        $site = TenantSite::query()->forTenant($tenant)->firstOrFail();
        $websites->applyTheme($site, (string) $request->validate(['theme_key' => ['required', 'string', 'max:80']])['theme_key'], $request->user());

        return back()->with('status', 'Theme applied as a draft. Review it in the editor before publishing.');
    }

    public function requestDomain(Request $request, ManagedWebsiteService $websites, ManagedWebsiteDomainService $domains): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        abort_unless($domains->enabledFor($tenant), 423, 'Custom domains are not enabled for this website yet.');
        $site = TenantSite::query()->forTenant($tenant)->firstOrFail();
        $domain = $domains->request($site, (string) $request->validate(['domain' => ['required', 'string', 'max:300']])['domain'], $request->user());

        return back()->with('status', 'Domain saved. Add the records shown below in one DNS visit, then check the connection.')->with('domain_wizard_id', $domain->id);
    }

    public function verifyDomain(Request $request, TenantSiteDomain $domain, ManagedWebsiteService $websites, ManagedWebsiteDomainService $domains): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        abort_unless((int) $domain->tenant_id === (int) $tenant->id, 404);
        abort_unless($domains->enabledFor($tenant), 423, 'Custom domains are not enabled for this website yet.');
        $domain = $domains->verify($domain, $request->user());

        return back()->with('status', $domain->status === 'verified' ? 'Domain ownership verified. You can activate it after the live routing check.' : 'The verification record is not visible yet. Check the record name and try again shortly.')->with('domain_wizard_id', $domain->id);
    }

    public function activateDomain(Request $request, TenantSiteDomain $domain, ManagedWebsiteService $websites, ManagedWebsiteDomainService $domains): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        abort_unless((int) $domain->tenant_id === (int) $tenant->id, 404);
        $domains->activate($domain, $request->user());

        return back()->with('status', $domain->hostname.' is now the live website address.')->with('domain_wizard_id', $domain->id);
    }

    public function deactivateDomain(Request $request, TenantSiteDomain $domain, ManagedWebsiteService $websites, ManagedWebsiteDomainService $domains): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        abort_unless((int) $domain->tenant_id === (int) $tenant->id, 404);
        $domains->deactivate($domain, $request->user());

        return back()->with('status', 'Custom domain disabled. The last published Everbranch subdomain remains unchanged.');
    }

    public function cancelDomain(Request $request, TenantSiteDomain $domain, ManagedWebsiteService $websites, ManagedWebsiteDomainService $domains): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        abort_unless((int) $domain->tenant_id === (int) $tenant->id, 404);
        abort_unless($domains->enabledFor($tenant), 423, 'Custom domains are not enabled for this website yet.');
        $domains->cancel($domain, $request->user());

        return back()->with('status', 'Domain setup removed. Enter the address you want to connect.');
    }

    public function create(Request $request, ManagedWebsiteService $websites): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        $websites->createSite($tenant, $request->user());

        return redirect()->route('managed-website.index')->with('status', 'Website draft created. Nothing is public until you publish.');
    }

    public function storePage(Request $request, ManagedWebsiteService $websites): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        $site = TenantSite::query()->forTenant($tenant)->firstOrFail();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'page_type' => ['required', 'in:landing,services,about,contact,faq'],
        ]);
        abort_if($site->pages()->where('slug', $data['slug'])->exists(), 422, 'That page address is already in use.');
        $page = TenantSitePage::query()->create([
            'tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'slug' => $data['slug'],
            'page_type' => $data['page_type'], 'title' => $data['title'], 'is_navigation_visible' => true,
        ]);
        $websites->saveDraft($site, $page, ['title' => $data['title'], 'blocks' => $this->templateBlocks($data['page_type'], $data['title'])], $request->user());

        return back()->with('status', 'Page draft added.');
    }

    public function destroyPage(Request $request, TenantSitePage $page, ManagedWebsiteService $websites): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        abort_unless((int) $page->tenant_id === (int) $tenant->id, 404);
        abort_if($page->slug === '/', 422, 'The Home page cannot be deleted.');
        $page->delete();

        return back()->with('status', 'Page removed.');
    }

    public function savePage(Request $request, TenantSitePage $page, ManagedWebsiteService $websites): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        abort_unless((int) $page->tenant_id === (int) $tenant->id, 404);
        $site = TenantSite::query()->forTenant($tenant)->findOrFail($page->tenant_site_id);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'blocks' => ['required', 'array', 'max:40'],
            'seo' => ['nullable', 'array'],
        ]);
        $websites->saveDraft($site, $page, $data, $request->user());

        return back()->with('status', 'Draft saved.');
    }

    public function publish(Request $request, ManagedWebsiteService $websites, ManagedWebsiteAccessService $access, WebsitePilotService $pilot): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        $this->requirePublisher($tenant, $request, $access);
        $site = TenantSite::query()->forTenant($tenant)->firstOrFail();
        abort_unless($pilot->readyToPublish($site, $site->setup), 422, 'Finish the Website checklist before publishing.');
        $websites->publish($site, $request->user());

        return back()->with('status', $websites->publicRenderingEnabled()
            ? 'Published. Your last approved snapshot is now live.'
            : 'Published safely. Public rendering is still disabled for this rollout.');
    }

    public function publishEditor(Request $request, ManagedWebsiteService $websites, ManagedWebsiteAccessService $access, WebsitePilotService $pilot): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        $this->requirePublisher($tenant, $request, $access);
        $site = TenantSite::query()->forTenant($tenant)->firstOrFail();
        abort_unless($pilot->readyToPublish($site, $site->setup), 422, 'Finish the Website checklist before publishing.');
        $websites->publish($site, $request->user());

        return response()->json(['status' => $websites->publicRenderingEnabled() ? 'published' : 'published_safely']);
    }

    public function rollback(Request $request, TenantSitePage $page, TenantSitePageVersion $version, ManagedWebsiteService $websites, ManagedWebsiteAccessService $access): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->requireEditor($tenant, $websites);
        $this->requirePublisher($tenant, $request, $access);
        $site = TenantSite::query()->forTenant($tenant)->findOrFail($page->tenant_site_id);
        abort_unless((int) $page->tenant_id === (int) $tenant->id && (int) $version->tenant_id === (int) $tenant->id, 404);
        $websites->rollback($site, $page, $version, $request->user());

        return back()->with('status', 'Published page restored from its immutable history.');
    }

    public function showPublic(Request $request, ?string $path, ManagedWebsiteService $websites): View
    {
        $tenant = $request->attributes->get('host_tenant');
        abort_unless($tenant instanceof Tenant, 404);
        $payload = $websites->publicPage($tenant, (string) $path);
        abort_unless($payload !== null, 404);
        abort_unless($websites->publicHostAllowed($payload['site'], $request->getHost()), 404);

        return view('managed-website.public', $payload + ['tenant' => $tenant]);
    }

    /** @return array<string,mixed> */
    protected function mediaPayload(TenantSiteMedia $media): array
    {
        return ['id' => $media->id, 'name' => $media->file_name, 'url' => route('managed-website.media.show', ['media' => $media]), 'alt_text' => $media->alt_text, 'mime_type' => $media->mime_type, 'size' => $media->file_size];
    }

    public function submitForm(Request $request, TenantSitePage $page): RedirectResponse
    {
        $site = TenantSite::query()->forTenantId((int) $page->tenant_id)->findOrFail($page->tenant_site_id);
        abort_unless($site->public_enabled && (bool) config('managed_website.public_render_enabled', false), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email:rfc,dns', 'max:190'],
            'phone' => ['nullable', 'string', 'max:80'],
            'message' => ['required', 'string', 'max:4000'],
            'website' => ['nullable', 'max:0'],
        ]);
        $form = TenantForm::query()->firstOrCreate(
            ['tenant_id' => $site->tenant_id, 'slug' => 'managed-website-contact'],
            ['name' => 'Website contact', 'status' => 'active', 'channel' => 'website', 'schema' => ['name', 'email', 'phone', 'message'], 'settings' => ['managed_website' => true]]
        );
        FormSubmission::query()->create([
            'tenant_id' => $site->tenant_id, 'tenant_form_id' => $form->id, 'status' => 'submitted', 'source' => 'managed_website',
            'source_key' => 'managed-website-'.Str::uuid(), 'submitted_at' => now(), 'submitter_name' => $data['name'],
            'submitter_email' => $data['email'], 'submitter_phone' => $data['phone'] ?? null,
            'payload' => ['message' => $data['message']], 'normalized_payload' => ['page_id' => $page->id],
        ]);

        return back()->with('website_form_status', 'Thanks — your message was received.');
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    /** @return array<string,string> */
    protected function draftPreviewLinks(TenantSite $site): array
    {
        return $site->pages
            ->filter(fn (TenantSitePage $candidate): bool => (int) $candidate->tenant_id === (int) $site->tenant_id)
            ->mapWithKeys(function (TenantSitePage $candidate): array {
                $path = trim('/'.trim((string) $candidate->slug, '/'), '/');
                $path = $path === '' ? '/' : '/'.$path;

                return [$path => route('managed-website.editor.preview.site', ['page' => $candidate])];
            })
            ->all();
    }

    protected function requireEditor(Tenant $tenant, ManagedWebsiteService $websites): void
    {
        abort_unless($websites->editorEnabledFor($tenant), 423, 'Website editing is not enabled for this workspace yet.');
    }

    protected function requirePublisher(Tenant $tenant, Request $request, ManagedWebsiteAccessService $access): void
    {
        abort_unless($access->canPublish($tenant, $request->user()), 403, 'Only a workspace owner or admin can publish or restore a live website.');
    }

    /** @return array<int,array<string,string>> */
    protected function templates(): array
    {
        return [
            ['key' => 'services', 'label' => 'Services', 'description' => 'Explain what you do and where customers should start.'],
            ['key' => 'about', 'label' => 'About', 'description' => 'Tell the team and business story.'],
            ['key' => 'contact', 'label' => 'Contact', 'description' => 'Collect a clear, tenant-owned inquiry.'],
            ['key' => 'faq', 'label' => 'FAQ', 'description' => 'Answer common questions before customers ask.'],
            ['key' => 'landing', 'label' => 'Landing page', 'description' => 'Build a focused offer or campaign page.'],
        ];
    }

    /** @return array<int,array<string,string>> */
    protected function templateBlocks(string $type, string $title): array
    {
        return match ($type) {
            'contact' => [['type' => 'hero', 'heading' => $title, 'body' => 'Tell us how we can help.'], ['type' => 'contact_form', 'heading' => 'Send a message']],
            'faq' => [['type' => 'hero', 'heading' => $title, 'body' => 'Helpful answers, in one place.'], ['type' => 'faq', 'question' => 'What should I know before reaching out?', 'answer' => 'Use this page to add an accurate answer.']],
            default => [['type' => 'hero', 'heading' => $title, 'body' => 'Add your business story here.'], ['type' => 'text', 'heading' => 'What we do', 'body' => 'Use clear, customer-friendly language.']],
        };
    }
}
