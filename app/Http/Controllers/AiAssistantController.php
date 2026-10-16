<?php

namespace App\Http\Controllers;

use App\Models\AiBranchAction;
use App\Models\AiBranchConversation;
use App\Models\AiBranchMemory;
use App\Models\Tenant;
use App\Services\AiBranch\AiAssistantAccess;
use App\Services\AiBranch\AiBranchActions;
use App\Services\AiBranch\AiBranchChat;
use App\Services\AiBranch\AiBranchContext;
use App\Services\AiBranch\AiWorkspaceContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Native/web JSON adapter over the existing scoped AI Branch services. */
class AiAssistantController extends Controller
{
    private function workspace(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 404);
        app(AiAssistantAccess::class)->authorize($tenant, $request->user());

        return $tenant;
    }

    public function session(Request $request, string $tenant = '')
    {
        $tenant = $this->workspace($request);
        $user = $request->user();
        $chat = app(AiBranchChat::class);
        $data = $request->validate(['conversation' => ['nullable', 'integer', 'min:1'], 'origin' => ['sometimes', 'array:kind,id']]);
        $origin = isset($data['origin']) ? app(\App\Services\AiBranch\AiWorkspacePage::class)->normalize($data['origin']) : [];
        $conversation = ! empty($data['conversation']) ? $chat->conversation($tenant, $user, (int) $data['conversation']) : null;
        $actions = AiBranchAction::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->latest('id')->limit(30)->get()
            ->filter(function ($action) use ($tenant, $user): bool {
                try {
                    app(AiBranchContext::class)->job($tenant, $user, (int) ($action->payload['job_id'] ?? 0));

                    return true;
                } catch (HttpException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
                    return false;
                }
            })->map(fn ($action): array => [
                'id' => $action->id, 'kind' => $action->kind, 'status' => $action->status,
                'payload' => $action->payload, 'digest' => $action->digest,
                'expires_at' => $action->expires_at?->toIso8601String(), 'result_id' => $action->result_id,
                'can_undo' => $action->kind === 'internal_job_note' && $action->status === 'executed'
                    && data_get(\App\Models\FieldServiceJobNote::query()->where('tenant_id', $tenant->id)->whereKey($action->result_id)->first()?->metadata, 'ai_branch_reversible') === true,
            ])->values()->all();
        $context = app(AiWorkspaceContext::class);
        abort_if($origin !== [] && ! $context->enabled($tenant), 422, 'Selected record context is unavailable.');
        $actionsAvailable = config('ai_branch.actions_enabled') && app(\App\Services\AiBranch\AiBranchAccess::class)->authorize($tenant, $user)['ai_limits']['actions'];

        $messages = $conversation ? (array) $conversation->messages : [];
        foreach ($messages as &$message) {
            foreach ($message['sources'] ?? [] as $index => $source) {
                $kind = $source['type'] ?? explode(':', (string) ($source['ref'] ?? ''))[0];
                $id = (int) ($source['id'] ?? 0);
                if ($kind === 'job' && $id > 0) {
                    app(AiBranchContext::class)->job($tenant, $user, $id);
                    $source['url'] = route('field-service.jobs.show', ['job' => $id, 'tenant' => $tenant->slug]);
                    $source['mobile_destination'] = ['kind' => 'field_service_job', 'id' => $id];
                }
                $message['sources'][$index] = $source;
            }
        }
        unset($message);
        $payload = [
            'actions_available' => (bool) $actionsAvailable,
            'note_jobs' => $actionsAvailable ? app(AiBranchContext::class)->sources($tenant, $user) : [],
            'reports_available' => (bool) config('ai_branch.assistant_reports_enabled') && $context->enabled($tenant),
            'report_audience' => config('ai_branch.assistant_reports_enabled') && $context->enabled($tenant)
                ? $tenant->users()->wherePivot('membership_active', true)->where('is_active', true)->orderBy('users.name')->get(['users.id', 'users.name']) : [],
            'financial_summary' => config('ai_branch.assistant_finance_enabled') ? app(\App\Services\AiBranch\AiFinancialSummary::class)->capture($tenant, $user) : null,
            'version' => 1, 'workspace' => ['id' => $tenant->id, 'name' => $tenant->name],
            'context' => $context->enabled($tenant) ? $context->capture($tenant, $user, $origin) : null,
            'conversation' => $conversation ? ['id' => $conversation->id, 'messages' => $messages, 'pending' => $conversation->pending_uuid !== null] : null,
            'conversations' => AiBranchConversation::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->where('expires_at', '>', now())->latest('id')->limit(30)->get(['id', 'created_at', 'expires_at']),
            'memories' => AiBranchMemory::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->where('expires_at', '>', now())->get(['id', 'body', 'expires_at']),
            'actions' => $actions, 'usage' => $chat->usage($tenant),
        ];
        app(AiAssistantAccess::class)->authorize($tenant, $user);

        return response()->json($payload)->header('Cache-Control', 'no-store, private');
    }

    public function start(Request $request, string $tenant)
    {
        $this->workspace($request);

        return app(AiBranchController::class)->start($request);
    }

    public function ask(Request $request, string $tenant, int $conversation)
    {
        $this->workspace($request);

        $response = app(AiBranchController::class)->ask($request, $conversation);
        $this->workspace($request);

        return $response;
    }

    public function memory(Request $request, string $tenant)
    {
        $this->workspace($request);

        return app(AiBranchController::class)->memory($request);
    }

    public function deleteConversation(Request $request, string $tenant, int $conversation)
    {
        $this->workspace($request);

        return app(AiBranchController::class)->deleteConversation($request, $conversation);
    }

    public function propose(Request $request, string $tenant)
    {
        $this->workspace($request);

        return app(AiBranchController::class)->propose($request, app(AiBranchActions::class));
    }

    public function forget(Request $request, string $tenant, int $memory)
    {
        $this->workspace($request);

        return app(AiBranchController::class)->deleteMemory($request, $memory);
    }

    public function decide(Request $request, string $tenant, int $action)
    {
        $this->workspace($request);

        return app(AiBranchController::class)->decide($request, $action, app(AiBranchActions::class));
    }

    public function undo(Request $request, string $tenant = '', int $action = 0)
    {
        $workspace = $this->workspace($request);
        $action = $action ?: (int) $request->route('action');
        $data = $request->validate(['digest' => ['required', 'string', 'size:64'], 'request_id' => ['required', 'uuid'], 'confirmed' => ['accepted']]);
        $receipt = app(AiBranchActions::class)->undo($workspace, $request->user(), $action, $data['digest'], $data['request_id']);

        return response()->json(['action_id' => $receipt->id, 'status' => $receipt->status, 'reversed_action_id' => $action])->header('Cache-Control', 'no-store');
    }

    public function webUndo(Request $request, int $action)
    {
        return $this->undo($request, '', $action);
    }
}
