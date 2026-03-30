<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingMessageTemplate;
use App\Models\MarketingProfile;
use App\Services\Marketing\MarketingTenantOwnershipService;
use App\Services\Marketing\MarketingTemplateRenderer;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MarketingMessageTemplatesController extends Controller
{
    public function __construct(
        protected MarketingTenantOwnershipService $ownershipService
    ) {
    }

    public function index(Request $request): View
    {
        $tenantId = $this->resolveTenantId($request);
        $templatesQuery = MarketingMessageTemplate::query()
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($this->strictTenantMode() && $tenantId !== null) {
            $templateIds = $this->ownershipService->tenantTemplateIds($tenantId);
            if ($templateIds->isEmpty()) {
                $templatesQuery->whereRaw('1 = 0');
            } else {
                $templatesQuery->whereIn('id', $templateIds->all());
            }
        }

        $templates = $templatesQuery->paginate(30);

        return view('marketing/templates/index', [
            'section' => MarketingSectionRegistry::section('message-templates'),
            'sections' => $this->navigationItems(),
            'templates' => $templates,
        ]);
    }

    public function create(Request $request): View
    {
        $tenantId = $this->resolveTenantId($request);

        $channel = strtolower(trim((string) $request->query('channel', 'sms')));
        if (!in_array($channel, ['sms', 'email'], true)) {
            $channel = 'sms';
        }

        $profile = null;
        $previewText = null;
        $profileId = (int) $request->query('profile_id', 0);
        if ($profileId > 0) {
            $profile = MarketingProfile::query()
                ->when($tenantId !== null, fn ($query) => $query->forTenantId($tenantId))
                ->find($profileId);
        }

        $template = new MarketingMessageTemplate([
            'channel' => $channel,
            'tone' => 'friendly',
            'is_active' => true,
            'template_text' => $channel === 'email'
                ? 'Hi {{first_name}}, we saved this for you. Ready for your next order?'
                : 'Hi {{first_name}}, we miss you. Want a quick restock?',
        ]);

        if ($profile) {
            $previewText = $this->resolveRenderer()->renderTemplate($template, $profile);
        }

        return view('marketing/templates/form', [
            'section' => MarketingSectionRegistry::section('message-templates'),
            'sections' => $this->navigationItems(),
            'template' => $template,
            'mode' => 'create',
            'previewText' => $previewText,
            'previewProfile' => $profile,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);

        $data = $this->validated($request);
        $template = MarketingMessageTemplate::query()->create([
            ...$data,
            'tenant_id' => $tenantId,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        return redirect()
            ->route('marketing.message-templates.edit', $template)
            ->with('toast', ['style' => 'success', 'message' => 'Template created.']);
    }

    public function edit(Request $request, MarketingMessageTemplate $template): View
    {
        $tenantId = $this->resolveTenantId($request);
        $this->assertTemplateAccess($template, $tenantId);

        return view('marketing/templates/form', [
            'section' => MarketingSectionRegistry::section('message-templates'),
            'sections' => $this->navigationItems(),
            'template' => $template,
            'mode' => 'edit',
            'previewText' => null,
        ]);
    }

    public function update(Request $request, MarketingMessageTemplate $template): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $this->assertTemplateAccess($template, $tenantId);

        $data = $this->validated($request);
        $template->fill([
            ...$data,
            'updated_by' => auth()->id(),
        ])->save();

        return redirect()
            ->route('marketing.message-templates.edit', $template)
            ->with('toast', ['style' => 'success', 'message' => 'Template updated.']);
    }

    public function preview(
        MarketingMessageTemplate $template,
        Request $request,
        MarketingTemplateRenderer $renderer
    ): View {
        $tenantId = $this->resolveTenantId($request);
        $this->assertTemplateAccess($template, $tenantId);

        $profileId = (int) $request->query('profile_id', 0);
        $profile = $profileId > 0
            ? MarketingProfile::query()
                ->when($tenantId !== null, fn ($query) => $query->forTenantId($tenantId))
                ->find($profileId)
            : MarketingProfile::query()
                ->when($tenantId !== null, fn ($query) => $query->forTenantId($tenantId))
                ->orderByDesc('id')
                ->first();
        $previewText = $profile ? $renderer->renderTemplate($template, $profile) : null;

        return view('marketing/templates/form', [
            'section' => MarketingSectionRegistry::section('message-templates'),
            'sections' => $this->navigationItems(),
            'template' => $template,
            'mode' => 'edit',
            'previewText' => $previewText,
            'previewProfile' => $profile,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'in:sms,email'],
            'objective' => ['nullable', 'in:winback,repeat_purchase,event_followup,consent_capture,review_request'],
            'tone' => ['nullable', 'string', 'max:100'],
            'template_text' => ['required', 'string', 'max:5000'],
            'variables_raw' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $variables = collect(explode(',', (string) ($data['variables_raw'] ?? '')))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();

        return [
            'name' => trim((string) $data['name']),
            'channel' => (string) $data['channel'],
            'objective' => $data['objective'] ?? null,
            'tone' => trim((string) ($data['tone'] ?? '')) ?: null,
            'template_text' => (string) $data['template_text'],
            'variables_json' => $variables,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }

    /**
     * @return array<int,array{key:string,label:string,href:string,current:bool}>
     */
    protected function navigationItems(): array
    {
        $items = [];
        foreach (MarketingSectionRegistry::sections() as $key => $section) {
            $items[] = [
                'key' => $key,
                'label' => $section['label'],
                'href' => route($section['route']),
                'current' => request()->routeIs($section['route']) || request()->routeIs($section['route'] . '.*'),
            ];
        }

        return $items;
    }

    protected function resolveRenderer(): MarketingTemplateRenderer
    {
        return app(MarketingTemplateRenderer::class);
    }

    protected function strictTenantMode(): bool
    {
        return $this->ownershipService->strictModeEnabled();
    }

    protected function resolveTenantId(Request $request): ?int
    {
        return $this->ownershipService->resolveTenantId($request, $this->strictTenantMode());
    }

    protected function assertTemplateAccess(MarketingMessageTemplate $template, ?int $tenantId): void
    {
        if (! $this->strictTenantMode() || $tenantId === null) {
            return;
        }

        if (! $this->ownershipService->templateOwnedByTenant((int) $template->id, $tenantId)) {
            abort(404);
        }
    }
}
