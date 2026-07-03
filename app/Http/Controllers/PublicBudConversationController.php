<?php

namespace App\Http\Controllers;

use App\Mail\PublicBudConversationMail;
use App\Models\ServiceInquiry;
use App\Services\Bud\BudConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PublicBudConversationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'string', 'max:120'],
            'source_page' => ['nullable', 'string', 'max:120'],
            'page_url' => ['nullable', 'url', 'max:300'],
            'question' => ['required', 'string', 'max:5000'],
            'reply' => ['nullable', 'string', 'max:5000'],
            'transcript' => ['nullable', 'array'],
            'transcript.*.role' => ['nullable', 'string', 'max:40'],
            'transcript.*.text' => ['nullable', 'string', 'max:5000'],
            'context' => ['nullable', 'array'],
        ]);

        $conversation = app(BudConversationService::class)->respond(
            (string) ($validated['question'] ?? ''),
            is_array($validated['context'] ?? null) ? (array) $validated['context'] : [],
            is_array($validated['transcript'] ?? null) ? (array) $validated['transcript'] : []
        );

        $sourcePage = $this->nullableText($validated['source_page'] ?? 'everbranch_promo_bud', 120)
            ?? 'everbranch_promo_bud';
        $pageUrl = $this->nullableText($validated['page_url'] ?? null, 300);
        $question = $this->text($validated['question'] ?? '', 5000);
        $reply = $this->text((string) ($conversation['reply'] ?? ''), 5000);
        $context = is_array($validated['context'] ?? null) ? $validated['context'] : [];
        $transcript = $this->cleanTranscript($validated['transcript'] ?? []);

        $inquiry = ServiceInquiry::query()->create([
            'name' => 'Bud Visitor',
            'email' => 'bud-chat@theeverbranch.com',
            'company' => $this->nullableText($this->contextCompany($context), 190),
            'website' => $pageUrl,
            'current_tools' => $this->nullableText($this->contextTools($context), 1000),
            'pain_point' => $this->nullableText("Question: {$question}\n\nBud reply: {$reply}", 5000),
            'source_page' => $sourcePage,
            'calculator_payload' => [
                'conversation_id' => $this->text($validated['conversation_id'] ?? '', 120),
                'page_url' => $pageUrl,
                'context' => $context,
                'transcript' => $transcript,
            ],
            'status' => 'new',
        ]);

        $recipient = trim((string) config('everbranch.bud.support_email', ''));

        if ($recipient !== '') {
            Mail::to($recipient)->send(new PublicBudConversationMail($inquiry, $question, $reply, $context, $transcript));
        }

        return response()->json([
            'ok' => true,
            'conversation_id' => $validated['conversation_id'],
            'support_email' => $recipient,
            'reply' => $reply,
            'confidence' => $conversation['confidence'] ?? 'medium',
            'uncertain' => (bool) ($conversation['uncertain'] ?? false),
            'follow_up' => $conversation['follow_up'] ?? null,
        ], 201);
    }

    /**
     * @param  array<int, array<string, mixed>>  $transcript
     * @return array<int, array{role:string,text:string}>
     */
    protected function cleanTranscript(array $transcript): array
    {
        return collect($transcript)
            ->map(function (mixed $row): ?array {
                if (! is_array($row)) {
                    return null;
                }

                $role = $this->text($row['role'] ?? 'unknown', 40);
                $text = $this->text($row['text'] ?? '', 5000);

                if ($text === '') {
                    return null;
                }

                return [
                    'role' => $role !== '' ? $role : 'unknown',
                    'text' => $text,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function contextCompany(array $context): ?string
    {
        $scenario = $this->nullableText($context['scenario'] ?? null, 80);

        if ($scenario === null) {
            return 'Everbranch promo chat';
        }

        return Str::headline($scenario.' promo chat');
    }

    protected function contextTools(array $context): ?string
    {
        $parts = collect([
            $this->nullableText($context['source'] ?? null, 80),
            $this->nullableText($context['scenario'] ?? null, 80),
            $this->nullableText($context['pane'] ?? null, 80),
            $this->nullableText($context['type'] ?? null, 120),
            $this->nullableText($context['customer'] ?? null, 120),
        ])->filter()->values();

        return $parts->isEmpty() ? null : $parts->join(' | ');
    }

    protected function text(mixed $value, int $limit): string
    {
        return Str::limit(trim((string) $value), $limit, '');
    }

    protected function nullableText(mixed $value, int $limit): ?string
    {
        $text = $this->text($value, $limit);

        return $text !== '' ? $text : null;
    }
}
