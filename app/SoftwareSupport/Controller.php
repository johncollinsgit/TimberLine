<?php

namespace App\SoftwareSupport;

use App\Models\TenantSupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class Controller extends \App\Http\Controllers\Controller
{
    public function __construct(private Access $access, private Service $service) {}

    public function index(Request $request)
    {
        $context = $this->access->context($request);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:180'], 'status' => ['nullable', Rule::in(Service::STATUSES)], 'priority' => ['nullable', Rule::in(Service::PRIORITIES)]]);
        $query = $this->access->tickets($context)->join('tenant_support_tickets as tickets', 'tickets.id', '=', 'software_support_records.ticket_id')
            ->join('tenants as support_clients', 'support_clients.id', '=', 'software_support_records.client_tenant_id')
            ->whereColumn('tickets.tenant_id', 'software_support_records.client_tenant_id')->where('tickets.source_type', 'software_service');
        foreach (['status', 'priority'] as $field) {
            if (! empty($filters[$field])) {
                $query->where('tickets.'.$field, $filters[$field]);
            }
        }
        if (! empty($filters['q'])) {
            $search = $filters['q'];
            $query->where(fn ($q) => $q->where('tickets.subject', 'like', '%'.addcslashes($search, '%_\\').'%')->orWhere('software_support_records.id', $search));
        }
        $tickets = $query->select('software_support_records.*', 'tickets.subject', 'tickets.status', 'tickets.priority', 'tickets.last_activity_at', 'support_clients.name as client_name')->orderByDesc('tickets.last_activity_at')->paginate(25)->withQueryString();

        return $this->view('index', compact('context', 'tickets', 'filters') + ['requestKey' => (string) Str::uuid()]);
    }

    public function store(Request $request)
    {
        $context = $this->access->context($request);
        abort_if($context['owner'], 403);
        $data = $request->validate($this->rules() + [
            'subject' => ['required', 'string', 'max:180'], 'department' => ['required', Rule::in(array_keys($context['departments']))],
            'category' => ['required', 'in:help,bug,billing,feature,account'], 'priority' => ['required', Rule::in(Service::PRIORITIES)],
        ]);
        unset($data['attachments']);
        $record = $this->service->create($context, $request->user(), $data, $request->file('attachments', []));

        return redirect()->route('software-support.show', ['workspace' => $context['tenant']->slug, 'reference' => $record->id], 303)->with('success', 'Your support ticket has been saved.');
    }

    public function show(Request $request, string $workspace, string $reference)
    {
        $context = $this->access->context($request);
        $record = $this->access->ticket($context, $reference);
        $ticket = $this->service->ticket($record);
        $clientName = \App\Models\Tenant::findOrFail($record->client_tenant_id)->name;
        $messages = TenantSupportTicketMessage::withoutGlobalScopes()->where('tenant_id', $record->client_tenant_id)->where('tenant_support_ticket_id', $record->ticket_id)->orderBy('id')->get();
        $attachments = $this->service->entries($record, 'attachment');
        $events = $this->service->entries($record, 'event');
        $notifications = $context['owner'] ? $this->service->entries($record, 'notification') : collect();

        return $this->view('show', compact('context', 'record', 'ticket', 'clientName', 'messages', 'attachments', 'events', 'notifications') + ['requestKey' => (string) Str::uuid()]);
    }

    public function reply(Request $request, string $workspace, string $reference)
    {
        $context = $this->access->context($request);
        $record = $this->access->ticket($context, $reference);
        $data = $request->validate($this->rules());
        unset($data['attachments']);
        $this->service->reply($record, $request->user(), $data, $context['owner'], $request->file('attachments', []));

        return redirect()->route('software-support.show', ['workspace' => $workspace, 'reference' => $reference], 303)->with('success', 'Your reply has been saved.');
    }

    public function triage(Request $request, string $workspace, string $reference)
    {
        $context = $this->access->context($request);
        abort_unless($context['owner'], 403);
        $record = $this->access->ticket($context, $reference);
        $data = $request->validate(['status' => ['required', Rule::in(Service::STATUSES)], 'priority' => ['required', Rule::in(Service::PRIORITIES)], 'revision' => ['required', 'integer', 'min:1']]);
        $this->service->triage($record, $request->user(), $data);

        return redirect()->route('software-support.show', ['workspace' => $workspace, 'reference' => $reference], 303)->with('success', 'Ticket updated.');
    }

    public function download(Request $request, string $workspace, string $reference, string $file)
    {
        $context = $this->access->context($request);
        $record = $this->access->ticket($context, $reference);
        $attachment = $this->service->entries($record, 'attachment')->firstWhere('id', $file);
        abort_unless($attachment, 404);
        $bytes = app(AttachmentGate::class)->download($attachment);

        return response()->streamDownload(fn () => print ($bytes), $attachment->payload['name'], ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'body' => ['required', 'string', 'max:8000'],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['bail', 'file', 'max:5120', 'mimetypes:image/png,image/jpeg,application/pdf,text/plain', function ($attribute, $value, $fail): void {
                $name = $value->getClientOriginalName();
                if (strlen($name) > 180 || preg_match('/[\x00-\x1F\x7F]/', $name) || ! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'pdf', 'txt'], true)) {
                    $fail('Choose a PNG, JPG, PDF or text file with a simple filename.');
                }
            }],
        ];
    }

    private function view(string $view, array $data)
    {
        return response()->view('software-support.'.$view, $data)->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
