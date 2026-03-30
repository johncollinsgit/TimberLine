<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\LandlordOperatorAction;
use App\Models\Tenant;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Services\Tenancy\LandlordTenantOperationsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class LandlordTenantOperationsController extends Controller
{
    public const ACTION_EXPORT = 'tenant_ops.export_snapshot';
    public const ACTION_EXPORT_DOWNLOAD = 'tenant_ops.export_snapshot_download';
    public const ACTION_RESTORE = 'tenant_ops.restore_snapshot';
    public const ACTION_CUSTOMER_MODIFY = 'tenant_ops.customer_modify';
    public const ACTION_CUSTOMER_ARCHIVE = 'tenant_ops.customer_archive_delete';

    public function selectTenant(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tenant' => ['required', 'string', 'max:120'],
        ]);

        $token = strtolower(trim((string) $validated['tenant']));
        $tenant = Tenant::query()
            ->when(
                is_numeric($token),
                fn ($query) => $query->where('id', (int) $token),
                fn ($query) => $query->where('slug', $token)
            )
            ->first();

        if (! $tenant) {
            return back()
                ->withErrors(['tenant' => 'Select a valid tenant before running landlord operations.'])
                ->withInput();
        }

        return redirect()->route('landlord.tenants.show', ['tenant' => $tenant->id]);
    }

    public function export(
        Request $request,
        Tenant $tenant,
        LandlordTenantOperationsService $operationsService,
        LandlordOperatorActionAuditService $auditService
    ): RedirectResponse {
        $validated = $request->validate([
            'tenant_id' => ['required', 'integer'],
            'tenant_slug' => ['required', 'string', 'max:120'],
            'confirm_phrase' => ['required', 'string', 'max:160'],
            'confirm_export' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        $this->assertTenantContextConfirmed(
            request: $request,
            tenant: $tenant,
            actionType: self::ACTION_EXPORT,
            tenantIdInput: (int) $validated['tenant_id'],
            tenantSlugInput: (string) $validated['tenant_slug'],
            phraseInput: (string) $validated['confirm_phrase'],
            operationsService: $operationsService,
            auditService: $auditService
        );

        try {
            $result = $operationsService->exportTenantSnapshot($tenant, $request->user());
        } catch (Throwable $e) {
            $auditService->record(
                tenantId: (int) $tenant->id,
                actorUserId: $request->user()?->id,
                actionType: self::ACTION_EXPORT,
                status: 'failed',
                targetType: 'tenant_snapshot',
                context: $this->requestContext($request),
                confirmation: [
                    'required_phrase' => $operationsService->confirmationPhraseForTenant($tenant),
                    'tenant_id_confirmed' => true,
                    'tenant_slug_confirmed' => true,
                    'phrase_confirmed' => true,
                    'reason' => (string) $validated['reason'],
                ],
                result: [
                    'error' => $e->getMessage(),
                ]
            );

            return back()
                ->withErrors(['tenant_operations_export' => 'Export failed: ' . $e->getMessage()])
                ->withInput();
        }

        $auditService->record(
            tenantId: (int) $tenant->id,
            actorUserId: $request->user()?->id,
            actionType: self::ACTION_EXPORT,
            status: 'success',
            targetType: 'tenant_snapshot',
            targetId: (string) ($result['artifact_id'] ?? ''),
            context: $this->requestContext($request),
            confirmation: [
                'required_phrase' => $operationsService->confirmationPhraseForTenant($tenant),
                'tenant_id_confirmed' => true,
                'tenant_slug_confirmed' => true,
                'phrase_confirmed' => true,
                'reason' => (string) $validated['reason'],
            ],
            result: $result
        );

        $exportFile = (string) ($result['artifact_file_name'] ?? 'artifact.json');
        $expiresAt = trim((string) ($result['expires_at'] ?? ''));
        $statusMessage = 'Tenant snapshot export created: ' . $exportFile;
        if ($expiresAt !== '') {
            $statusMessage .= ' (expires ' . $expiresAt . ')';
        }

        return back()->with('status', $statusMessage);
    }

    public function restore(
        Request $request,
        Tenant $tenant,
        LandlordTenantOperationsService $operationsService,
        LandlordOperatorActionAuditService $auditService
    ): RedirectResponse {
        $validated = $request->validate([
            'tenant_id' => ['required', 'integer'],
            'tenant_slug' => ['required', 'string', 'max:120'],
            'confirm_phrase' => ['required', 'string', 'max:160'],
            'confirm_restore' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
            'dry_run' => ['nullable', 'boolean'],
            'allow_overwrite' => ['nullable', 'boolean'],
            'confirm_overwrite' => ['nullable', 'boolean'],
            'overwrite_phrase' => ['nullable', 'string', 'max:160'],
            'apply_phrase' => ['nullable', 'string', 'max:160'],
            'snapshot_file' => ['required', 'file', 'mimetypes:application/json,text/plain,text/json'],
        ]);
        $this->assertTenantContextConfirmed(
            request: $request,
            tenant: $tenant,
            actionType: self::ACTION_RESTORE,
            tenantIdInput: (int) $validated['tenant_id'],
            tenantSlugInput: (string) $validated['tenant_slug'],
            phraseInput: (string) $validated['confirm_phrase'],
            operationsService: $operationsService,
            auditService: $auditService
        );

        $dryRun = (bool) ($validated['dry_run'] ?? false);
        $allowOverwrite = (bool) ($validated['allow_overwrite'] ?? false);
        $applyPhraseConfirmed = $dryRun;
        $overwritePhraseConfirmed = ! $allowOverwrite;

        if ($allowOverwrite && ! (bool) ($validated['confirm_overwrite'] ?? false)) {
            throw ValidationException::withMessages([
                'confirm_overwrite' => 'Overwrite confirmation is required when overwrite mode is enabled.',
            ]);
        }
        if ($allowOverwrite) {
            $expectedOverwritePhrase = $operationsService->overwritePhraseForTenant($tenant);
            $overwritePhraseInput = strtolower(trim((string) ($validated['overwrite_phrase'] ?? '')));
            $overwritePhraseConfirmed = $overwritePhraseInput === $expectedOverwritePhrase;
            if ($overwritePhraseInput !== $expectedOverwritePhrase) {
                throw ValidationException::withMessages([
                    'overwrite_phrase' => 'Overwrite confirmation phrase is invalid for the selected tenant.',
                ]);
            }
        }
        if (! $dryRun) {
            $expectedApplyPhrase = $operationsService->applyRestorePhraseForTenant($tenant);
            $applyPhraseInput = strtolower(trim((string) ($validated['apply_phrase'] ?? '')));
            $applyPhraseConfirmed = $applyPhraseInput === $expectedApplyPhrase;
            if ($applyPhraseInput !== $expectedApplyPhrase) {
                throw ValidationException::withMessages([
                    'apply_phrase' => 'Apply confirmation phrase is required for restore apply mode.',
                ]);
            }
        }

        try {
            $result = $operationsService->restoreTenantSnapshot(
                tenant: $tenant,
                artifact: $validated['snapshot_file'],
                allowOverwrite: $allowOverwrite,
                dryRun: $dryRun
            );
        } catch (Throwable $e) {
            $auditService->record(
                tenantId: (int) $tenant->id,
                actorUserId: $request->user()?->id,
                actionType: self::ACTION_RESTORE,
                status: 'blocked',
                targetType: 'tenant_snapshot',
                targetId: (string) ($validated['snapshot_file']->getClientOriginalName() ?? ''),
                context: $this->requestContext($request),
                confirmation: [
                    'required_phrase' => $operationsService->confirmationPhraseForTenant($tenant),
                    'tenant_id_confirmed' => true,
                    'tenant_slug_confirmed' => true,
                    'phrase_confirmed' => true,
                    'reason' => (string) $validated['reason'],
                    'dry_run' => $dryRun,
                    'apply_phrase_confirmed' => $applyPhraseConfirmed,
                    'allow_overwrite' => $allowOverwrite,
                    'overwrite_phrase_confirmed' => $overwritePhraseConfirmed,
                ],
                result: [
                    'error' => $e->getMessage(),
                ]
            );

            return back()
                ->withErrors(['tenant_operations_restore' => 'Restore blocked: ' . $e->getMessage()])
                ->withInput();
        }

        $auditService->record(
            tenantId: (int) $tenant->id,
            actorUserId: $request->user()?->id,
            actionType: self::ACTION_RESTORE,
            status: 'success',
            targetType: 'tenant_snapshot',
            targetId: (string) ($validated['snapshot_file']->getClientOriginalName() ?? ''),
            context: $this->requestContext($request),
            confirmation: [
                'required_phrase' => $operationsService->confirmationPhraseForTenant($tenant),
                'tenant_id_confirmed' => true,
                'tenant_slug_confirmed' => true,
                'phrase_confirmed' => true,
                'reason' => (string) $validated['reason'],
                'dry_run' => $dryRun,
                'apply_phrase_confirmed' => $applyPhraseConfirmed,
                'allow_overwrite' => $allowOverwrite,
                'overwrite_phrase_confirmed' => $overwritePhraseConfirmed,
            ],
            result: $result
        );

        if ($dryRun) {
            return back()->with('status', 'Tenant snapshot restore dry-run completed. No rows were mutated.');
        }

        return back()->with('status', 'Tenant snapshot restore apply completed.');
    }

    public function modifyCustomer(
        Request $request,
        Tenant $tenant,
        LandlordTenantOperationsService $operationsService,
        LandlordOperatorActionAuditService $auditService
    ): RedirectResponse {
        $validated = $request->validate([
            'tenant_id' => ['required', 'integer'],
            'tenant_slug' => ['required', 'string', 'max:120'],
            'confirm_phrase' => ['required', 'string', 'max:160'],
            'confirm_modify' => ['required', 'accepted'],
            'profile_id' => ['required', 'integer'],
            'confirm_profile_id' => ['required', 'string', 'max:40'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'accepts_email_marketing' => ['nullable', 'boolean'],
            'accepts_sms_marketing' => ['nullable', 'boolean'],
        ]);
        $this->assertTenantContextConfirmed(
            request: $request,
            tenant: $tenant,
            actionType: self::ACTION_CUSTOMER_MODIFY,
            tenantIdInput: (int) $validated['tenant_id'],
            tenantSlugInput: (string) $validated['tenant_slug'],
            phraseInput: (string) $validated['confirm_phrase'],
            operationsService: $operationsService,
            auditService: $auditService
        );
        $this->assertProfileConfirmation(
            profileId: (int) $validated['profile_id'],
            confirmationInput: (string) $validated['confirm_profile_id'],
            fieldName: 'confirm_profile_id'
        );

        $editablePayload = Arr::only($validated, [
            'first_name',
            'last_name',
            'email',
            'phone',
            'notes',
            'accepts_email_marketing',
            'accepts_sms_marketing',
        ]);
        if ($editablePayload === []) {
            throw ValidationException::withMessages([
                'profile_id' => 'At least one editable customer field is required for a modify action.',
            ]);
        }

        try {
            $result = $operationsService->modifyTenantCustomer(
                tenant: $tenant,
                profileId: (int) $validated['profile_id'],
                changes: $editablePayload
            );
        } catch (Throwable $e) {
            $auditService->record(
                tenantId: (int) $tenant->id,
                actorUserId: $request->user()?->id,
                actionType: self::ACTION_CUSTOMER_MODIFY,
                status: 'blocked',
                targetType: 'marketing_profile',
                targetId: (string) $validated['profile_id'],
                context: $this->requestContext($request),
                confirmation: [
                    'required_phrase' => $operationsService->confirmationPhraseForTenant($tenant),
                    'tenant_id_confirmed' => true,
                    'tenant_slug_confirmed' => true,
                    'phrase_confirmed' => true,
                    'reason' => (string) $validated['reason'],
                    'profile_id_confirmed' => true,
                ],
                result: [
                    'error' => $e->getMessage(),
                ]
            );

            return back()
                ->withErrors(['tenant_operations_customer_modify' => 'Customer modify blocked: ' . $e->getMessage()])
                ->withInput();
        }

        $auditService->record(
            tenantId: (int) $tenant->id,
            actorUserId: $request->user()?->id,
            actionType: self::ACTION_CUSTOMER_MODIFY,
            status: 'success',
            targetType: 'marketing_profile',
            targetId: (string) $validated['profile_id'],
            context: $this->requestContext($request),
            confirmation: [
                'required_phrase' => $operationsService->confirmationPhraseForTenant($tenant),
                'tenant_id_confirmed' => true,
                'tenant_slug_confirmed' => true,
                'phrase_confirmed' => true,
                'reason' => (string) $validated['reason'],
                'profile_id_confirmed' => true,
            ],
            beforeState: (array) ($result['before'] ?? []),
            afterState: (array) ($result['after'] ?? []),
            result: $result
        );

        return back()->with('status', 'Tenant customer profile updated.');
    }

    public function archiveCustomer(
        Request $request,
        Tenant $tenant,
        LandlordTenantOperationsService $operationsService,
        LandlordOperatorActionAuditService $auditService
    ): RedirectResponse {
        $validated = $request->validate([
            'tenant_id' => ['required', 'integer'],
            'tenant_slug' => ['required', 'string', 'max:120'],
            'confirm_phrase' => ['required', 'string', 'max:160'],
            'confirm_delete' => ['required', 'accepted'],
            'profile_id' => ['required', 'integer'],
            'confirm_profile_id' => ['required', 'string', 'max:40'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        $this->assertTenantContextConfirmed(
            request: $request,
            tenant: $tenant,
            actionType: self::ACTION_CUSTOMER_ARCHIVE,
            tenantIdInput: (int) $validated['tenant_id'],
            tenantSlugInput: (string) $validated['tenant_slug'],
            phraseInput: (string) $validated['confirm_phrase'],
            operationsService: $operationsService,
            auditService: $auditService
        );
        $this->assertProfileConfirmation(
            profileId: (int) $validated['profile_id'],
            confirmationInput: (string) $validated['confirm_profile_id'],
            fieldName: 'confirm_profile_id'
        );

        try {
            $result = $operationsService->archiveTenantCustomerForDelete(
                tenant: $tenant,
                profileId: (int) $validated['profile_id'],
                reason: (string) $validated['reason'],
                actorUserId: $request->user()?->id
            );
        } catch (Throwable $e) {
            $auditService->record(
                tenantId: (int) $tenant->id,
                actorUserId: $request->user()?->id,
                actionType: self::ACTION_CUSTOMER_ARCHIVE,
                status: 'blocked',
                targetType: 'marketing_profile',
                targetId: (string) $validated['profile_id'],
                context: $this->requestContext($request),
                confirmation: [
                    'required_phrase' => $operationsService->confirmationPhraseForTenant($tenant),
                    'tenant_id_confirmed' => true,
                    'tenant_slug_confirmed' => true,
                    'phrase_confirmed' => true,
                    'reason' => (string) $validated['reason'],
                    'profile_id_confirmed' => true,
                ],
                result: [
                    'error' => $e->getMessage(),
                ]
            );

            return back()
                ->withErrors(['tenant_operations_customer_archive' => 'Customer delete/archive blocked: ' . $e->getMessage()])
                ->withInput();
        }

        $auditService->record(
            tenantId: (int) $tenant->id,
            actorUserId: $request->user()?->id,
            actionType: self::ACTION_CUSTOMER_ARCHIVE,
            status: 'success',
            targetType: 'marketing_profile',
            targetId: (string) $validated['profile_id'],
            context: $this->requestContext($request),
            confirmation: [
                'required_phrase' => $operationsService->confirmationPhraseForTenant($tenant),
                'tenant_id_confirmed' => true,
                'tenant_slug_confirmed' => true,
                'phrase_confirmed' => true,
                'reason' => (string) $validated['reason'],
                'profile_id_confirmed' => true,
            ],
            beforeState: (array) ($result['before'] ?? []),
            afterState: (array) ($result['after'] ?? []),
            result: $result
        );

        return back()->with('status', 'Tenant customer archived using safe-delete workflow.');
    }

    public function downloadExport(
        Request $request,
        Tenant $tenant,
        LandlordOperatorAction $action,
        LandlordTenantOperationsService $operationsService,
        LandlordOperatorActionAuditService $auditService
    ): StreamedResponse {
        if ((int) ($action->tenant_id ?? 0) !== (int) $tenant->id) {
            $auditService->record(
                tenantId: (int) $tenant->id,
                actorUserId: $request->user()?->id,
                actionType: self::ACTION_EXPORT_DOWNLOAD,
                status: 'blocked',
                targetType: 'tenant_snapshot',
                targetId: (string) $action->id,
                context: $this->requestContext($request),
                result: [
                    'error' => 'tenant_mismatch',
                ]
            );
            abort(404);
        }

        if ((string) $action->action_type !== self::ACTION_EXPORT) {
            $auditService->record(
                tenantId: (int) $tenant->id,
                actorUserId: $request->user()?->id,
                actionType: self::ACTION_EXPORT_DOWNLOAD,
                status: 'blocked',
                targetType: (string) ($action->target_type ?? 'tenant_snapshot'),
                targetId: (string) $action->id,
                context: $this->requestContext($request),
                result: [
                    'error' => 'action_type_not_export',
                ]
            );
            abort(404);
        }

        $artifactPath = trim((string) data_get((array) ($action->result ?? []), 'artifact_path', ''));
        $expectedPrefix = sprintf('landlord/tenant-ops/tenant-%d/', (int) $tenant->id);
        if (! str_starts_with($artifactPath, $expectedPrefix)) {
            $auditService->record(
                tenantId: (int) $tenant->id,
                actorUserId: $request->user()?->id,
                actionType: self::ACTION_EXPORT_DOWNLOAD,
                status: 'blocked',
                targetType: (string) ($action->target_type ?? 'tenant_snapshot'),
                targetId: (string) $action->id,
                context: $this->requestContext($request),
                result: [
                    'error' => 'artifact_path_scope_mismatch',
                ]
            );
            abort(404);
        }

        $expiresAtRaw = trim((string) data_get((array) ($action->result ?? []), 'expires_at', ''));
        $expiresAt = null;
        if ($expiresAtRaw !== '') {
            try {
                $expiresAt = Carbon::parse($expiresAtRaw);
            } catch (\Throwable) {
                $auditService->record(
                    tenantId: (int) $tenant->id,
                    actorUserId: $request->user()?->id,
                    actionType: self::ACTION_EXPORT_DOWNLOAD,
                    status: 'blocked',
                    targetType: (string) ($action->target_type ?? 'tenant_snapshot'),
                    targetId: (string) $action->id,
                    context: $this->requestContext($request),
                    result: [
                        'error' => 'artifact_expiry_metadata_invalid',
                    ]
                );
                abort(404);
            }
        } elseif ($action->created_at !== null) {
            $expiresAt = Carbon::parse($action->created_at)->addDays($operationsService->snapshotRetentionDays());
        }

        if ($expiresAt !== null && now()->greaterThan($expiresAt)) {
            $auditService->record(
                tenantId: (int) $tenant->id,
                actorUserId: $request->user()?->id,
                actionType: self::ACTION_EXPORT_DOWNLOAD,
                status: 'blocked',
                targetType: (string) ($action->target_type ?? 'tenant_snapshot'),
                targetId: (string) $action->id,
                context: $this->requestContext($request),
                result: [
                    'error' => 'artifact_expired',
                    'expires_at' => $expiresAt->toIso8601String(),
                ]
            );
            abort(404);
        }

        if ($artifactPath === '' || ! Storage::disk('local')->exists($artifactPath)) {
            $auditService->record(
                tenantId: (int) $tenant->id,
                actorUserId: $request->user()?->id,
                actionType: self::ACTION_EXPORT_DOWNLOAD,
                status: 'blocked',
                targetType: (string) ($action->target_type ?? 'tenant_snapshot'),
                targetId: (string) $action->id,
                context: $this->requestContext($request),
                result: [
                    'error' => 'artifact_missing',
                ]
            );
            abort(404);
        }

        $fileName = trim((string) data_get((array) ($action->result ?? []), 'artifact_file_name', ''));
        if ($fileName === '') {
            $fileName = basename($artifactPath);
        }

        $auditService->record(
            tenantId: (int) $tenant->id,
            actorUserId: $request->user()?->id,
            actionType: self::ACTION_EXPORT_DOWNLOAD,
            status: 'success',
            targetType: (string) ($action->target_type ?? 'tenant_snapshot'),
            targetId: (string) $action->id,
            context: $this->requestContext($request),
            result: [
                'artifact_path' => $artifactPath,
                'artifact_file_name' => $fileName,
                'expires_at' => $expiresAt?->toIso8601String(),
            ]
        );

        return Storage::disk('local')->download($artifactPath, $fileName, [
            'Content-Type' => 'application/json',
        ]);
    }

    protected function assertTenantContextConfirmed(
        Request $request,
        Tenant $tenant,
        string $actionType,
        int $tenantIdInput,
        string $tenantSlugInput,
        string $phraseInput,
        LandlordTenantOperationsService $operationsService,
        LandlordOperatorActionAuditService $auditService
    ): void {
        $tenantSlug = strtolower(trim((string) $tenant->slug));
        $normalizedSlugInput = strtolower(trim($tenantSlugInput));
        $expectedPhrase = $operationsService->confirmationPhraseForTenant($tenant);
        $normalizedPhraseInput = strtolower(trim($phraseInput));

        if ($tenantIdInput !== (int) $tenant->id || $normalizedSlugInput !== $tenantSlug || $normalizedPhraseInput !== $expectedPhrase) {
            $auditService->record(
                tenantId: (int) $tenant->id,
                actorUserId: $request->user()?->id,
                actionType: $actionType,
                status: 'blocked',
                targetType: 'tenant',
                targetId: (string) $tenant->id,
                context: $this->requestContext($request),
                confirmation: [
                    'required_phrase' => $expectedPhrase,
                    'tenant_id_confirmed' => $tenantIdInput === (int) $tenant->id,
                    'tenant_slug_confirmed' => $normalizedSlugInput === $tenantSlug,
                    'phrase_confirmed' => $normalizedPhraseInput === $expectedPhrase,
                ],
                result: [
                    'error' => 'tenant_confirmation_failed',
                ]
            );

            throw ValidationException::withMessages([
                'confirm_phrase' => 'Tenant confirmation failed. Verify tenant id, tenant slug, and confirmation phrase.',
            ]);
        }
    }

    protected function assertProfileConfirmation(
        int $profileId,
        string $confirmationInput,
        string $fieldName = 'confirm_profile_id'
    ): void {
        $normalizedConfirmation = trim($confirmationInput);
        if ($normalizedConfirmation !== (string) $profileId) {
            throw ValidationException::withMessages([
                $fieldName => 'Customer profile confirmation does not match the selected profile id.',
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function requestContext(Request $request): array
    {
        return [
            'route' => (string) optional($request->route())->getName(),
            'path' => (string) $request->path(),
            'method' => (string) $request->method(),
            'host' => (string) $request->getHost(),
            'ip' => (string) ($request->ip() ?? ''),
            'user_agent' => (string) ($request->userAgent() ?? ''),
        ];
    }
}
