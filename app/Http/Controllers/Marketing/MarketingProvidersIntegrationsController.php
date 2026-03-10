<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\EventInstance;
use App\Models\MarketingEventSourceMapping;
use App\Models\MarketingImportRun;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Services\Marketing\MarketingEventAttributionService;
use App\Services\Marketing\MarketingLegacyImportService;
use App\Services\Marketing\SquareMarketingSyncService;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MarketingProvidersIntegrationsController extends Controller
{
    public function index(Request $request, MarketingEventAttributionService $attributionService): View
    {
        $search = trim((string) $request->query('search', ''));
        $sourceSystem = trim((string) $request->query('source_system', 'all'));
        $mapped = trim((string) $request->query('mapped', 'all'));

        $mappings = MarketingEventSourceMapping::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('raw_value', 'like', '%' . $search . '%')
                        ->orWhere('normalized_value', 'like', '%' . $search . '%')
                        ->orWhere('notes', 'like', '%' . $search . '%');
                });
            })
            ->when($sourceSystem !== 'all' && $sourceSystem !== '', fn ($query) => $query->where('source_system', $sourceSystem))
            ->when($mapped === 'mapped', fn ($query) => $query->whereNotNull('event_instance_id'))
            ->when($mapped === 'unmapped', fn ($query) => $query->whereNull('event_instance_id'))
            ->with('eventInstance:id,title,starts_at')
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString();

        $unmappedValues = $attributionService->unmappedValuesFromOrders();

        $sourceSystems = MarketingEventSourceMapping::query()
            ->distinct()
            ->orderBy('source_system')
            ->pluck('source_system')
            ->values();

        $recentRuns = MarketingImportRun::query()
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        return view('marketing/providers-integrations/index', [
            'section' => MarketingSectionRegistry::section('providers-integrations'),
            'sections' => $this->navigationItems(),
            'mappings' => $mappings,
            'search' => $search,
            'sourceSystem' => $sourceSystem,
            'mapped' => $mapped,
            'sourceSystems' => $sourceSystems,
            'unmappedValues' => $unmappedValues,
            'recentRuns' => $recentRuns,
            'squareCounts' => [
                'customers' => SquareCustomer::query()->count(),
                'orders' => SquareOrder::query()->count(),
                'payments' => SquarePayment::query()->count(),
            ],
            'consentRules' => $this->consentRules(),
        ]);
    }

    public function createMapping(Request $request): View
    {
        $mapping = new MarketingEventSourceMapping([
            'source_system' => (string) $request->query('source_system', 'square_tax_name'),
            'raw_value' => (string) $request->query('raw_value', ''),
            'normalized_value' => (string) $request->query('normalized_value', ''),
            'is_active' => true,
        ]);

        return view('marketing/providers-integrations/mapping-form', [
            'section' => MarketingSectionRegistry::section('providers-integrations'),
            'sections' => $this->navigationItems(),
            'mapping' => $mapping,
            'eventInstances' => $this->eventInstanceOptions(),
            'formMode' => 'create',
        ]);
    }

    public function storeMapping(Request $request, MarketingEventAttributionService $attributionService): RedirectResponse
    {
        $data = $request->validate([
            'source_system' => ['required', 'string', 'max:100'],
            'raw_value' => ['required', 'string', 'max:255'],
            'normalized_value' => ['nullable', 'string', 'max:255'],
            'event_instance_id' => ['nullable', 'integer', 'exists:event_instances,id'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        MarketingEventSourceMapping::query()->updateOrCreate(
            [
                'source_system' => trim((string) $data['source_system']),
                'raw_value' => trim((string) $data['raw_value']),
            ],
            [
                'normalized_value' => trim((string) ($data['normalized_value'] ?? '')) ?: null,
                'event_instance_id' => $data['event_instance_id'] ?? null,
                'confidence' => $data['confidence'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? false),
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ]
        );

        $attributionService->refreshSquareOrderAttributions(500);

        return redirect()
            ->route('marketing.providers-integrations')
            ->with('toast', ['style' => 'success', 'message' => 'Event source mapping created.']);
    }

    public function editMapping(MarketingEventSourceMapping $mapping): View
    {
        return view('marketing/providers-integrations/mapping-form', [
            'section' => MarketingSectionRegistry::section('providers-integrations'),
            'sections' => $this->navigationItems(),
            'mapping' => $mapping,
            'eventInstances' => $this->eventInstanceOptions(),
            'formMode' => 'edit',
        ]);
    }

    public function updateMapping(
        Request $request,
        MarketingEventSourceMapping $mapping,
        MarketingEventAttributionService $attributionService
    ): RedirectResponse
    {
        $data = $request->validate([
            'source_system' => ['required', 'string', 'max:100'],
            'raw_value' => ['required', 'string', 'max:255'],
            'normalized_value' => ['nullable', 'string', 'max:255'],
            'event_instance_id' => ['nullable', 'integer', 'exists:event_instances,id'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $mapping->fill([
            'source_system' => trim((string) $data['source_system']),
            'raw_value' => trim((string) $data['raw_value']),
            'normalized_value' => trim((string) ($data['normalized_value'] ?? '')) ?: null,
            'event_instance_id' => $data['event_instance_id'] ?? null,
            'confidence' => $data['confidence'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ])->save();

        $attributionService->refreshSquareOrderAttributions(500);

        return redirect()
            ->route('marketing.providers-integrations')
            ->with('toast', ['style' => 'success', 'message' => 'Event source mapping updated.']);
    }

    public function runSquareSync(Request $request, SquareMarketingSyncService $syncService): RedirectResponse
    {
        $data = $request->validate([
            'sync_type' => ['required', 'in:customers,orders,payments'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'since' => ['nullable', 'date'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $options = [
            'limit' => (int) ($data['limit'] ?? 200),
            'since' => $data['since'] ?? null,
            'dry_run' => (bool) ($data['dry_run'] ?? false),
            'created_by' => auth()->id(),
        ];

        match ($data['sync_type']) {
            'customers' => $syncService->syncCustomers($options),
            'orders' => $syncService->syncOrders($options),
            'payments' => $syncService->syncPayments($options),
        };

        return redirect()
            ->route('marketing.providers-integrations')
            ->with('toast', ['style' => 'success', 'message' => 'Square sync started and logged.']);
    }

    public function importLegacy(Request $request, MarketingLegacyImportService $importService): RedirectResponse
    {
        $data = $request->validate([
            'import_type' => ['required', 'in:yotpo_contacts_import,square_marketing_import'],
            'file' => ['required', 'file', 'mimes:csv,txt'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $importService->importFile(
            file: $data['file'],
            type: (string) $data['import_type'],
            createdBy: auth()->id(),
            dryRun: (bool) ($data['dry_run'] ?? false)
        );

        return redirect()
            ->route('marketing.providers-integrations')
            ->with('toast', ['style' => 'success', 'message' => 'Legacy import completed and logged.']);
    }

    /**
     * @return array<int,array{id:int,label:string}>
     */
    protected function eventInstanceOptions(): array
    {
        return EventInstance::query()
            ->orderByDesc('starts_at')
            ->orderBy('title')
            ->limit(300)
            ->get(['id', 'title', 'starts_at'])
            ->map(fn (EventInstance $row): array => [
                'id' => (int) $row->id,
                'label' => (string) $row->title . ' (' . (optional($row->starts_at)->toDateString() ?: 'no-date') . ')',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    protected function consentRules(): array
    {
        return [
            'Explicit opt-out always overrides opt-in.',
            'Email and SMS consent are handled independently.',
            'Imported consent only upgrades to opt-in when there is no stronger local opt-out signal.',
            'Ambiguous or missing consent is never auto-upgraded to true.',
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
}
