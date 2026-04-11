<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UiPreferencesController extends Controller
{
    private const SIDEBAR_KEY_MIGRATIONS = [
        'operations' => 'production',
        'shipping-room' => 'production',
        'pouring-room' => 'production',
        'retail-plan' => 'production',
        'pour-lists' => 'production',
        'events' => 'production',
        'markets' => 'production',
    ];

    private const THEMES = [
        'forestry-green',
        'sugar-and-spice',
        'get-shit-done',
        'steve-jobs',
    ];

    public function update(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }

        $prefs = is_array($user->ui_preferences) ? $user->ui_preferences : [];

        $prefs['wide_layout'] = $request->boolean('wide_layout');
        $prefs['compact_tables'] = $request->boolean('compact_tables');

        $user->forceFill(['ui_preferences' => $prefs])->save();

        return back()->with('status', 'Preferences updated.');
    }

    public function updateSidebarOrder(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }

        $validated = $request->validate([
            'sidebar_order' => ['required', 'array'],
            'sidebar_order.*' => ['string', 'max:100'],
        ]);

        $prefs = is_array($user->ui_preferences) ? $user->ui_preferences : [];
        $prefs['sidebar_order'] = $this->normalizedSidebarOrder((array) $validated['sidebar_order']);

        $user->forceFill(['ui_preferences' => $prefs])->save();

        return response()->json(['ok' => true]);
    }

    public function updateTheme(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }

        $validated = $request->validate([
            'theme' => ['required', 'string', 'in:'.implode(',', self::THEMES)],
        ]);

        $prefs = is_array($user->ui_preferences) ? $user->ui_preferences : [];
        $prefs['theme'] = $validated['theme'];

        $user->forceFill(['ui_preferences' => $prefs])->save();

        return response()->json(['ok' => true, 'theme' => $validated['theme']]);
    }

    /**
     * @param  array<int,mixed>  $sidebarOrder
     * @return array<int,string>
     */
    private function normalizedSidebarOrder(array $sidebarOrder): array
    {
        $normalized = [];
        foreach ($sidebarOrder as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $key = strtolower(trim($candidate));
            if ($key === '') {
                continue;
            }

            $mapped = self::SIDEBAR_KEY_MIGRATIONS[$key] ?? $key;
            if (! in_array($mapped, $normalized, true)) {
                $normalized[] = $mapped;
            }
        }

        return $normalized;
    }
}
