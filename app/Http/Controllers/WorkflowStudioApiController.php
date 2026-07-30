<?php

namespace App\Http\Controllers;

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowRun;
use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\V2\WorkflowComponentCatalog;
use App\Services\Automation\V2\WorkflowDefinitionException;
use App\Services\Automation\V2\WorkflowDraftConflictException;
use App\Services\Automation\V2\WorkflowDraftService;
use App\Services\Automation\V2\WorkflowStudioBootstrapService;
use App\Services\Automation\V2\WorkflowStudioFeatureGate;
use App\Services\Automation\V2\WorkflowStudioProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowStudioApiController extends Controller
{
    public function catalog(
        Request $request,
        WorkflowComponentCatalog $catalog,
    ): JsonResponse {
        $this->assertStudioEnabled($request);

        return response()->json($catalog->publicCatalog());
    }

    public function store(
        Request $request,
        WorkflowDraftService $drafts,
        WorkflowStudioBootstrapService $bootstrap,
    ): JsonResponse {
        $this->assertStudioEnabled($request);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'template_key' => ['nullable', 'string', 'max:100'],
            'definition' => ['nullable', 'array'],
            'draft_definition' => ['nullable', 'array'],
        ]);
        $tenantId = $this->tenantId($request);
        $templateKey = trim((string) ($data['template_key'] ?? 'blank'));
        $definition = (array) ($data['definition'] ?? $data['draft_definition'] ?? []);
        $requestedName = trim((string) ($data['name'] ?? ''));
        $explicitTemplateName = $requestedName !== ''
            && strcasecmp($requestedName, 'Untitled workflow') !== 0
                ? $requestedName
                : null;
        $usesTemplate = $templateKey !== 'blank' && $this->templateExists($templateKey);

        try {
            $workflow = $usesTemplate
                ? $drafts->createFromTemplate(
                    $tenantId,
                    $templateKey,
                    $request->user(),
                    $explicitTemplateName,
                )
                : $drafts->createBlank(
                    $tenantId,
                    $request->user(),
                    $requestedName ?: 'Untitled workflow',
                );

            if ($definition !== []) {
                $workflow = $drafts->save(
                    $tenantId,
                    (int) $workflow->getKey(),
                    (int) $workflow->draft_revision,
                    $definition,
                    $request->user(),
                    $usesTemplate ? $explicitTemplateName : ($requestedName ?: null),
                );
            }
        } catch (WorkflowDefinitionException $exception) {
            return $this->definitionError($exception);
        } catch (AutomationWorkflowException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($bootstrap->apiPayload($workflow), 201);
    }

    public function load(
        Request $request,
        AutomationWorkflow $workflow,
        WorkflowDraftService $drafts,
        WorkflowStudioBootstrapService $bootstrap,
    ): JsonResponse {
        $this->assertOwned($request, $workflow);
        $loaded = $drafts->load($this->tenantId($request), (int) $workflow->getKey());
        $fresh = $loaded['workflow'];

        return response()->json($bootstrap->apiPayload($fresh, (array) $loaded['definition']));
    }

    public function save(
        Request $request,
        AutomationWorkflow $workflow,
        WorkflowDraftService $drafts,
        WorkflowStudioBootstrapService $bootstrap,
    ): JsonResponse {
        $this->assertOwned($request, $workflow);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'draft_revision' => ['required', 'integer', 'min:0'],
            'definition' => ['nullable', 'array'],
            'draft_definition' => ['nullable', 'array'],
        ]);
        $definition = (array) ($data['definition'] ?? $data['draft_definition'] ?? []);

        try {
            $saved = $drafts->save(
                $this->tenantId($request),
                (int) $workflow->getKey(),
                (int) $data['draft_revision'],
                $definition,
                $request->user(),
                isset($data['name']) ? trim((string) $data['name']) : null,
            );
        } catch (WorkflowDraftConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'current_revision' => $exception->currentRevision,
                'expected_revision' => $exception->expectedRevision,
            ], 409);
        } catch (WorkflowDefinitionException $exception) {
            return $this->definitionError($exception);
        } catch (AutomationWorkflowException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($bootstrap->apiPayload($saved));
    }

    public function testStep(
        Request $request,
        AutomationWorkflow $workflow,
        string $step,
        WorkflowStudioProductService $studio,
        WorkflowStudioBootstrapService $bootstrap,
    ): JsonResponse {
        $this->assertOwned($request, $workflow);
        $data = $request->validate([
            'step_id' => ['nullable', 'string', 'max:100'],
            'draft_revision' => ['required', 'integer', 'min:1'],
            'sample' => ['nullable', 'array'],
        ]);
        if ((int) $workflow->draft_revision !== (int) $data['draft_revision']) {
            return response()->json(['message' => 'This draft changed before the test started. Save and try again.'], 409);
        }

        try {
            [$fresh, $result] = $studio->testStep(
                $workflow,
                $step,
                $request->user(),
                (array) ($data['sample'] ?? []),
            );
        } catch (WorkflowDefinitionException $exception) {
            return $this->definitionError($exception);
        } catch (AutomationWorkflowException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($bootstrap->apiPayload($fresh) + ['result' => $result]);
    }

    public function testRun(
        Request $request,
        AutomationWorkflow $workflow,
        WorkflowStudioProductService $studio,
        WorkflowStudioBootstrapService $bootstrap,
    ): JsonResponse {
        $this->assertOwned($request, $workflow);
        $data = $request->validate([
            'draft_revision' => ['required', 'integer', 'min:1'],
            'sample' => ['nullable', 'array'],
        ]);

        try {
            $run = $studio->testRun(
                $workflow,
                (int) $data['draft_revision'],
                $request->user(),
                (array) ($data['sample'] ?? []),
            );
        } catch (WorkflowDraftConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (WorkflowDefinitionException $exception) {
            return $this->definitionError($exception);
        } catch (AutomationWorkflowException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($bootstrap->apiPayload($workflow->fresh()) + [
            'run' => [
                'id' => $run->getKey(),
                'status' => $run->status,
                'url' => route('workflows.runs.show', $run),
            ],
        ]);
    }

    public function publish(
        Request $request,
        AutomationWorkflow $workflow,
        WorkflowStudioProductService $studio,
        WorkflowStudioBootstrapService $bootstrap,
    ): JsonResponse {
        $this->assertOwned($request, $workflow);
        $data = $request->validate(['draft_revision' => ['required', 'integer', 'min:1']]);

        try {
            $published = $studio->publish(
                $workflow,
                (int) $data['draft_revision'],
                $request->user(),
            );
        } catch (WorkflowDraftConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (WorkflowDefinitionException $exception) {
            return $this->definitionError($exception);
        } catch (AutomationWorkflowException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($bootstrap->apiPayload($published));
    }

    public function pause(
        Request $request,
        AutomationWorkflow $workflow,
        WorkflowStudioProductService $studio,
        WorkflowStudioBootstrapService $bootstrap,
    ): JsonResponse {
        $this->assertOwned($request, $workflow);

        try {
            $paused = $studio->pause($workflow, $request->user());
        } catch (AutomationWorkflowException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($bootstrap->apiPayload($paused));
    }

    public function resume(
        Request $request,
        AutomationWorkflow $workflow,
        WorkflowStudioProductService $studio,
        WorkflowStudioBootstrapService $bootstrap,
    ): JsonResponse {
        $this->assertOwned($request, $workflow);

        try {
            $resumed = $studio->resume(
                $workflow,
                $request->user(),
                $request->boolean('release_held_items')
            );
        } catch (WorkflowDefinitionException $exception) {
            return $this->definitionError($exception);
        } catch (AutomationWorkflowException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($bootstrap->apiPayload($resumed));
    }

    public function retryRun(
        Request $request,
        AutomationWorkflowRun $run,
        WorkflowStudioProductService $studio,
    ): JsonResponse {
        if ((int) $run->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
        $this->assertStudioEnabled($request);

        try {
            $queued = $studio->retryRun($run, $request->user());
        } catch (AutomationWorkflowException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'run_id' => $run->getKey(),
            'queued_items' => $queued,
            'status' => 'running',
            'url' => route('workflows.runs.show', $run),
        ]);
    }

    public function discardHeld(
        Request $request,
        AutomationWorkflow $workflow,
        WorkflowStudioProductService $studio,
        WorkflowStudioBootstrapService $bootstrap,
    ): JsonResponse {
        $this->assertOwned($request, $workflow);

        try {
            $discardedItems = $studio->discardHeldItems($workflow, $request->user());
        } catch (AutomationWorkflowException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($bootstrap->apiPayload($workflow->fresh()) + [
            'discarded_items' => $discardedItems,
        ]);
    }

    protected function templateExists(string $templateKey): bool
    {
        return array_key_exists($templateKey, app(WorkflowComponentCatalog::class)->templates());
    }

    protected function tenantId(Request $request): int
    {
        $tenantId = $request->attributes->get('current_tenant_id');
        if (! is_numeric($tenantId) || (int) $tenantId <= 0) {
            abort(403, 'A workspace is required to manage automations.');
        }

        return (int) $tenantId;
    }

    protected function assertOwned(Request $request, AutomationWorkflow $workflow): void
    {
        if ((int) $workflow->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
        $this->assertStudioEnabled($request);
    }

    protected function assertStudioEnabled(Request $request): void
    {
        if (! app(WorkflowStudioFeatureGate::class)->enabledForTenant($this->tenantId($request))) {
            abort(403, 'Workflow Studio is not enabled for this workspace.');
        }
    }

    protected function definitionError(WorkflowDefinitionException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'errors' => $exception->errors(),
        ], 422);
    }
}
