<?php

namespace App\Http\Controllers;

use App\Console\Commands\EverbranchPreparePestControlDemo;
use App\Models\FormSubmission;
use App\Models\Tenant;
use App\Models\TenantForm;
use App\Models\User;
use App\Services\Tenancy\TenantCommercialExperienceService;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PlatformProductPagesController extends Controller
{
    public function promo(TenantCommercialExperienceService $experienceService): Response
    {
        return response()->view('platform.promo', $experienceService->promoPayload());
    }

    public function industryDemo(?string $discipline = 'retail'): Response
    {
        $disciplines = [
            'retail' => 'Retail & product brands',
            'field' => 'Field & service teams',
            'projects' => 'Project work',
            'studio' => 'Independent studios',
            'practice' => 'Professional practices',
            'community' => 'Community teams',
        ];
        $discipline = strtolower(trim((string) $discipline));

        abort_unless(array_key_exists($discipline, $disciplines), 404);

        return response()->view('platform.industry-demo', [
            'discipline' => $discipline,
            'disciplines' => $disciplines,
        ]);
    }

    public function pestControlFleetDemo(): Response
    {
        return response()->view('platform.pest-control-fleet-demo', [
            'demoEmail' => EverbranchPreparePestControlDemo::OWNER_EMAIL,
            'demoPassword' => EverbranchPreparePestControlDemo::DEFAULT_PASSWORD,
        ]);
    }

    public function pestControlFleetDemoLogin(Request $request): RedirectResponse
    {
        $demoOwner = User::query()
            ->where('email', EverbranchPreparePestControlDemo::OWNER_EMAIL)
            ->where('requested_via', 'fictional_pest_control_demo')
            ->where('is_active', true)
            ->firstOrFail();

        Auth::login($demoOwner);
        $request->session()->regenerate();

        return redirect()->route('field-service.index', ['tenant' => 'green-shield-pest-control']);
    }

    public function pestControlWebsite(): Response
    {
        return response()->view('platform.pest-control-website');
    }

    public function submitPestControlReminder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:80'],
            'property_type' => ['required', 'in:home,business'],
            'reminders' => ['required', 'array', 'min:1', 'max:3'],
            'reminders.*' => ['in:service,seasonal,mosquito'],
            'message' => ['nullable', 'string', 'max:600'],
            'website' => ['nullable', 'max:0'],
        ]);

        $tenant = Tenant::query()->where('slug', 'green-shield-pest-control')->firstOrFail();
        $form = TenantForm::query()->firstOrCreate(
            ['tenant_id' => (int) $tenant->id, 'slug' => 'pest-prevention-reminders'],
            [
                'name' => 'Pest prevention reminders',
                'description' => 'Customer-facing fictional demo request capture. It never sends messages.',
                'status' => 'active',
                'channel' => 'website',
                'schema' => ['name', 'email', 'phone', 'property_type', 'reminders', 'message'],
                'settings' => ['fictional_demo' => true, 'delivery_enabled' => false],
            ],
        );

        FormSubmission::query()->create([
            'tenant_id' => (int) $tenant->id,
            'tenant_form_id' => (int) $form->id,
            'status' => 'submitted',
            'source' => 'fictional_pest_control_website',
            'source_key' => 'green-shield-reminder-'.Str::uuid(),
            'submitted_at' => now(),
            'submitter_name' => $data['name'],
            'submitter_email' => strtolower($data['email']),
            'submitter_phone' => $data['phone'] ?: null,
            'payload' => [
                'property_type' => $data['property_type'],
                'reminders' => array_values($data['reminders']),
                'message' => $data['message'] ?: null,
            ],
            'normalized_payload' => ['entrypoint' => 'green_shield_public_demo'],
            'metadata' => [
                'fictional_demo' => true,
                'delivery_enabled' => false,
                'notice' => 'Captured only for the public Green Shield demo. No customer profile, consent, workflow, or message is created.',
            ],
        ]);

        return redirect()
            ->to(route('platform.pest-control-website').'#reminders')
            ->with('pest_reminder_status', 'Your fictional reminder request is captured. In a live workspace, this can route to a consented reminder journey.');
    }

    public function contact(Request $request): Response
    {
        $intent = strtolower(trim((string) $request->query('intent', 'contact')));

        return response()->view('platform.contact', [
            'contact' => (array) config('product_surfaces.contact', []),
            'intent' => in_array($intent, ['contact', 'sales', 'walkthrough'], true) ? $intent : 'contact',
        ]);
    }

    public function plans(TenantCommercialExperienceService $experienceService): Response
    {
        return response()->view('platform.plans', $experienceService->publicPlansPayload());
    }

    public function demo(TenantCommercialExperienceService $experienceService): Response
    {
        $plansPayload = $experienceService->publicPlansPayload();

        return response()->view('platform.access-request', [
            'surface' => (array) config('product_surfaces.demo', []),
            'form_options' => $experienceService->publicAccessRequestOptions(),
            'intent' => 'demo',
            'plan_cards' => (array) ($plansPayload['plan_cards'] ?? []),
            'addon_cards' => (array) ($plansPayload['addon_cards'] ?? []),
            'recommended_plan_key' => (string) ($plansPayload['recommended_plan_key'] ?? 'growth'),
        ]);
    }

    public function start(TenantCommercialExperienceService $experienceService): Response
    {
        $plansPayload = $experienceService->publicPlansPayload();

        return response()->view('platform.access-request', [
            'surface' => (array) config('product_surfaces.start_client', []),
            'form_options' => $experienceService->publicAccessRequestOptions(),
            'intent' => 'production',
            'plan_cards' => (array) ($plansPayload['plan_cards'] ?? []),
            'addon_cards' => (array) ($plansPayload['addon_cards'] ?? []),
            'recommended_plan_key' => (string) ($plansPayload['recommended_plan_key'] ?? 'growth'),
        ]);
    }

    public function requestSubmitted(): Response
    {
        $intent = strtolower(trim((string) request()->query('intent', 'production')));

        return response()->view('platform.request-submitted', [
            'intent' => in_array($intent, ['demo', 'production'], true) ? $intent : 'production',
        ]);
    }

    public function catalogFeed(TenantModuleCatalogService $catalogService): JsonResponse
    {
        return response()->json($catalogService->publicCatalogPayload());
    }

    public function moduleExplorer(Request $request, TenantModuleCatalogService $catalogService, ?string $module = null): Response
    {
        $catalog = $catalogService->publicCatalogPayload();
        $modules = collect((array) ($catalog['modules'] ?? []));
        $selectedModule = null;

        if (filled($module)) {
            $selectedModule = $modules->firstWhere('key', strtolower(trim((string) $module)));
            abort_unless(is_array($selectedModule), 404);
        }

        return response()->view('platform.module-explorer', [
            'catalog' => $catalog,
            'modules' => $modules->values()->all(),
            'selectedModule' => $selectedModule,
            'filters' => [
                'query' => trim((string) $request->query('q', '')),
                'category' => trim((string) $request->query('category', '')),
                'integration' => trim((string) $request->query('integration', '')),
                'setup' => trim((string) $request->query('setup', '')),
                'industry' => trim((string) $request->query('industry', '')),
                'sort' => trim((string) $request->query('sort', 'recommended')),
            ],
        ]);
    }
}
