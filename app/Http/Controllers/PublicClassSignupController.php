<?php

namespace App\Http\Controllers;

use App\Models\ClassEnrollment;
use App\Models\ClassReminder;
use App\Models\ClassSchedulingSetting;
use App\Models\MarketingProfile;
use App\Models\ScheduledClass;
use App\Models\Tenant;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicClassSignupController extends Controller
{
    public function __construct(protected TenantModuleAccessResolver $moduleAccess) {}

    public function index(Tenant $tenant): View
    {
        $settings = $this->publicSettings($tenant);
        $classes = ScheduledClass::query()
            ->forTenantId((int) $tenant->id)
            ->where('status', 'published')
            ->where('registration_open', true)
            ->where('starts_at', '>=', now())
            ->withSum(['confirmedEnrollments as confirmed_enrollments_sum_seats'], 'seats')
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (ScheduledClass $class): bool => $class->seats_remaining > 0)
            ->values();

        return view('class-scheduling.public-index', compact('tenant', 'settings', 'classes'));
    }

    public function show(Tenant $tenant, string $class): View
    {
        $settings = $this->publicSettings($tenant);
        $scheduledClass = ScheduledClass::query()
            ->forTenantId((int) $tenant->id)
            ->where('slug', $class)
            ->where('status', 'published')
            ->withSum(['confirmedEnrollments as confirmed_enrollments_sum_seats'], 'seats')
            ->firstOrFail();
        abort_unless($scheduledClass->registration_open && $scheduledClass->starts_at->isFuture() && $scheduledClass->seats_remaining > 0, 404);

        return view('class-scheduling.public-show', compact('tenant', 'settings', 'scheduledClass'));
    }

    public function store(Request $request, Tenant $tenant, string $class): RedirectResponse
    {
        $settings = $this->publicSettings($tenant);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:80'],
            'seats' => ['required', 'integer', 'min:1', 'max:10'],
            'email_reminders_enabled' => ['nullable', 'boolean'],
            'sms_reminders_enabled' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $scheduledClass = DB::transaction(function () use ($request, $tenant, $class, $settings, $data): ScheduledClass {
            $scheduledClass = ScheduledClass::query()
                ->forTenantId((int) $tenant->id)
                ->where('slug', $class)
                ->where('status', 'published')
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($scheduledClass->registration_open && $scheduledClass->starts_at->isFuture(), 404);
            $scheduledClass->loadSum(['confirmedEnrollments as confirmed_enrollments_sum_seats'], 'seats');
            abort_if((int) $data['seats'] > $scheduledClass->seats_remaining, 422, 'That class no longer has enough open seats.');

            $normalizedEmail = strtolower(trim((string) $data['email']));
            $existingEnrollment = ClassEnrollment::query()
                ->forTenantId((int) $tenant->id)
                ->where('scheduled_class_id', $scheduledClass->id)
                ->where('normalized_email', $normalizedEmail)
                ->whereIn('status', ['confirmed', 'pending'])
                ->first();
            if ($existingEnrollment) {
                throw ValidationException::withMessages([
                    'email' => 'That email address is already registered for this class.',
                ]);
            }
            $normalizedPhone = preg_replace('/\D+/', '', (string) ($data['phone'] ?? '')) ?: null;
            $nameParts = preg_split('/\s+/', trim((string) $data['name']), 2) ?: [];
            $profile = MarketingProfile::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id, 'normalized_email' => $normalizedEmail],
                [
                    'first_name' => $nameParts[0] ?? (string) $data['name'],
                    'last_name' => $nameParts[1] ?? null,
                    'email' => (string) $data['email'],
                    'normalized_email' => $normalizedEmail,
                    'phone' => $data['phone'] ?? null,
                    'normalized_phone' => $normalizedPhone,
                    'source_channels' => ['class_signup'],
                ]
            );

            $enrollment = ClassEnrollment::query()->create([
                'tenant_id' => (int) $tenant->id,
                'scheduled_class_id' => (int) $scheduledClass->id,
                'marketing_profile_id' => (int) $profile->id,
                'name' => (string) $data['name'],
                'email' => (string) $data['email'],
                'normalized_email' => $normalizedEmail,
                'phone' => $data['phone'] ?? null,
                'normalized_phone' => $normalizedPhone,
                'seats' => (int) $data['seats'],
                'status' => 'confirmed',
                'email_reminders_enabled' => $request->boolean('email_reminders_enabled', true),
                'sms_reminders_enabled' => $request->boolean('sms_reminders_enabled'),
                'notes' => $data['notes'] ?? null,
                'source' => 'public_signup',
                'metadata' => ['public_form' => true],
            ]);

            $hours = (int) collect($scheduledClass->reminder_offsets ?: $settings->default_reminder_offsets ?: [24])->first();
            $scheduledFor = $scheduledClass->starts_at->copy()->subHours(max(1, $hours));
            if ($scheduledFor->isFuture()) {
                foreach (['email', 'sms'] as $channel) {
                    $enabled = $channel === 'email' ? $enrollment->email_reminders_enabled : $enrollment->sms_reminders_enabled;
                    if (! $enabled || ($channel === 'sms' && ! $normalizedPhone)) {
                        continue;
                    }
                    ClassReminder::query()->create([
                        'tenant_id' => (int) $tenant->id,
                        'class_enrollment_id' => (int) $enrollment->id,
                        'channel' => $channel,
                        'scheduled_for' => $scheduledFor,
                        'status' => 'scheduled',
                        'message' => 'Reminder: '.$scheduledClass->title.' begins '.$scheduledClass->starts_at->format('M j \a\t g:i A').'.',
                        'provider_metadata' => ['delivery_gate' => 'tenant_provider_and_consent_required'],
                    ]);
                }
            }

            return $scheduledClass;
        });

        return redirect()->route('public.classes.show', ['tenant' => $tenant->slug, 'class' => $scheduledClass->slug])
            ->with('status', 'You are signed up! We saved your class and reminder preferences.');
    }

    protected function publicSettings(Tenant $tenant): ClassSchedulingSetting
    {
        $module = (array) data_get($this->moduleAccess->resolveForTenant((int) $tenant->id, ['class_scheduling']), 'modules.class_scheduling', []);
        abort_unless(($module['enabled'] ?? false) === true, 404);
        $settings = ClassSchedulingSetting::query()->where('tenant_id', $tenant->id)->first();
        abort_unless($settings instanceof ClassSchedulingSetting && $settings->public_signup_enabled, 404);

        return $settings;
    }
}
