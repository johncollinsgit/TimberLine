<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UiPreferencesController extends Controller
{
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
        $prefs['sidebar_order'] = array_values(array_unique($validated['sidebar_order']));

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
}
