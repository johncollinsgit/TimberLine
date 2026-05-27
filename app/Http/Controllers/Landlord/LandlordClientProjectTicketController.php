<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\ClientProjectTicket;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LandlordClientProjectTicketController extends Controller
{
    public function index(Request $request): View
    {
        $filter = strtolower(trim((string) $request->query('filter', 'all')));
        $filterOptions = $this->filterOptions();
        $activeFilter = array_key_exists($filter, $filterOptions) ? $filter : 'all';

        $query = ClientProjectTicket::query()
            ->with(['tenant', 'project', 'phase', 'requester', 'reviewer', 'tasks', 'references'])
            ->latest('id');

        if (in_array($activeFilter, ClientProjectTicket::STATUSES, true)) {
            $query->where('status', $activeFilter);
        }

        return view('landlord.client-project-tickets.index', [
            'tickets' => $query->get(),
            'filterOptions' => $filterOptions,
            'activeFilter' => $activeFilter,
            'statusLabels' => $this->statusLabels(),
            'priorityLabels' => $this->priorityLabels(),
        ]);
    }

    public function update(Request $request, ClientProjectTicket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(ClientProjectTicket::STATUSES)],
            'priority' => ['required', 'string', Rule::in(array_keys($this->priorityLabels()))],
            'scope_notes' => ['nullable', 'string', 'max:5000'],
            'landlord_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        /** @var User|null $user */
        $user = $request->user();
        $ticket->fill([
            'status' => (string) $validated['status'],
            'priority' => (string) $validated['priority'],
            'scope_notes' => $this->nullableText($validated['scope_notes'] ?? null, 5000),
            'landlord_notes' => $this->nullableText($validated['landlord_notes'] ?? null, 5000),
            'reviewed_by_user_id' => $user?->id,
            'reviewed_at' => now(),
        ])->save();

        return redirect()
            ->route('landlord.client-project-tickets.index', ['filter' => (string) $validated['status']])
            ->with('status', 'Client project request updated.');
    }

    /**
     * @return array<string,string>
     */
    protected function filterOptions(): array
    {
        return ['all' => 'All'] + $this->statusLabels();
    }

    /**
     * @return array<string,string>
     */
    protected function statusLabels(): array
    {
        return collect(ClientProjectTicket::STATUSES)
            ->mapWithKeys(static fn (string $status): array => [$status => Str::headline(str_replace('_', ' ', $status))])
            ->all();
    }

    /**
     * @return array<string,string>
     */
    protected function priorityLabels(): array
    {
        return [
            'low' => 'Low',
            'normal' => 'Normal',
            'high' => 'High',
        ];
    }

    protected function nullableText(mixed $value, int $limit): ?string
    {
        $text = Str::limit(trim((string) $value), $limit, '');

        return $text !== '' ? $text : null;
    }
}
