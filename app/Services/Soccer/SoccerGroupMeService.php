<?php

namespace App\Services\Soccer;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class SoccerGroupMeService
{
    public function __construct(private SoccerStore $store) {}

    private function api(string $token)
    {
        return Http::withHeaders(['X-Access-Token' => $token])->acceptJson()->timeout(15)->baseUrl('https://api.groupme.com/v3');
    }

    public function connect(int $tenant, User $user, string $token): void
    {
        $r = $this->api($token)->get('/users/me');
        abort_unless($r->successful() && $r->json('response.id'), 422, 'GroupMe could not verify this token.');
        \Illuminate\Support\Facades\DB::transaction(function () use ($tenant, $user, $r, $token) {
            $this->store->query('groupme_connections', $tenant)->where('user_id', $user->id)->delete();
            $this->store->insert('groupme_connections', $tenant, ['user_id' => $user->id, 'provider_user_id' => $r->json('response.id'), 'token' => Crypt::encryptString($token)]);
        });
        $tenantModel = \App\Models\Tenant::findOrFail($tenant);
        if (app(SoccerAccess::class)->admin($tenantModel, $user)) {
            $settings = $this->store->settings($tenant);
            $ids = $settings['groupme_groups'] ?? [];
            if (count($ids) === 3) {
                foreach ($ids as $id) {
                    $this->group($tenant, $user, $id);
                }
                $settings['verified_group_ids'] = $ids;
                $settings['verified_group_user_id'] = $user->id;
                $this->store->saveSettings($tenant, $settings);
            }
        }
    }

    public function allowed(int $tenant, User $user, ?object $coach, bool $admin): array
    {
        $all = $this->store->settings($tenant)['groupme_groups'] ?? [];

        return array_filter($all, fn ($id, $key) => $admin || ($coach && in_array($key, $coach->role === 'head' ? ['soccer', 'all_coaches', 'head_coaches'] : ['soccer', 'all_coaches'], true)), ARRAY_FILTER_USE_BOTH);
    }

    private function token(int $tenant, User $user): string
    {
        $c = $this->store->query('groupme_connections', $tenant)->where('user_id', $user->id)->first();
        abort_unless($c, 422, 'Connect your own GroupMe account first.');

        return Crypt::decryptString($c->token);
    }

    public function group(int $tenant, User $user, string $group): array
    {
        $r = $this->api($this->token($tenant, $user))->get('/groups/'.rawurlencode($group));
        abort_unless($r->successful(), 403, 'Your GroupMe account must belong to this group.');

        $groupData = $r->json('response');
        $connection = $this->store->query('groupme_connections', $tenant)->where('user_id', $user->id)->firstOrFail();
        abort_unless(collect($groupData['members'] ?? [])->contains(fn ($member) => (string) ($member['user_id'] ?? '') === (string) $connection->provider_user_id), 403, 'Your GroupMe account must belong to this group.');

        return $groupData;
    }

    public function messages(int $tenant, User $user, string $group, ?string $before = null): array
    {
        $this->group($tenant, $user, $group);
        $r = $this->api($this->token($tenant, $user))->get('/groups/'.rawurlencode($group).'/messages', array_filter(['limit' => 100, 'before_id' => $before]));
        abort_unless($r->successful() || $r->status() === 304, 502, 'GroupMe messages are temporarily unavailable.');
        $messages = $r->json('response.messages') ?? [];
        $service = app(\App\Services\Marketing\MessagingConversationService::class);
        $conversation = \App\Models\MessagingConversation::query()->forTenantId($tenant)->where('channel', 'groupme')->where('source_id', $group)->first();
        if (! $conversation) {
            $conversation = \App\Models\MessagingConversation::create(['tenant_id' => $tenant, 'channel' => 'groupme', 'source_type' => 'soccer_groupme', 'source_id' => $group, 'status' => 'open', 'subject' => 'GroupMe', 'source_context' => ['group_id' => $group]]);
        }
        foreach (array_reverse($messages) as $m) {
            $service->appendMessage($conversation, ['channel' => 'groupme', 'direction' => 'inbound', 'provider' => 'groupme', 'provider_message_id' => $m['id'], 'dedupe_hash' => hash('sha256', 'groupme:'.$tenant.':'.$group.':'.$m['id']), 'body' => $m['text'] ?? '', 'from_identity' => $m['name'] ?? 'GroupMe', 'received_at' => \Carbon\Carbon::createFromTimestamp($m['created_at']), 'metadata' => ['attachments' => $m['attachments'] ?? [], 'source_type' => 'soccer_groupme']]);
        }

        return $messages;
    }

    public function send(int $tenant, User $user, string $group, string $text, string $requestId): ?string
    {
        $this->group($tenant, $user, $group);
        $r = $this->api($this->token($tenant, $user))->post('/groups/'.rawurlencode($group).'/messages', ['message' => ['source_guid' => $requestId, 'text' => $text]]);
        abort_unless($r->successful() || $r->status() === 409, 502, 'GroupMe could not post the message.');

        return $r->json('response.message.id');
    }
}
