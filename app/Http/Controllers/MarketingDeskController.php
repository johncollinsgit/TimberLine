<?php

namespace App\Http\Controllers;

use App\Models\AutomationWorkflow;
use App\Models\IntegrationConnection;
use App\Models\MarketingAssistantTurn;
use App\Models\MarketingAudiencePermission;
use App\Models\MarketingBrandKnowledgeVersion;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingConversationControl;
use App\Models\MarketingDeliveryAuthorization;
use App\Models\MarketingProfile;
use App\Models\MarketingReplyPolicy;
use App\Models\MarketingSocialPost;
use App\Models\MarketingSocialPublication;
use App\Models\MessagingConversation;
use App\Services\Automation\V2\WorkflowDraftService;
use App\Services\Marketing\Desk\BrandKnowledgeService;
use App\Services\Marketing\Desk\InstagramPostSyncService;
use App\Services\Marketing\Desk\MarketingAiService;
use App\Services\Marketing\Desk\MarketingDeskAccess;
use App\Services\Marketing\Desk\MarketingSignalService;
use App\Services\Marketing\Desk\MarketingWorkflowCatalog;
use App\Services\Marketing\Desk\ReplyAutomationService;
use App\Services\Marketing\Desk\SocialPostService;
use App\Services\Marketing\Desk\SocialPublisher;
use App\Services\Marketing\Desk\SocialResultsService;
use App\Services\Marketing\MessagingResponseInboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketingDeskController extends Controller
{
    private function tenant(Request $request, bool $owner = false, bool $staff = false): int
    {
        $id = (int) $request->attributes->get('current_tenant_id');
        app(MarketingDeskAccess::class)->ensure($id, $request->user(), $owner, $staff);

        return $id;
    }

    private function done(Request $request, string $view, mixed $data = null)
    {
        return $request->expectsJson() ? response()->json($data ?? ['ok' => true])
            : redirect()->route('marketing.desk.index', ['view' => $view])->with('toast', ['style' => 'success', 'message' => 'Saved.']);
    }

    public function index(Request $request)
    {
        $view = (string) $request->query('view', 'today');
        abort_unless(in_array($view, ['today', 'chat', 'calendar', 'insights', 'inbox', 'recipes', 'knowledge', 'settings'], true), 404);
        $tenantId = $this->tenant($request, staff: $view === 'inbox');
        config(['marketing_desk.timezone' => app(\App\Services\Marketing\Desk\MarketingWorkspaceClock::class)->timezone($tenantId)]);
        $role = $request->user()->tenants()->whereKey($tenantId)->first()->pivot->role;
        $staff = in_array($role, ['member', 'employee'], true);
        $threads = MessagingConversation::forTenantId($tenantId)->where('status', 'open')->when($staff, fn ($q) => $q->where('assigned_to', $request->user()->id))->orderByDesc('last_message_at')->limit(30)->get();
        $selected = $request->integer('conversation') ? (clone MessagingConversation::forTenantId($tenantId))->when($staff, fn ($q) => $q->where('assigned_to', $request->user()->id))->findOrFail($request->integer('conversation')) : null;
        $messages = $selected ? $selected->messages()->latest('id')->limit(50)->get()->reverse() : collect();
        $controls = MarketingConversationControl::forTenantId($tenantId)->whereIn('conversation_id', $threads->pluck('id'))->get()->keyBy('conversation_id');
        $posts = $staff ? collect() : MarketingSocialPost::forTenantId($tenantId)->orderByDesc('id')->limit(50)->get();
        $publications = $staff ? collect() : MarketingSocialPublication::forTenantId($tenantId)->whereIn('post_id', $posts->pluck('id'))->get();
        $connections = $staff ? collect() : IntegrationConnection::forTenantId($tenantId)->whereIn('provider', ['instagram', 'facebook'])->get();
        $knowledge = $staff ? collect() : MarketingBrandKnowledgeVersion::forTenantId($tenantId)->latest('id')->limit(30)->get();
        $turns = $staff ? collect() : MarketingAssistantTurn::forTenantId($tenantId)->where('user_id', $request->user()->id)->where('purpose', 'owner')->latest('id')->limit(10)->get()->reverse();
        $workflows = $staff ? collect() : AutomationWorkflow::forTenantId($tenantId)->with('publishedVersion')->latest('id')->limit(50)->get();
        $policies = $staff ? collect() : MarketingReplyPolicy::forTenantId($tenantId)->get();
        $budget = $staff ? [] : app(MarketingAiService::class)->budget($tenantId);
        $recipes = app(MarketingWorkflowCatalog::class)->recipes();
        $tenant = \App\Models\Tenant::findOrFail($tenantId);
        $ranking = in_array($request->query('ranking'), array_keys(SocialResultsService::METRICS), true) ? $request->query('ranking') : 'reach';
        $platformFilter = in_array($request->query('platform'), ['instagram', 'facebook'], true) ? $request->query('platform') : 'all';
        $formatFilter = in_array($request->query('format'), ['image', 'carousel', 'video', 'text'], true) ? $request->query('format') : 'all';
        $results = app(SocialResultsService::class)->cards($posts, $publications, $ranking, $platformFilter, $formatFilter);
        $photoPosts = $posts->filter(fn ($post) => filled($post->content['thumbnail_url'] ?? (($post->content['media_type'] ?? '') !== 'video' ? ($post->content['media_urls'][0] ?? null) : null)))->take(4);

        return response()->view('marketing.desk.index', compact('view', 'tenantId', 'tenant', 'staff', 'threads', 'selected', 'messages', 'controls', 'posts', 'publications', 'connections', 'knowledge', 'turns', 'workflows', 'policies', 'budget', 'recipes', 'ranking', 'platformFilter', 'formatFilter', 'results', 'photoPosts'))->header('Cache-Control', 'private, no-store');
    }

    public function knowledge(Request $request)
    {
        $id = $this->tenant($request);
        $knowledge = app(BrandKnowledgeService::class)->create($id, $request->user(), $request->all());

        return $this->done($request, 'knowledge', ['id' => $knowledge->id]);
    }

    public function approveKnowledge(Request $request, int $id)
    {
        app(BrandKnowledgeService::class)->approve($this->tenant($request), $id, $request->user());

        return $this->done($request, 'knowledge');
    }

    public function chat(Request $request)
    {
        $tenantId = $this->tenant($request);
        $data = $request->validate(['question' => 'required|string|max:3000', 'request_uuid' => 'required|uuid', 'thread_uuid' => 'required|uuid', 'knowledge_id' => 'nullable|integer']);
        $knowledge = ! empty($data['knowledge_id']) ? app(BrandKnowledgeService::class)->approved($tenantId, $data['knowledge_id']) : null;
        $source = $knowledge ? 'knowledge:'.$knowledge->id : null;
        $evidence = ['question' => $data['question'], 'workspace' => \App\Models\Tenant::findOrFail($tenantId)->name, 'knowledge' => $knowledge ? [['source_id' => $source, ...$knowledge->content]] : []];
        // History is private to the operator and supplied as untrusted conversation evidence.
        $evidence['history'] = MarketingAssistantTurn::forTenantId($tenantId)->where('user_id', $request->user()->id)->where('thread_uuid', $data['thread_uuid'])->where('purpose', 'owner')->where('status', 'completed')->latest('id')->limit(3)->get()->reverse()->filter(fn ($t) => data_get($t->input, 'knowledge.0.source_id') === $source)->map(fn ($t) => ['question' => $t->input['question'] ?? '', 'answer' => mb_substr($t->result['answer'] ?? '', 0, 1200)])->all();
        $evidence['recipes'] = array_keys(app(MarketingWorkflowCatalog::class)->recipes());
        $measured = MarketingSocialPublication::forTenantId($tenantId)->where('status', 'published')->whereNotNull('observed_at')->latest('observed_at')->limit(5)->get();
        $measuredPosts = MarketingSocialPost::forTenantId($tenantId)->whereIn('id', $measured->pluck('post_id'))->get()->keyBy('id');
        $evidence['performance'] = $measured->filter(fn ($publication) => $measuredPosts->has($publication->post_id))->map(fn ($publication) => [
            'source_id' => 'post:'.$publication->post_id, 'platform' => $publication->platform,
            'format' => $measuredPosts[$publication->post_id]->content['media_type'],
            'caption' => mb_substr($measuredPosts[$publication->post_id]->content[$publication->platform.'_caption'] ?? $measuredPosts[$publication->post_id]->content['caption'], 0, 1200),
            'metrics' => array_intersect_key((array) $publication->insights, array_flip(['reach', 'likes', 'comments', 'saved', 'shares', 'post_impressions_unique', 'post_clicks'])),
            'period' => 'lifetime', 'observed_at' => $publication->observed_at->toIso8601String(),
        ])->values()->all();
        $turn = app(MarketingAiService::class)->generate($tenantId, $request->user(), $data['request_uuid'], $data['thread_uuid'], 'owner', $evidence);

        return $this->done($request, 'chat', ['status' => $turn->status, 'result' => $turn->result]);
    }

    public function savePost(Request $request, ?int $id = null)
    {
        $tenantId = $this->tenant($request);
        $input = $request->all();
        if (is_string($input['media_urls'] ?? null)) {
            $input['media_urls'] = array_values(array_filter(array_map('trim', preg_split('/\R/', $input['media_urls']))));
        }
        $request->validate(['media_files' => 'nullable|array|max:10', 'media_files.*' => 'file|mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime|max:102400']);
        foreach ($request->file('media_files', []) as $file) {
            $input['media_urls'][] = app(\App\Services\Marketing\Desk\MarketingMediaService::class)->store($tenantId, $file);
        }
        $post = app(SocialPostService::class)->save($tenantId, $request->user(), $input, $id);

        return $this->done($request, 'calendar', ['id' => $post->id, 'revision' => $post->revision]);
    }

    public function syncInstagram(Request $request, int $id)
    {
        $tenantId = $this->tenant($request);
        try {
            $count = app(InstagramPostSyncService::class)->sync($tenantId, $id, $request->user());
        } catch (\Illuminate\Http\Client\RequestException $exception) {
            return $request->expectsJson() ? response()->json(['message' => 'Instagram could not refresh these posts. Check the account permissions and try again.'], 503)
                : back()->withErrors(['instagram' => 'Instagram could not refresh these posts. Check the account permissions and try again. Saved posts remain available.']);
        }

        return $request->expectsJson() ? response()->json(['synced' => $count]) : redirect()->route('marketing.desk.index', ['view' => 'insights', 'platform' => 'instagram', 'ranking' => 'likes'])->with('toast', ['style' => 'success', 'message' => $count.' Instagram posts refreshed.']);
    }

    public function copyCaption(Request $request, int $id)
    {
        $tenantId = $this->tenant($request);
        $data = $request->validate(['platform' => 'nullable|in:instagram,facebook']);
        $original = MarketingSocialPost::forTenantId($tenantId)->findOrFail($id);
        $caption = $original->content[($data['platform'] ?? 'instagram').'_caption'] ?? $original->content['caption'];
        abort_if(trim($caption) === '', 422, 'This post has no caption to copy.');
        $draft = app(SocialPostService::class)->save($tenantId, $request->user(), ['caption' => $caption, 'media_type' => 'text', 'media_urls' => []]);

        return $this->done($request, 'calendar', ['id' => $draft->id]);
    }

    public function approvePost(Request $request, int $id)
    {
        $data = $request->validate(['revision' => 'required|integer', 'connections' => 'required|array|min:1|max:2', 'connections.*' => 'nullable|integer', 'scheduled_at' => 'required|date', 'confirmed' => 'accepted']);
        $tenantId = $this->tenant($request);
        $when = \Carbon\CarbonImmutable::parse($data['scheduled_at'], app(\App\Services\Marketing\Desk\MarketingWorkspaceClock::class)->timezone($tenantId));
        abort_if($when->isPast(), 422, 'Choose a future time in the workspace timezone.');
        $post = app(SocialPostService::class)->approve($tenantId, $id, $request->user(), $data['revision'], array_filter($data['connections']), $when->toIso8601String());

        return $this->done($request, 'calendar', ['id' => $post->id]);
    }

    public function batchApprovePosts(Request $request)
    {
        $tenantId = $this->tenant($request);
        $data = $request->validate([
            'confirmed' => 'accepted', 'posts' => 'required|array|min:1|max:20',
            'posts.*.id' => 'required|integer|distinct', 'posts.*.revision' => 'required|integer|min:1',
            'posts.*.selected' => 'nullable|boolean', 'posts.*.scheduled_at' => 'nullable|date',
            'posts.*.connections' => 'nullable|array|max:2', 'posts.*.connections.*' => 'nullable|integer',
        ]);
        $selected = array_values(array_filter($data['posts'], fn ($post) => (bool) ($post['selected'] ?? false)));
        abort_unless(count($selected) > 0, 422, 'Choose at least one draft to approve.');
        DB::transaction(function () use ($selected, $tenantId, $request): void {
            foreach ($selected as $post) {
                abort_unless(filled($post['scheduled_at'] ?? null) && count(array_filter($post['connections'] ?? [])) > 0, 422, 'Each selected draft needs a publish time and an account.');
                $when = \Carbon\CarbonImmutable::parse($post['scheduled_at'], app(\App\Services\Marketing\Desk\MarketingWorkspaceClock::class)->timezone($tenantId));
                abort_if($when->isPast(), 422, 'Choose future times in the workspace timezone.');
                app(SocialPostService::class)->approve($tenantId, $post['id'], $request->user(), $post['revision'], array_filter($post['connections']), $when->toIso8601String());
            }
        }, 3);

        return $this->done($request, 'calendar', ['approved' => count($selected)]);
    }

    public function insights(Request $request, int $id)
    {
        app(SocialPublisher::class)->refreshInsights($this->tenant($request), $id, $request->user());

        return $this->done($request, 'insights');
    }

    public function reconcilePublication(Request $request, int $id)
    {
        $tenantId = $this->tenant($request);
        $data = $request->validate(['provider_post_id' => 'required|string|max:150', 'confirmed' => 'accepted']);
        $publication = MarketingSocialPublication::forTenantId($tenantId)->where('status', 'unknown')->findOrFail($id);
        $post = MarketingSocialPost::forTenantId($tenantId)->findOrFail($publication->post_id);
        $client = app(\App\Services\Marketing\Desk\MetaMarketingClient::class);
        $connection = $client->connection($tenantId, $publication->connection_id, $publication->platform, 'publish');
        $path = $client->account($connection).($publication->platform === 'instagram' ? '/media' : '/published_posts');
        $evidence = $client->request($connection, 'GET', $path, ['fields' => $publication->platform === 'instagram' ? 'id,caption,timestamp' : 'id,message,created_time', 'limit' => 100]);
        $verified = collect($evidence['data'] ?? [])->firstWhere('id', $data['provider_post_id']);
        abort_unless($verified && trim((string) ($verified['caption'] ?? $verified['message'] ?? '')) === trim((string) ($post->content[$publication->platform.'_caption'] ?? $post->content['caption'])), 422, 'This post could not be verified in the connected account. Preserve the uncertain attempt.');
        $publication->update(['status' => 'published', 'external_id' => $data['provider_post_id'], 'failure_code' => null, 'insights' => [...(array) $publication->insights, '_reconciliation' => ['verified_by' => $request->user()->id, 'verified_at' => now()->toIso8601String(), 'provider_post_id' => $data['provider_post_id']]]]);
        if (! MarketingSocialPublication::forTenantId($tenantId)->where('post_id', $post->id)->where('revision', $post->revision)->where('status', '!=', 'published')->exists()) {
            $post->update(['status' => 'published']);
        }

        return $this->done($request, 'calendar');
    }

    public function budget(Request $request)
    {
        $tenantId = $this->tenant($request, true);
        $data = $request->validate(['cap_cents' => 'required|integer|min:0|max:5000', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($tenantId, $data, $request): void {
            $profile = \App\Models\TenantAccessProfile::where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();
            $metadata = (array) $profile->metadata;
            data_set($metadata, 'marketing_desk.ai_budget', ['cap_cents' => $data['cap_cents'], 'approved_by' => $request->user()->id, 'approved_at' => now()->toIso8601String()]);
            $profile->update(['metadata' => $metadata]);
        }, 3);

        return $this->done($request, 'settings');
    }

    public function timezone(Request $request)
    {
        $tenantId = $this->tenant($request, true);
        $data = $request->validate(['timezone' => 'required|string|timezone:all']);
        $profile = \App\Models\TenantAccessProfile::where('tenant_id', $tenantId)->firstOrFail();
        $metadata = (array) $profile->metadata;
        data_set($metadata, 'marketing_desk.timezone', $data['timezone']);
        $profile->update(['metadata' => $metadata]);

        return $this->done($request, 'settings');
    }

    public function policy(Request $request, ?int $id = null)
    {
        $tenantId = $this->tenant($request);
        $data = $request->validate(['channel' => 'required|in:email,sms,instagram,facebook', 'audience' => 'required|in:general,retail,wholesale,services', 'store_key' => 'nullable|string|max:80', 'knowledge_version_id' => 'required|integer', 'enabled' => 'required|boolean', 'confirmed' => 'accepted']);
        app(BrandKnowledgeService::class)->approved($tenantId, $data['knowledge_version_id'], $data['audience']);
        $this->verifyStore($tenantId, $data['store_key'] ?? '');
        $policy = $id ? MarketingReplyPolicy::forTenantId($tenantId)->findOrFail($id) : new MarketingReplyPolicy(['tenant_id' => $tenantId]);
        $policy->fill([...$data, 'store_key' => $data['store_key'] ?? '', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'revision' => ($policy->revision ?? 0) + 1]);
        unset($policy->confirmed);
        $policy->save();

        return $this->done($request, 'settings', ['id' => $policy->id]);
    }

    public function control(Request $request, int $id)
    {
        $tenantId = $this->tenant($request, staff: true);
        $conversation = MessagingConversation::forTenantId($tenantId)->findOrFail($id);
        $this->authorizeConversation($request, $conversation);
        $data = $request->validate(['mode' => 'required|in:human,auto', 'policy_id' => 'nullable|integer']);
        if ($data['mode'] === 'human') {
            app(ReplyAutomationService::class)->takeover($tenantId, $id);
        } else {
            $this->tenant($request);
            $policy = MarketingReplyPolicy::forTenantId($tenantId)->where('channel', $conversation->channel)->where('store_key', $conversation->store_key ?? '')->where('enabled', true)->findOrFail($data['policy_id'] ?? 0);
            app(BrandKnowledgeService::class)->approved($tenantId, $policy->knowledge_version_id, $policy->audience);
            MarketingConversationControl::updateOrCreate(['tenant_id' => $tenantId, 'conversation_id' => $id], ['mode' => 'auto', 'policy_id' => $policy->id, 'reason' => null]);
        }

        return $this->done($request, 'inbox');
    }

    public function reply(Request $request, int $id)
    {
        $tenantId = $this->tenant($request, staff: true);
        $conversation = MessagingConversation::forTenantId($tenantId)->findOrFail($id);
        $this->authorizeConversation($request, $conversation);
        $data = $request->validate(['body' => 'required|string|max:10000']);
        app(MessagingResponseInboxService::class)->reply($tenantId, $conversation->store_key, $id, $data, $request->user());

        return $this->done($request, 'inbox');
    }

    public function recipe(Request $request)
    {
        $tenantId = $this->tenant($request);
        $data = $request->validate(['recipe' => 'required|string']);
        abort_unless(isset(app(MarketingWorkflowCatalog::class)->recipes()[$data['recipe']]), 422);
        $workflow = app(WorkflowDraftService::class)->createFromTemplate($tenantId, $data['recipe'], $request->user());

        return $request->expectsJson() ? response()->json(['id' => $workflow->id, 'url' => route('workflows.show', $workflow)]) : redirect()->route('workflows.show', $workflow);
    }

    public function approveWorkflow(Request $request, int $id)
    {
        $tenantId = $this->tenant($request);
        $data = $request->validate(['definition_hash' => 'required|string|size:64', 'audience' => 'required|in:retail,wholesale,services', 'store_key' => 'nullable|string|max:80', 'confirmed' => 'accepted']);
        $this->verifyStore($tenantId, $data['store_key'] ?? '');
        $workflow = AutomationWorkflow::forTenantId($tenantId)->with('publishedVersion')->findOrFail($id);
        abort_unless($workflow->publishedVersion && hash_equals($workflow->publishedVersion->definition_hash, $data['definition_hash']), 409);
        abort_unless(data_get($workflow->publishedVersion->definition, 'trigger.config.audience') === $data['audience'] && (string) data_get($workflow->publishedVersion->definition, 'trigger.config.store_key', '') === (string) ($data['store_key'] ?? ''), 422, 'The approved audience and store must match the published trigger.');
        MarketingDeliveryAuthorization::updateOrCreate(['workflow_id' => $id, 'definition_hash' => $data['definition_hash']], ['tenant_id' => $tenantId, 'audience' => $data['audience'], 'store_key' => $data['store_key'] ?? '', 'active' => true, 'approved_by' => $request->user()->id, 'approved_at' => now()]);

        return $this->done($request, 'recipes');
    }

    public function permission(Request $request)
    {
        $tenantId = $this->tenant($request);
        $data = $request->validate(['profile_id' => 'required|integer', 'audience' => 'required|in:retail,wholesale,services', 'channel' => 'required|in:email,sms', 'store_key' => 'nullable|string|max:80', 'proof' => 'required|string|min:10|max:150', 'confirmed' => 'accepted']);
        $profile = MarketingProfile::forTenantId($tenantId)->findOrFail($data['profile_id']);
        $this->verifyStore($tenantId, $data['store_key'] ?? '');
        abort_unless($profile->getAttribute('accepts_'.$data['channel'].'_marketing') && ! $profile->getAttribute($data['channel'].'_opted_out_at'), 422, 'Record current channel consent through the customer consent tools first.');
        DB::transaction(function () use ($tenantId, $profile, $data, $request): void {
            $evidence = MarketingConsentEvent::create(['tenant_id' => $tenantId, 'marketing_profile_id' => $profile->id, 'channel' => $data['channel'], 'event_type' => 'audience_permission_reviewed', 'source_type' => 'marketing_desk', 'source_id' => (string) $request->user()->id, 'details' => ['audience' => $data['audience'], 'store_key' => $data['store_key'] ?? '', 'proof' => $data['proof']], 'occurred_at' => now()]);
            MarketingAudiencePermission::updateOrCreate(['tenant_id' => $tenantId, 'marketing_profile_id' => $profile->id, 'audience' => $data['audience'], 'channel' => $data['channel'], 'store_key' => $data['store_key'] ?? ''], ['proof_key' => 'consent-event:'.$evidence->id, 'granted_at' => now(), 'revoked_at' => null]);
            app(MarketingSignalService::class)->record($tenantId, $profile, $data['audience'], 'subscription', 'consent-event:'.$evidence->id, $data['store_key'] ?? '');
        });

        return $this->done($request, 'settings');
    }

    public function milestone(Request $request)
    {
        $tenantId = $this->tenant($request);
        $data = $request->validate(['profile_id' => 'required|integer', 'audience' => 'required|in:retail,wholesale,services', 'event' => 'required|in:segment,inquiry,fulfilled,sample_delivered,completed', 'store_key' => 'nullable|string|max:80', 'proof' => 'required|string|min:10|max:150', 'prospect_id' => 'nullable|integer', 'confirmed' => 'accepted']);
        $this->verifyStore($tenantId, $data['store_key'] ?? '');
        app(MarketingSignalService::class)->record($tenantId, MarketingProfile::forTenantId($tenantId)->findOrFail($data['profile_id']), $data['audience'], $data['event'], $data['proof'], $data['store_key'] ?? '', $data['prospect_id'] ?? null);

        return $this->done($request, 'recipes');
    }

    private function verifyStore(int $tenantId, string $key): void
    {
        if ($key !== '') {
            abort_unless(\App\Models\ShopifyStore::forTenantId($tenantId)->where('store_key', $key)->exists(), 422, 'Choose a store owned by this workspace.');
        }
    }

    private function authorizeConversation(Request $request, MessagingConversation $conversation): void
    {
        $role = $request->user()->tenants()->whereKey($conversation->tenant_id)->first()->pivot->role;
        if (in_array($role, ['member', 'employee'], true)) {
            abort_unless((int) $conversation->assigned_to === (int) $request->user()->id, 403);
        }
    }
}
