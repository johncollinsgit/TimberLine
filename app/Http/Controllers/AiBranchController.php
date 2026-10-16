<?php

namespace App\Http\Controllers;

use App\Models\AiBranchAccount;
use App\Models\AiBranchAction;
use App\Models\AiBranchConversation;
use App\Models\AiBranchMemory;
use App\Models\Tenant;
use App\Services\AiBranch\AiBranchAccess;
use App\Services\AiBranch\AiBranchActions;
use App\Services\AiBranch\AiBranchChat;
use App\Services\AiBranch\AiBranchContext;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $tier = $this->access->authorize($tenant, $request->user(), false);
        $account = AiBranchAccount::query()->where('tenant_id', $tenant->id)->first();
        $conversation = $request->integer('conversation') ? $this->chat->conversation($tenant, $request->user(), $request->integer('conversation')) : null;
        $scopeHash = app(AiBranchContext::class)->scopeHash($tenant, $request->user());

        return response()->view('ai-branch.index', [
            'tenant' => $tenant, 'tier' => $tier, 'account' => $account,
            'usage' => $this->chat->usage($tenant), 'conversation' => $conversation,
            'conversations' => AiBranchConversation::query()->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)
                ->where('context_hash', $scopeHash)->where('expires_at', '>', now())->latest('id')->limit(30)->get(),
            'memories' => AiBranchMemory::query()->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->where('expires_at', '>', now())->get(),
            'actions' => AiBranchAction::query()->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->where('status', 'pending')->where('expires_at', '>', now())->latest()->limit(20)->get(),
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
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'budget_cents' => ['required', 'integer', 'min:0', 'max:'.$tier['ai_limits']['provider_budget_cents']], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($tenant, $request, $data): void {
            $account = AiBranchAccount::query()->firstOrCreate(['tenant_id' => $tenant->id]);
            $account = AiBranchAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $before = $account->only(['enabled', 'budget_cents']);
            $account->fill(['enabled' => $data['enabled'], 'budget_cents' => $data['budget_cents'], 'approved_by' => $request->user()->id, 'approved_at' => now()])->save();
            app(LandlordOperatorActionAuditService::class)->record($tenant->id, $request->user()->id, 'ai_branch.opt_in',
                targetType: 'ai_branch_account', targetId: $account->id,
                confirmation: ['explicit' => true], beforeState: $before, afterState: $account->only(['enabled', 'budget_cents']),
                context: ['billing_impact' => 'No subscription change or overage charge; provider cap only.']);
        }, 3);

        return redirect()->route('ai-branch.index', ['tenant' => $tenant->slug])->with('status', 'AI workspace settings saved.');
    }

    public function start(Request $request)
    {
        $tenant = $this->tenant($request);
        $conversation = $this->chat->start($tenant, $request->user());

        return redirect()->route('ai-branch.index', ['tenant' => $tenant->slug, 'conversation' => $conversation->id]);
    }

    public function ask(Request $request, int $conversation)
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['question' => ['required', 'string', 'max:3000'], 'request_id' => ['required', 'uuid']]);
        $result = $this->chat->ask($tenant, $request->user(), $conversation, $data['question'], $data['request_id']);
        if ($request->expectsJson()) {
            return response()->json($result)->header('Cache-Control', 'no-store');
        }

        return redirect()->route('ai-branch.index', ['tenant' => $tenant->slug, 'conversation' => $conversation]);
    }

    public function deleteConversation(Request $request, int $conversation)
    {
        $tenant = $this->tenant($request);
        DB::transaction(function () use ($tenant, $request, $conversation): void {
            $row = AiBranchConversation::query()->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->whereKey($conversation)->lockForUpdate()->firstOrFail();
            abort_if($row->pending_uuid !== null, 409, 'This chat has a request awaiting usage review.');
            $row->delete();
        });

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

        return redirect()->route('ai-branch.index', ['tenant' => $tenant->slug])->with('status', 'Memory saved. Start a new chat to use the updated memory.');
    }

    public function deleteMemory(Request $request, int $memory)
    {
        $tenant = $this->tenant($request);
        DB::transaction(function () use ($tenant, $request, $memory): void {
            AiBranchAccount::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            AiBranchMemory::query()->where('tenant_id', $tenant->id)->where('user_id', $request->user()->id)->whereKey($memory)->firstOrFail()->delete();
        });

        return redirect()->route('ai-branch.index', ['tenant' => $tenant->slug])->with('status', 'Memory deleted. Start a new chat for the updated context.');
    }

    public function propose(Request $request, AiBranchActions $actions)
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['job_id' => ['required', 'integer', 'min:1'], 'body' => ['required', 'string', 'max:3000'], 'request_id' => ['required', 'uuid']]);
        $actions->propose($tenant, $request->user(), $data['job_id'], $data['body'], $data['request_id']);

        return redirect()->route('ai-branch.index', ['tenant' => $tenant->slug])->with('status', 'Review the exact note before approving it.');
    }

    public function decide(Request $request, int $action, AiBranchActions $actions)
    {
        $tenant = $this->tenant($request);
        $data = $request->validate(['digest' => ['required', 'string', 'size:64'], 'decision' => ['required', 'in:approve,reject'], 'confirmed' => ['accepted']]);
        $actions->decide($tenant, $request->user(), $action, $data['digest'], $data['decision'] === 'approve');

        return redirect()->route('ai-branch.index', ['tenant' => $tenant->slug])->with('status', 'Action decision recorded.');
    }
}
