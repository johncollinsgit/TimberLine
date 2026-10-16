<?php

namespace App\Services\Navigation;

use App\Models\User;
use App\Services\Search\Providers\NavigationSearchProvider;
use Illuminate\Http\Request;

/** Projects existing authorized navigation; it never grants access. */
class VoiceNavigationRegistry
{
    public function mobile(Request $request, User $user, array $navigation, array $modules, array $branding): array
    {
        $pages = [];
        $add = function (string $key, string $title, array $destination, array $aliases = []) use (&$pages): void {
            $pages[] = ['key' => $key, 'title' => $title, 'aliases' => array_values(array_unique([mb_strtolower($title), ...$aliases])), 'availability' => 'native', 'destination' => $destination];
        };
        foreach ($navigation as $item) {
            if ($item['key'] !== 'more') {
                $add('tab:'.$item['key'], $item['label'], ['kind' => 'tab', 'tab' => $item['key']], $this->aliases($item['key']));
            }
        }
        foreach (['account' => 'Account', 'branches' => 'Branches', 'search' => 'Search'] as $key => $title) {
            if (! in_array('tab:'.$key, array_column($pages, 'key'), true)) {
                $add('tab:'.$key, $title, ['kind' => 'tab', 'tab' => $key], $this->aliases($key));
            }
        }
        foreach ($modules as $module) {
            $add('module:'.$module['module_key'], $module['display_name'], ['kind' => $module['module_key']], $this->aliases($module['module_key']));
        }
        foreach (['support' => ['Help and support', ['help', 'support', 'tickets']], 'sessions' => ['Devices and sessions', ['devices', 'sessions']]] as $section => [$title, $aliases]) {
            $add('account:'.$section, $title, ['kind' => 'tab', 'tab' => 'account', 'section' => $section], $aliases);
        }
        if ($branding['can_manage'] ?? false) {
            $add('account:branding', 'Workspace branding', ['kind' => 'tab', 'tab' => 'account', 'section' => 'branding'], ['branding', 'company logo', 'company name']);
        }
        if (in_array('time_tracking', array_column($modules, 'module_key'), true)) {
            $add('tab:hours', 'My hours', ['kind' => 'tab', 'tab' => 'hours'], ['timesheet', 'hours']);
        }
        $nativeTitles = array_map(fn ($page) => mb_strtolower($page['title']), $pages);
        foreach (app(NavigationSearchProvider::class)->search('', ['request' => $request, 'user' => $user]) as $row) {
            if (! in_array(mb_strtolower($row['title']), $nativeTitles, true)) {
                $pages[] = ['key' => 'web:'.hash('sha256', $row['url']), 'title' => $row['title'], 'aliases' => $row['meta']['aliases'] ?? [], 'availability' => 'unavailable', 'reason' => 'This page is available in the authenticated website. A native destination is not available.', 'web_url' => $row['url']];
            }
        }

        return ['version' => 1, 'pages' => $pages];
    }

    private function aliases(string $key): array
    {
        return match ($key) {
            'home' => ['dashboard', 'overview'], 'work', 'work_core' => ['jobs', 'projects', 'work'],
            'field_service' => ['jobs', 'projects', 'work', 'schedule', 'job calendar'],
            'field_service_calendar', 'class_scheduling' => ['schedule', 'calendar'],
            'customers' => ['clients', 'contacts'], 'team' => ['employees', 'staff', 'people'],
            'clock' => ['timer', 'clock in', 'clock out'], 'account' => ['settings', 'preferences', 'security', 'appearance', 'notifications'],
            'branches' => ['modules', 'features'], 'documents' => ['files', 'photos'], 'reporting' => ['reports'],
            'fleet_tracking' => ['fleet', 'vehicles', 'vans', 'crew map'], 'estimator' => ['estimates', 'estimate drafts'],
            default => [],
        };
    }
}
