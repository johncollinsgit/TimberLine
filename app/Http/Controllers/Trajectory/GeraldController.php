<?php

namespace App\Http\Controllers\Trajectory;

use App\Http\Controllers\Controller;
use App\Jobs\Trajectory\ReviewFinances;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Services\Trajectory\FinanceAccess;
use App\Services\Trajectory\FinanceAiService;
use App\Services\Trajectory\FinanceAuditService;
use App\Services\Trajectory\GeraldAudioProvider;
use App\Services\Trajectory\GeraldService;
use App\Services\Trajectory\WorkspaceService;
use Illuminate\Http\Request;

class GeraldController extends Controller
{
    private function json(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function state(Request $request, Space $space)
    {
        app(FinanceAccess::class)->authorize($request->user(), $space);
        $page = max(1, min(100000, (int) $request->query('page', 1)));
        $query = Record::where('space_id', $space->id)->where('kind', 'finance_audit_finding');
        $findings = (clone $query)->latest('id')->skip(($page - 1) * 50)->take(50)->get();
        $versions = Transaction::where('space_id', $space->id)->whereIn('id', $findings->map(fn ($r) => $r->data['transaction_id']))->get()->keyBy('id');
        $latest = Record::where('space_id', $space->id)->where('kind', 'finance_audit')->latest('id')->first();

        return $this->json(['ai' => app(FinanceAiService::class)->presentation($request->user(), $space), 'preferences' => $space->settings['gerald_review'] ?? ['auto_categorize' => false, 'after_sync' => false],
            'audit' => $latest ? ['id' => $latest->id, ...array_diff_key($latest->data, array_flip(['checked', 'actor_id']))] : null,
            'findings' => $findings->map(fn ($r) => ['id' => $r->id, ...$r->data, 'stale' => ! isset($versions[$r->data['transaction_id']]) || $versions[$r->data['transaction_id']]->version !== ($r->data['applied_version'] ?? $r->data['transaction_version'])])->all(),
            'page' => $page, 'has_more' => $query->count() > $page * 50]);
    }

    public function settings(Request $request, Space $space)
    {
        app(FinanceAiService::class)->configure($request->user(), $space, $request->all());

        return $this->json(['saved' => true]);
    }

    public function preferences(Request $request, Space $space)
    {
        $data = $request->validate(['auto_categorize' => 'required|boolean', 'after_sync' => 'required|boolean', 'confirmed' => 'accepted']);
        app(WorkspaceService::class)->manage($request->user(), $space);
        // Opt-in cannot activate without the existing AI allowance and consent.
        if ($data['auto_categorize'] || $data['after_sync']) {
            app(FinanceAiService::class)->authorize($request->user(), $space);
        }
        app(WorkspaceService::class)->save($request->user(), $space, ['gerald_review' => ['auto_categorize' => $data['auto_categorize'], 'after_sync' => $data['after_sync'], 'actor_id' => $request->user()->id]]);

        return $this->json(['saved' => true]);
    }

    public function ask(Request $request, Space $space)
    {
        return $this->json(app(GeraldService::class)->ask($request->user(), $space, $request->all()));
    }

    public function startAudit(Request $request, Space $space)
    {
        $audit = app(FinanceAuditService::class)->start($request->user(), $space);

        return $this->json(['id' => $audit->id, 'status' => $audit->data['status']]);
    }

    public function resumeAudit(Request $request, Space $space, int $audit)
    {
        app(FinanceAccess::class)->authorize($request->user(), $space);
        $record = Record::where('space_id', $space->id)->where('kind', 'finance_audit')->findOrFail($audit);
        abort_unless($record->data['status'] === 'paused', 409, 'Only a paused review can be resumed.');
        $record->update(['data' => [...$record->data, 'actor_id' => $request->user()->id, 'status' => 'queued', 'format_retry' => ($record->data['format_retry'] ?? 0) + (str_contains($record->data['reason'] ?? '', 'invalid review format') ? 1 : 0), 'reason' => null]]);
        ReviewFinances::dispatch($record->id);

        return $this->json(['queued' => true]);
    }

    public function applyAudit(Request $request, Space $space)
    {
        $data = $request->validate(['finding_ids' => 'required|array|min:1|max:100', 'finding_ids.*' => 'required|integer|distinct', 'category' => 'nullable|string|max:40', 'confirmed' => 'accepted']);

        return $this->json(['updated_ids' => app(FinanceAuditService::class)->apply($request->user(), $space, $data['finding_ids'], $data['category'] ?? null)]);
    }

    public function confirm(Request $request, Space $space, int $proposal)
    {
        $data = $request->validate(['confirmed' => 'sometimes|boolean', 'transcription_receipt' => 'nullable|uuid']);
        abort_unless(($data['confirmed'] ?? false) || isset($data['transcription_receipt']), 422);

        return $this->json(app(GeraldService::class)->confirm($request->user(), $space, $proposal, $data['confirmed'] ?? false, $data['transcription_receipt'] ?? null));
    }

    public function transcribe(Request $request, Space $space)
    {
        $data = $request->validate(['audio' => 'required|file|max:1500', 'request_id' => 'required|uuid', 'proposal_id' => 'nullable|integer']);
        if (isset($data['proposal_id'])) {
            $proposal = Record::where('space_id', $space->id)->where('kind', 'gerald_proposal')->findOrFail($data['proposal_id']);
            abort_unless($proposal->data['actor_id'] === $request->user()->id && $proposal->data['status'] === 'pending' && $proposal->data['expires_at'] > now()->timestamp, 404);
        }
        $wav = file_get_contents($request->file('audio')->getRealPath());
        $seconds = app(GeraldAudioProvider::class)->duration($wav);
        $result = app(FinanceAiService::class)->run($request->user(), $space, 'transcription', $data['request_id'], ['audio_hash' => hash('sha256', $wav), 'proposal_id' => $data['proposal_id'] ?? null], function () use ($wav, $data) {
            $r = app(GeraldAudioProvider::class)->transcribe($wav);

            return [...$r, 'value' => ['transcript' => $r['transcript'], 'receipt' => $data['request_id'], 'proposal_id' => $data['proposal_id'] ?? null]];
        }, ['model' => 'gpt-transcribe', 'reserve_micros' => (int) ceil((ceil($seconds) + 1) * config('trajectory.audio_rates.transcription_micros_per_minute') / 60)]);

        return $this->json($result);
    }

    public function speak(Request $request, Space $space)
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'conversation_id' => 'required|integer', 'message_request_id' => 'required|uuid', 'business_id' => 'nullable|integer', 'proposal_id' => 'nullable|integer']);
        $gerald = app(GeraldService::class);
        $scope = $gerald->scope($request->user(), $space, $data['business_id'] ?? null);
        $conversation = $gerald->conversation($request->user(), $space, $data['conversation_id'], $scope);
        $message = collect($conversation->data['messages'])->first(fn ($m) => $m['role'] === 'assistant' && ($m['request_id'] ?? '') === $data['message_request_id']);
        abort_unless($message, 404);
        $text = mb_substr($message['text'], 0, 650);
        if (isset($data['proposal_id'])) {
            $proposal = Record::where('space_id', $space->id)->where('kind', 'gerald_proposal')->findOrFail($data['proposal_id']);
            abort_unless($proposal->data['actor_id'] === $request->user()->id && $proposal->data['status'] === 'pending' && $proposal->data['expires_at'] > now()->timestamp, 404);
            $text = $proposal->data['readback'];
        }
        $rates = config('trajectory.audio_rates');
        $reserve = (int) ceil((8000 * $rates['speech_text_input_per_million'] + 2048 * max($rates['speech_text_output_per_million'], $rates['speech_audio_output_per_million'])) / 1000000);
        $result = app(FinanceAiService::class)->run($request->user(), $space, 'speech', $data['request_id'], ['message_request_id' => $data['message_request_id'], 'text' => $text, 'scope_ids' => array_map(fn ($s) => $s->id, $scope)], fn () => app(GeraldAudioProvider::class)->speak($text), ['model' => GeraldAudioProvider::SPEECH_MODEL, 'reserve_micros' => $reserve]);
        $gerald->scope($request->user(), $space->fresh(), $data['business_id'] ?? null);

        return $this->json($result);
    }
}
