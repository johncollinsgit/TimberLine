<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\ClassEnrollment;
use App\Models\ClassReminder;
use App\Models\ScheduledClass;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Mobile\TenantMobileModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class EverbranchMobileClassSchedulingController extends Controller
{
    public function __construct(protected TenantMobileModuleRegistry $modules) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = Carbon::createFromFormat('!Y-m', (string) ($validated['month'] ?? now()->format('Y-m')));
        $classes = ScheduledClass::query()->forTenantId((int) $tenant->id)
            ->whereBetween('starts_at', [$month->copy()->startOfMonth()->startOfWeek(), $month->copy()->endOfMonth()->endOfWeek()])
            ->whereNotIn('status', ['cancelled'])
            ->withSum(['confirmedEnrollments as confirmed_enrollments_sum_seats'], 'seats')
            ->orderBy('starts_at')->get();

        return response()->json([
            'contract_version' => 2,
            'month' => $month->format('Y-m'),
            'label' => $month->format('F Y'),
            'can_manage' => $this->canManage($request->user(), $tenant),
            'days' => $classes->groupBy(fn (ScheduledClass $class): string => $class->starts_at->toDateString())
                ->map(fn ($dayClasses) => $dayClasses->map(fn (ScheduledClass $class): array => $this->summary($class))->values())
                ->all(),
            'classes' => $classes->map(fn (ScheduledClass $class): array => $this->summary($class))->values(),
        ]);
    }

    public function show(Request $request, string $tenant, ScheduledClass $scheduledClass): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        abort_unless((int) $scheduledClass->tenant_id === (int) $tenantModel->id, 404);
        $scheduledClass->load([
            'enrollments' => fn ($query) => $query->with(['customer', 'reminders'])->orderBy('name'),
        ])->loadSum(['confirmedEnrollments as confirmed_enrollments_sum_seats'], 'seats');

        return response()->json(['class' => [
            ...$this->summary($scheduledClass),
            'description' => $scheduledClass->description,
            'image_url' => $scheduledClass->image_url,
            'price' => $scheduledClass->price === null ? null : (float) $scheduledClass->price,
            'can_manage' => $this->canManage($request->user(), $tenantModel),
            'attendees' => $scheduledClass->enrollments->map(fn (ClassEnrollment $enrollment): array => [
                'id' => (int) $enrollment->id,
                'name' => (string) $enrollment->name,
                'email' => $enrollment->email,
                'phone' => $enrollment->phone,
                'seats' => (int) $enrollment->seats,
                'status' => (string) $enrollment->status,
                'customer_id' => $enrollment->marketing_profile_id ? (int) $enrollment->marketing_profile_id : null,
                'destination' => $enrollment->marketing_profile_id ? ['kind' => 'customer', 'id' => (int) $enrollment->marketing_profile_id] : null,
                'message_destination' => $enrollment->marketing_profile_id ? ['kind' => 'message_customer', 'id' => (int) $enrollment->marketing_profile_id] : null,
                'reminders' => $enrollment->reminders->map(fn (ClassReminder $reminder): array => [
                    'id' => (int) $reminder->id,
                    'channel' => (string) $reminder->channel,
                    'scheduled_for' => $reminder->scheduled_for->toIso8601String(),
                    'status' => (string) $reminder->status,
                ])->values(),
            ])->values(),
        ]]);
    }

    public function storeReminder(Request $request, string $tenant, ClassEnrollment $enrollment): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        abort_unless($this->canManage($request->user(), $tenantModel), 403);
        abort_unless((int) $enrollment->tenant_id === (int) $tenantModel->id, 404);
        $enrollment->loadMissing('scheduledClass');
        $validated = $request->validate([
            'channel' => ['required', Rule::in(['email', 'sms'])],
            'scheduled_for' => ['required', 'date', 'after:now'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);
        $scheduledFor = Carbon::parse((string) $validated['scheduled_for']);
        abort_if($scheduledFor->greaterThanOrEqualTo($enrollment->scheduledClass->starts_at), 422, 'Reminders must be scheduled before the class begins.');
        abort_if($validated['channel'] === 'sms' && (! $enrollment->sms_reminders_enabled || blank($enrollment->normalized_phone)), 422, 'This customer has not enabled text reminders for this class.');
        abort_if($validated['channel'] === 'email' && (! $enrollment->email_reminders_enabled || blank($enrollment->email)), 422, 'This customer has not enabled email reminders for this class.');

        $reminder = ClassReminder::query()->create([
            'tenant_id' => (int) $tenantModel->id,
            'class_enrollment_id' => (int) $enrollment->id,
            'created_by_user_id' => (int) $request->user()->id,
            'channel' => (string) $validated['channel'],
            'scheduled_for' => $scheduledFor,
            'status' => 'scheduled',
            'message' => $validated['message'] ?? 'Reminder: '.$enrollment->scheduledClass->title.' begins '.$enrollment->scheduledClass->starts_at->format('M j, Y \a\t g:i A').'.',
            'provider_metadata' => ['delivery_gate' => 'tenant_provider_and_consent_required', 'mobile' => true],
        ]);

        return response()->json(['ok' => true, 'reminder' => [
            'id' => (int) $reminder->id,
            'channel' => (string) $reminder->channel,
            'scheduled_for' => $reminder->scheduled_for->toIso8601String(),
            'status' => (string) $reminder->status,
        ]], 201);
    }

    /** @return array<string,mixed> */
    protected function summary(ScheduledClass $class): array
    {
        return [
            'id' => (int) $class->id,
            'title' => (string) $class->title,
            'category' => (string) ($class->category ?: 'Class'),
            'location' => (string) ($class->location ?: 'Location pending'),
            'starts_at' => $class->starts_at->toIso8601String(),
            'ends_at' => $class->ends_at?->toIso8601String(),
            'status' => (string) $class->status,
            'registration_open' => (bool) $class->registration_open,
            'capacity' => (int) $class->capacity,
            'seats_taken' => $class->seats_taken,
            'seats_remaining' => $class->seats_remaining,
            'destination' => ['kind' => 'scheduled_class', 'id' => (int) $class->id],
        ];
    }

    protected function canManage(?User $user, Tenant $tenant): bool
    {
        if (! $user) {
            return false;
        }
        $membership = $tenant->users()->whereKey((int) $user->id)->first();

        return in_array((string) $membership?->pivot?->role, ['admin', 'manager', 'marketing_manager'], true);
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        abort_unless(collect($this->modules->manifest((int) $tenant->id))->contains('module_key', 'class_scheduling'), 404);

        return $tenant;
    }
}
