<?php

namespace App\Http\Controllers;

use App\Models\ClassEnrollment;
use App\Models\ClassReminder;
use App\Models\ClassSchedulingSetting;
use App\Models\ScheduledClass;
use App\Models\Tenant;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClassSchedulingController extends Controller
{
    public function __construct(
        protected AuthenticatedTenantContextResolver $tenantContextResolver,
    ) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenant($request);
        $month = $this->month($request->query('month'));
        $classes = ScheduledClass::query()
            ->forTenantId((int) $tenant->id)
            ->whereBetween('starts_at', [$month->copy()->startOfMonth()->startOfWeek(), $month->copy()->endOfMonth()->endOfWeek()])
            ->withSum(['confirmedEnrollments as confirmed_enrollments_sum_seats'], 'seats')
            ->orderBy('starts_at')
            ->get();

        return view('class-scheduling.index', [
            'tenant' => $tenant,
            'settings' => ClassSchedulingSetting::query()->firstOrCreate(
                ['tenant_id' => (int) $tenant->id],
                ['timezone' => 'America/New_York', 'default_reminder_offsets' => [24]]
            ),
            'classes' => $classes,
            'month' => $month,
            'previousMonth' => $month->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $month->copy()->addMonth()->format('Y-m'),
            'publicUrl' => route('public.classes.index', ['tenant' => $tenant->slug]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $data = $this->validatedClass($request, $tenant);
        $slug = $this->uniqueSlug($tenant, (string) $data['title']);

        $class = ScheduledClass::query()->create($this->classPayload($data, $tenant, $slug));

        return redirect()->route('class-scheduling.show', $class)->with(
            'status',
            $this->isFrontYardFoodsTenant($tenant) ? 'Event or class added to the calendar.' : 'Class added to the calendar.'
        );
    }

    public function update(Request $request, ScheduledClass $scheduledClass): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->assertOwned($tenant, $scheduledClass);
        $data = $this->validatedClass($request, $tenant);
        $scheduledClass->update($this->classPayload($data, $tenant, (string) $scheduledClass->slug));

        return back()->with(
            'status',
            $this->isFrontYardFoodsTenant($tenant) ? 'Event/class settings updated.' : 'Class settings updated.'
        );
    }

    public function show(Request $request, ScheduledClass $scheduledClass): View
    {
        $tenant = $this->tenant($request);
        $this->assertOwned($tenant, $scheduledClass);
        $scheduledClass->load([
            'enrollments' => fn ($query) => $query->with(['customer', 'reminders'])->orderBy('name'),
        ])->loadSum(['confirmedEnrollments as confirmed_enrollments_sum_seats'], 'seats');

        return view('class-scheduling.show', [
            'tenant' => $tenant,
            'scheduledClass' => $scheduledClass,
            'settings' => ClassSchedulingSetting::query()->where('tenant_id', $tenant->id)->first(),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $data = $request->validate([
            'public_signup_enabled' => ['nullable', 'boolean'],
            'timezone' => ['required', 'timezone'],
            'public_heading' => ['required', 'string', 'max:255'],
            'public_intro' => ['nullable', 'string', 'max:2000'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'logo_url' => ['nullable', 'url:http,https', 'max:2048'],
            'hero_image_url' => ['nullable', 'url:http,https', 'max:2048'],
            'brand_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'default_reminder_hours' => ['required', 'integer', 'min:1', 'max:720'],
        ]);

        $reminderHours = (int) $data['default_reminder_hours'];
        unset($data['default_reminder_hours']);
        ClassSchedulingSetting::query()->updateOrCreate(
            ['tenant_id' => (int) $tenant->id],
            [
                ...$data,
                'public_signup_enabled' => $request->boolean('public_signup_enabled'),
                'default_reminder_offsets' => [$reminderHours],
            ]
        );

        return back()->with(
            'status',
            $this->isFrontYardFoodsTenant($tenant) ? 'Event/class signup and publishing settings updated.' : 'Class signup settings updated.'
        );
    }

    public function storeReminder(Request $request, ClassEnrollment $enrollment): RedirectResponse
    {
        $tenant = $this->tenant($request);
        abort_unless((int) $enrollment->tenant_id === (int) $tenant->id, 404);
        $enrollment->loadMissing('scheduledClass');

        $data = $request->validate([
            'channel' => ['required', Rule::in(['email', 'sms'])],
            'scheduled_for' => ['required', 'date', 'after:now'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);
        $scheduledFor = Carbon::parse((string) $data['scheduled_for']);
        abort_if($scheduledFor->greaterThanOrEqualTo($enrollment->scheduledClass->starts_at), 422, 'Reminders must be scheduled before the class begins.');
        abort_if($data['channel'] === 'sms' && (! $enrollment->sms_reminders_enabled || blank($enrollment->normalized_phone)), 422, 'This customer has not enabled text reminders for this class.');
        abort_if($data['channel'] === 'email' && (! $enrollment->email_reminders_enabled || blank($enrollment->email)), 422, 'This customer has not enabled email reminders for this class.');

        ClassReminder::query()->create([
            'tenant_id' => (int) $tenant->id,
            'class_enrollment_id' => (int) $enrollment->id,
            'created_by_user_id' => (int) $request->user()->id,
            'channel' => (string) $data['channel'],
            'scheduled_for' => $scheduledFor,
            'status' => 'scheduled',
            'message' => $data['message'] ?? $this->defaultReminderMessage($enrollment),
            'provider_metadata' => ['delivery_gate' => 'tenant_provider_and_consent_required'],
        ]);

        return back()->with('status', ucfirst((string) $data['channel']).' reminder scheduled.');
    }

    protected function tenant(Request $request): Tenant
    {
        $attributeTenant = $request->attributes->get('current_tenant');
        $tenant = $attributeTenant instanceof Tenant
            ? $attributeTenant
            : $this->tenantContextResolver->resolveForRequest($request, $request->user());

        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    protected function assertOwned(Tenant $tenant, ScheduledClass $scheduledClass): void
    {
        abort_unless((int) $scheduledClass->tenant_id === (int) $tenant->id, 404);
    }

    /** @return array<string,mixed> */
    protected function validatedClass(Request $request, Tenant $tenant): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'status' => ['required', Rule::in(['draft', 'published', 'cancelled', 'complete'])],
            'registration_open' => ['nullable', 'boolean'],
            'image_url' => ['nullable', 'url:http,https', 'max:2048'],
            'reminder_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    protected function classPayload(array $data, Tenant $tenant, string $slug): array
    {
        return [
            'tenant_id' => (int) $tenant->id,
            'title' => (string) $data['title'],
            'slug' => $slug,
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'] ?? null,
            'capacity' => (int) $data['capacity'],
            'price' => $data['price'] ?? null,
            'status' => (string) $data['status'],
            'registration_open' => (bool) ($data['registration_open'] ?? false),
            'image_url' => $data['image_url'] ?? null,
            'reminder_offsets' => [(int) ($data['reminder_hours'] ?? 24)],
        ];
    }

    protected function uniqueSlug(Tenant $tenant, string $title): string
    {
        $base = Str::slug($title) ?: 'class';
        $slug = $base;
        $suffix = 2;
        while (ScheduledClass::query()->forTenantId((int) $tenant->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    protected function isFrontYardFoodsTenant(Tenant $tenant): bool
    {
        return (string) $tenant->slug === 'front-yard-foods';
    }

    protected function month(mixed $value): Carbon
    {
        try {
            return Carbon::createFromFormat('!Y-m', trim((string) $value) ?: now()->format('Y-m'));
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }

    protected function defaultReminderMessage(ClassEnrollment $enrollment): string
    {
        return 'Reminder: '.$enrollment->scheduledClass->title.' begins '.$enrollment->scheduledClass->starts_at->format('M j, Y \a\t g:i A').'.';
    }
}
