<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingSegment;
use App\Support\Marketing\MarketingSectionRegistry;
use App\Services\Marketing\MarketingSegmentPreviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MarketingSegmentsController extends Controller
{
    public function index(): View
    {
        $segments = MarketingSegment::query()
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->paginate(30);

        return view('marketing/segments/index', [
            'section' => MarketingSectionRegistry::section('segments'),
            'sections' => $this->navigationItems(),
            'segments' => $segments,
        ]);
    }

    public function create(): View
    {
        return view('marketing/segments/form', [
            'section' => MarketingSectionRegistry::section('segments'),
            'sections' => $this->navigationItems(),
            'segment' => new MarketingSegment([
                'status' => 'draft',
                'channel_scope' => 'any',
                'rules_json' => [
                    'logic' => 'and',
                    'conditions' => [
                        ['field' => 'total_orders', 'operator' => 'gt', 'value' => 1],
                    ],
                    'groups' => [],
                ],
            ]),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'in:draft,active,paused,archived'],
            'channel_scope' => ['nullable', 'in:sms,email,any'],
            'rule_logic' => ['nullable', 'in:and,or'],
            'conditions' => ['nullable', 'array'],
            'conditions.*.field' => ['nullable', 'string', 'max:100'],
            'conditions.*.operator' => ['nullable', 'string', 'max:50'],
            'conditions.*.value' => ['nullable'],
        ]);

        $segment = MarketingSegment::query()->create([
            'name' => trim((string) $data['name']),
            'slug' => Str::slug((string) $data['name']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'status' => (string) $data['status'],
            'channel_scope' => (string) ($data['channel_scope'] ?? 'any'),
            'rules_json' => $this->rulesFromRequest($request),
            'is_system' => false,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        return redirect()
            ->route('marketing.segments.preview', $segment)
            ->with('toast', ['style' => 'success', 'message' => 'Segment created.']);
    }

    public function edit(MarketingSegment $segment): View
    {
        return view('marketing/segments/form', [
            'section' => MarketingSectionRegistry::section('segments'),
            'sections' => $this->navigationItems(),
            'segment' => $segment,
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, MarketingSegment $segment): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'in:draft,active,paused,archived'],
            'channel_scope' => ['nullable', 'in:sms,email,any'],
            'rule_logic' => ['nullable', 'in:and,or'],
            'conditions' => ['nullable', 'array'],
            'conditions.*.field' => ['nullable', 'string', 'max:100'],
            'conditions.*.operator' => ['nullable', 'string', 'max:50'],
            'conditions.*.value' => ['nullable'],
        ]);

        $segment->fill([
            'name' => trim((string) $data['name']),
            'slug' => Str::slug((string) $data['name']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'status' => (string) $data['status'],
            'channel_scope' => (string) ($data['channel_scope'] ?? 'any'),
            'rules_json' => $this->rulesFromRequest($request),
            'updated_by' => auth()->id(),
        ])->save();

        return redirect()
            ->route('marketing.segments.preview', $segment)
            ->with('toast', ['style' => 'success', 'message' => 'Segment updated.']);
    }

    public function preview(
        MarketingSegment $segment,
        Request $request,
        MarketingSegmentPreviewService $previewService
    ): View {
        $search = trim((string) $request->query('search', ''));
        $sampleSize = max(5, min(100, (int) $request->query('sample_size', 25)));
        $preview = $previewService->preview($segment, $sampleSize, $search);

        $segment->forceFill([
            'last_previewed_at' => now(),
            'updated_by' => auth()->id(),
        ])->save();

        return view('marketing/segments/preview', [
            'section' => MarketingSectionRegistry::section('segments'),
            'sections' => $this->navigationItems(),
            'segment' => $segment,
            'preview' => $preview,
            'search' => $search,
            'sampleSize' => $sampleSize,
        ]);
    }

    public function duplicate(MarketingSegment $segment): RedirectResponse
    {
        $clone = $segment->replicate(['slug', 'last_previewed_at']);
        $clone->name = $segment->name . ' (Copy)';
        $clone->slug = Str::slug($clone->name . '-' . Str::random(4));
        $clone->is_system = false;
        $clone->status = 'draft';
        $clone->created_by = auth()->id();
        $clone->updated_by = auth()->id();
        $clone->save();

        return redirect()
            ->route('marketing.segments.edit', $clone)
            ->with('toast', ['style' => 'success', 'message' => 'Segment duplicated.']);
    }

    public function archive(MarketingSegment $segment): RedirectResponse
    {
        $segment->forceFill([
            'status' => 'archived',
            'updated_by' => auth()->id(),
        ])->save();

        return redirect()
            ->route('marketing.segments')
            ->with('toast', ['style' => 'success', 'message' => 'Segment archived.']);
    }

    /**
     * @return array<string,mixed>
     */
    protected function rulesFromRequest(Request $request): array
    {
        $logic = strtolower(trim((string) $request->input('rule_logic', 'and')));
        if (!in_array($logic, ['and', 'or'], true)) {
            $logic = 'and';
        }

        $conditions = [];
        foreach ((array) $request->input('conditions', []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $field = trim((string) ($row['field'] ?? ''));
            $operator = strtolower(trim((string) ($row['operator'] ?? 'eq')));
            $valueRaw = $row['value'] ?? null;
            if ($field === '' || $operator === '') {
                continue;
            }
            $conditions[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $this->castRuleValue($valueRaw),
            ];
        }

        return [
            'logic' => $logic,
            'conditions' => $conditions,
            'groups' => [],
        ];
    }

    protected function castRuleValue(mixed $value): mixed
    {
        $string = trim((string) $value);
        if ($string === '') {
            return '';
        }
        if (is_numeric($string)) {
            return str_contains($string, '.') ? (float) $string : (int) $string;
        }
        $lower = strtolower($string);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }

        return $string;
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
