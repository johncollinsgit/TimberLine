<?php

namespace App\Http\Controllers;

use App\Models\DevelopmentChangeLog;
use App\Models\DevelopmentNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminDevelopmentNotesController extends Controller
{
    public function index(): View
    {
        $projectNotes = DevelopmentNote::query()
            ->with(['creator:id,name,email', 'updater:id,name,email'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        $changeLogs = DevelopmentChangeLog::query()
            ->with(['creator:id,name,email'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return view('admin.development-notes.index', [
            'projectNotes' => $projectNotes,
            'changeLogs' => $changeLogs,
        ]);
    }

    public function storeNote(Request $request): RedirectResponse
    {
        $validated = $this->validateNotePayload($request);

        DevelopmentNote::query()->create([
            'title' => trim((string) $validated['title']),
            'body' => trim((string) $validated['body']),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('admin.development-notes.index')
            ->with('status', 'Project note added.');
    }

    public function updateNote(Request $request, DevelopmentNote $developmentNote): RedirectResponse
    {
        $validated = $this->validateNotePayload($request);

        $developmentNote->update([
            'title' => trim((string) $validated['title']),
            'body' => trim((string) $validated['body']),
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('admin.development-notes.index')
            ->with('status', 'Project note updated.');
    }

    public function destroyNote(DevelopmentNote $developmentNote): RedirectResponse
    {
        $developmentNote->delete();

        return redirect()
            ->route('admin.development-notes.index')
            ->with('status', 'Project note deleted.');
    }

    public function storeChangeLog(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'summary' => ['required', 'string', 'max:8000'],
            'area' => ['nullable', 'string', 'max:180'],
        ]);

        DevelopmentChangeLog::query()->create([
            'title' => trim((string) $validated['title']),
            'summary' => trim((string) $validated['summary']),
            'area' => trim((string) ($validated['area'] ?? '')) ?: null,
            'created_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('admin.development-notes.index')
            ->with('status', 'Change log entry added.');
    }

    /**
     * @return array<string, string>
     */
    protected function validateNotePayload(Request $request): array
    {
        /** @var array<string, string> $validated */
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        return $validated;
    }
}
