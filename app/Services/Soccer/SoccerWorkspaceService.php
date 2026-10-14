<?php

namespace App\Services\Soccer;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;

class SoccerWorkspaceService
{
    public function navigation(Request $r, Tenant $t, User $u, array $profile): array
    {
        $a = app(SoccerAccess::class);
        $admin = $a->admin($t, $u);
        $coach = $a->coach($t, $u);
        $items = [];
        $labels = $admin ? ['home' => 'Home', 'parents' => 'Parents', 'players' => 'Players', 'coaches' => 'Coaches', 'teams' => 'Teams', 'messages' => 'Messages', 'website' => 'Website', 'settings' => 'Settings'] : ($coach ? ['home' => 'Home', 'players' => 'Players', 'coaches' => 'My jersey', 'teams' => 'Teams', 'messages' => 'Messages'] : []);
        $icons = ['home' => 'home', 'parents' => 'users', 'players' => 'user-group', 'coaches' => 'user-circle', 'teams' => 'flag', 'messages' => 'chat-bubble-left-right', 'website' => 'globe-alt', 'settings' => 'cog-6-tooth'];
        foreach ($labels as $key => $label) {
            $item = ['key' => 'soccer-'.$key, 'label' => $label, 'icon' => $icons[$key], 'href' => route('soccer.workspace', ['section' => $key, 'tenant' => $t->slug]), 'current' => $r->routeIs('soccer.workspace') && $r->route('section', 'home') === $key];
            if ($key === 'teams') {
                $keys = $admin ? array_keys(config('soccer.teams')) : json_decode($coach->team_keys, true);
                $item['children'] = array_map(fn ($k) => ['key' => 'soccer-team-'.$k, 'label' => config('soccer.teams.'.$k.'.name'), 'href' => route('soccer.workspace', ['section' => 'teams', 'team' => $k, 'tenant' => $t->slug]), 'current' => $r->query('team') === $k], $keys);
            } $items[] = $item;
        }

        return ['tenant' => $t, 'tenant_id' => $t->id, 'experience_profile' => $profile, 'items' => $items, 'admin_sub_items' => [], 'marketing_sub_groups' => [], 'birthday_sub_groups' => [], 'wiki_sections' => [], 'quick_actions' => [], 'ops_attention' => ['unresolved_exceptions' => 0, 'latest_run' => null], 'current_console' => ['label' => $t->name, 'href' => route('soccer.workspace', ['tenant' => $t->slug])], 'console_switches' => [], 'shell_context' => 'tenant'];
    }

    public function search(string $q, array $context): array
    {
        $t = Tenant::find($context['tenant_id']);
        $u = $context['user'] ?? null;
        $r = $context['request'] ?? request();
        $results = [];
        if ($t && $u && app(SoccerAccess::class)->enabled($t)) {
            foreach ($this->navigation($r, $t, $u, [])['items'] as $i) {
                if ($q === '' || str_contains(strtolower($i['label']), strtolower($q))) {
                    $results[] = ['id' => $i['key'], 'type' => 'navigation', 'title' => $i['label'], 'label' => $i['label'], 'url' => $i['href'], 'score' => 100];
                }
            }
        }

        return ['query' => $q, 'total' => count($results), 'results' => $results, 'groups' => ['navigation' => $results], 'empty_state' => $results ? null : ['title' => 'No soccer destination found', 'subtitle' => 'Search Parents, Players, Coaches, Teams, or Messages.']];
    }
}
