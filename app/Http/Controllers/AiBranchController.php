<?php

namespace App\Http\Controllers;

use App\Models\AiBranchAccount;
use App\Models\AiBranchAction;
use App\Models\AiBranchConversation;
use App\Models\AiBranchMemory;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Services\AiBranch\AiBranchAccess;
use App\Services\AiBranch\AiBranchActions;
use App\Services\AiBranch\AiBranchChat;
use App\Services\AiBranch\AiBranchContext;
use App\Services\AiBranch\AiWorkspaceContext;
use App\Services\AiBranch\AiWorkspacePage;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AiBranchController extends Controller
{
    public function __construct(private AiBranchAccess $access, private AiBranchChat $chat) {}

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 404);
        $this->access->member($tenant, $request->user());

        return $tenant;
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        try {
            $tier = $this->access->authorize($tenant, $request->user(), false);
        } catch (HttpException $exception) {
            if (! config('ai_branch.standard_enabled') || app(\App\Services\AiBranch\AiFictionalPilot::class)->reserved($tenant)) {
                throw $exception;
            }

            return response()->view('ai-branch.offer', ['tenant' => $tenant, 'existing' => (config('ai_branch.existing_tenants')[$tenant->id] ?? null) === $tenant->slug])->header('Cache-Control', 'no-store, private');
        }
        $account = AiBranchAccount::query()->where('tenant_id', $tenant->id)->first();
        $conversation = null;
        $contextNotice = null;
        if ($request->integer('conversation')) {
            try {
                $conversation = $this->chat->conversation($tenant, $request->user(), $request->integer('conversation'));
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() !== 409) {
                    throw $exception;
                }
                $contextNotice = $exception->getMessage();
            }
        }
        $workspaceContext = app(AiWorkspaceContext::class);
        $enhanced = $workspaceContext->enabled($tenant);
        $origin = $this->origin($request);
        abort_if(! $enhanced && $origin !== [], 422, 'Page context is unavailable.');
        $snapshot = $enhanced && $account?->enabled ? $workspaceContext->capture($tenant, $request->user(), $origin) : null;
        $workspace = $enhanced ? ($snapshot['workspace'] ?? $workspaceContext->profile($tenant, $request->user())) : null;

        return response()->view('ai-branch.index', [
            'canApproveAi' => $this->access->canApprove($tenant, $request->user()),
            'metered' => app(\App\Services\AiBranch\AiBranchOffer::class)->metered($tenant),
            'commercial' => app(\App\Services\AiBranch\AiBranchOffer::class)->presentation($tenant),
            'selectedModel' => app(\App\Services\AiBranch\AiBranchOffer::class)->model($tenant),
            'pilotGrant' => app(\App\Services\AiBranch\AiFictionalPilot::class)->current()
                ? app(\App\Services\AiBranch\AiFictionalPilot::class)->grant($tenant, $request->user()) : null,
            'tenant' => $tenant, 'tier' => $tier, 'account' => $account,
            'enhanced' => $enhanced, 'snapshot' => $snapshot, 'workspace' => $workspace, 'origin' => $origin, 'contextNotice' => $contextNotice,
            'usage' => $this->chat->usage($tenant), 'conversation' => $conversation,
            'conversations' => AiBranchConversation::query()->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)
                ->where('expires_at', '>', now())->latest('id')->limit(30)->get(),
            'memories' => AiBranchMemory::query()->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->where('expires_at', '>', now())->get(),
            'actions' => AiBranchAction::query()->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->where('kind', 'internal_job_note')->where('status', 'pending')->where('expires_at', '>', now())->latest()->limit(20)->get()->filter(function ($action) use ($tenant, $request): bool {
                try {
                    app(AiBranchContext::class)->job($tenant, $request->user(), (int) ($action->payload['job_id'] ?? 0));

                    return true;
                } catch (HttpException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
                    return false;
                }
            }),
            'jobs' => $account?->enabled ? app(AiBranchContext::class)->sources($tenant, $request->user()) : [],
        ])->header('Cache-Control', 'no-store');
    }

    public function settings(Request $request)
    {
        $tenant = $this->tenant($request);
        $tier = $this->access->authorize($tenant, $request->user(), false);
        if ($request->has('budget_dollars')) {
            abort_if($request->has('budget_cents'), 422);
            $request->validate(['budget_dollars' => ['required', 'regex:/^\d{1,4}(\.\d{1,2})?$/']]);
            $request->merge(['budget_cents' => (int) round((float) $request->input('budget_dollars') * 100)]);
        }
        $metered = app(\App\Services\AiBranch\AiBranchOffer::class)->metered($tenant);
        if ($metered) {
            abort_unless($this->access->canApprove($tenant, $request->user()), 403, 'A workspace owner or verified billing admin must approve AI pricing, model and cap.');
        }
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'budget_cents' => ['required', 'integer', 'min:0', 'max:'.$tier['ai_limits']['provider_budget_cents']], 'confirmed' => ['accepted'], 'timezone' => ['sometimes', 'required', 'string', 'timezone:all'],
            'model' => [$metered ? 'required' : 'sometimes', \Illuminate\Validation\Rule::in(array_keys(config('ai_branch.models')))]]);
        abort_if($metered && $data['enabled'] && $data['budget_cents'] === 0, 422, 'Choose a positive spending cap before enabling AI.');
        if (isset($data['timezone'])) {
            app(AiWorkspaceContext::class)->authorize($tenant, $request->user(), false);
        }
        DB::transaction(function () use ($tenant, $request, $data, $metered): void {
            $account = AiBranchAccount::query()->firstOrCreate(['tenant_id' => $tenant->id]);
            $account = AiBranchAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $before = $account->only(['enabled', 'budget_cents']);
            if (isset($data['timezone']) || $metered) {
                $profile = TenantAccessProfile::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
                $metadata = (array) $profile->metadata;
                $before['timezone'] = data_get($metadata, 'ai_branch.timezone', 'UTC');
                if (isset($data['timezone'])) {
                    data_set($metadata, 'ai_branch.timezone', $data['timezone']);
                }
                if ($metered) {
                    data_set($metadata, 'ai_branch.model', $data['model']);
                    data_set($metadata, 'ai_branch.usage_terms', ['version' => config('ai_branch.rates_version'), 'multiplier' => 2,
                        'rates_digest' => hash('sha256', json_encode(config('ai_branch.models')[$data['model']], JSON_THROW_ON_ERROR)),
                        'approved_by' => $request->user()->id, 'approved_at' => now()->toIso8601String()]);
                }
                $profile->forceFill(['metadata' => $metadata])->save();
            }
            $account->fill(['enabled' => $data['enabled'], 'budget_cents' => $data['budget_cents'], 'approved_by' => $request->user()->id, 'approved_at' => now()])->save();
            app(LandlordOperatorActionAuditService::class)->record($tenant->id, $request->user()->id, 'ai_branch.opt_in',
                targetType: 'ai_branch_account', targetId: $account->id,
                confirmation: ['explicit' => true], beforeState: $before, afterState: [...$account->only(['enabled', 'budget_cents']), ...(isset($data['timezone']) ? ['timezone' => $data['timezone']] : [])],
                context: ['billing_impact' => $metered ? 'Prepaid AI usage at approved model rates (2x provider cost); no subscription change.' : 'No subscription change or overage charge; provider cap only.',
                    ...($metered ? ['model' => $data['model'], 'pricing_version' => config('ai_branch.rates_version')] : [])]);
        }, 3);

        return redirect()->route('ai-branch.index', ['tenant' => $tenant->slug])->with('status', 'AI workspace settings saved.');
    }

    public function checkout(Request $request)
    {
        $tenant = $this->tenant($request);
        $request->validate(['confirmed' => ['accepted']]);
        $result = app(\App\Services\AiBranch\AiBranchAccessPurchase::class)->prepare($tenant, $request->user(), 'owner-reviewed-ai-access-'.$request->user()->id);

        return redirect()->away($result['url']);
    }

    public function creditCheckout(Request $request)
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['pack_cents' => ['required', 'integer'], 'confirmed' => ['accepted']]);
        $result = app(\App\Services\Billing\StripeMessagingCreditService::class)->createAiCheckout($tenant, $request->user(), $data['pack_cents']);
        abort_unless($result['ok'] ?? false, 422, $result['message'] ?? 'Choose a valid credit pack.');

        return redirect()->away($result['url']);
    }

    public function start(Request $request)
    {
        $tenant = $this->tenant($request);
        $origin = $this->origin($request);
        if ($origin !== []) {
            app(AiWorkspaceContext::class)->authorize($tenant, $request->user());
            app(AiWorkspacePage::class)->resolve($tenant, $request->user(), $origin);
        }
        $conversation = $this->chat->start($tenant, $request->user());
        if ($request->expectsJson()) {
            return response()->json(['conversation_id' => $conversation->id], 201)->header('Cache-Control', 'no-store');
        }

        return redirect()->route('ai-branch.index', ['tenant' => $tenant->slug, 'conversation' => $conversation->id, ...($origin !== [] ? ['origin' => $origin] : [])]);
    }

    public function ask(Request $request, int $conversation)
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['question' => ['required', 'string', 'max:3000'], 'request_id' => ['required', 'uuid']]);
        if ($request->has('model')) {
            abort_unless($request->input('model') === app(\App\Services\AiBranch\AiBranchOffer::class)->model($tenant), 409, 'The selected AI model changed. Refresh before asking again.');
        }
        $result = $this->chat->ask($tenant, $request->user(), $conversation, $data['question'], $data['request_id'], $this->origin($request));
        if ($request->expectsJson()) {
            return response()->json($result)->header('Cache-Control', 'no-store');
        }

        return redirect()->route('ai-branch.index', ['tenant' => $tenant->slug, 'conversation' => $conversation, ...($request->has('origin') ? ['origin' => $this->origin($request)] : [])]);
    }

    private function origin(Request $request): array
    {
        $data = $request->validate(['origin' => ['sometimes', 'array:kind,id']]);

        return isset($data['origin']) ? app(AiWorkspacePage::class)->normalize($data['origin']) : [];
    }

    public function briefing(Request $request)
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['request_id' => ['required', 'uuid']]);
        $origin = $this->origin($request);
        try {
            $result = $this->chat->briefing($tenant, $request->user(), $data['request_id'], $origin);
        } catch (HttpException $exception) {
            $id = $exception->getHeaders()['X-AI-Conversation'] ?? null;
            if (! $id || $request->expectsJson()) {
                throw $exception;
            }

            return redirect()->route('ai-branch.index', ['tenant' => $tenant->slug, 'conversation' => $id])->with('status', $exception->getMessage());
        }
        if ($request->expectsJson()) {
            return response()->json($result)->header('Cache-Control', 'no-store');
        }

        return redirect()->route('ai-branch.index', ['tenant' => $tenant->slug, 'conversation' => $result['conversation_id'], ...($origin !== [] ? ['origin' => $origin] : [])]);
    }

    public function deleteConversation(Request $request, int $conversation)
    {
        $tenant = $this->tenant($request);
        DB::transaction(function () use ($tenant, $request, $conversation): void {
            $row = AiBranchConversation::query()->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->whereKey($conversation)->lockForUpdate()->firstOrFail();
            abort_if($row->pending_uuid !== null, 409, 'This chat has a request awaiting usage review.');
            $row->delete();
        });

        if ($request->expectsJson()) {
            return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
        }

        return back()->with('status', 'Chat deleted.');
    }

    public function memory(Request $request)
    {
        $tenant = $this->tenant($request);
        $this->access->authorize($tenant, $request->user());
        $data = $request->validate(['body' => ['required', 'string', 'max:500'], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($tenant, $request, $data): void {
            AiBranchAccount::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            abort_if(AiBranchMemory::query()->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->where('expires_at', '>', now())->count() >= 10, 422, 'Delete an existing memory first.');
            AiBranchMemory::query()->create(['tenant_id' => $tenant->id, 'user_id' => $request->user()->id, 'body' => $data['body'], 'expires_at' => now()->addDays((int) config('ai_branch.memory_days'))]);
        });

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'restart_required' => true], 201)->header('Cache-Control', 'no-store');
        }

        return redirect()->route('ai-branch.index', ['tenant' => $tenant->slug])->with('status', 'Memory saved. Start a new chat to use the updated memory.');
    }

    public function deleteMemory(Request $request, int $memory)
    {
        $tenant = $this->tenant($request);
        DB::transaction(function () use ($tenant, $request, $memory): void {
            AiBranchAccount::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            AiBranchMemory::query()->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->whereKey($memory)->firstOrFail()->delete();
        });

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'restart_required' => true])->header('Cache-Control', 'no-store');
        }

        return redirect()->route('ai-branch.index', ['tenant' => $tenant->slug])->with('status', 'Memory deleted. Start a new chat for the updated context.');
    }

    public function propose(Request $request, AiBranchActions $actions)
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['job_id' => ['required', 'integer', 'min:1'], 'body' => ['required', 'string', 'max:3000'], 'request_id' => ['required', 'uuid']]);
        $proposal = $actions->propose($tenant, $request->user(), $data['job_id'], $data['body'], $data['request_id']);
        if ($request->expectsJson()) {
            return response()->json(['action_id' => $proposal->id, 'status' => $proposal->status, 'digest' => $proposal->digest, 'payload' => $proposal->payload], 201)->header('Cache-Control', 'no-store');
        }

        return redirect()->route('ai-branch.index', ['tenant' => $tenant->slug])->with('status', 'Review the exact note before approving it.');
    }

    public function decide(Request $request, int $action, AiBranchActions $actions)
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['digest' => ['required', 'string', 'size:64'], 'decision' => ['required', 'in:approve,reject'], 'confirmed' => ['accepted']]);
        $result = $actions->decide($tenant, $request->user(), $action, $data['digest'], $data['decision'] === 'approve');
        if ($request->expectsJson()) {
            return response()->json(['action_id' => $result->id, 'status' => $result->status, 'result_id' => $result->result_id])->header('Cache-Control', 'no-store');
        }

        return redirect()->route('ai-branch.index', ['tenant' => $tenant->slug])->with('status', 'Action decision recorded.');
    }
}
