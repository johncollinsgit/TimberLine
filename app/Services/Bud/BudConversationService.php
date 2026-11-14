<?php

namespace App\Services\Bud;

use Illuminate\Support\Str;

class BudConversationService
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, array<string, mixed>>  $transcript
     * @return array{
     *     reply:string,
     *     confidence:string,
     *     uncertain:bool,
     *     follow_up:string|null
     * }
     */
    public function respond(string $question, array $context = [], array $transcript = []): array
    {
        $questionText = trim($question);
        $normalized = $this->normalize($questionText);
        $scenario = $this->normalize((string) ($context['scenario'] ?? ''));
        $pane = $this->normalize((string) ($context['pane'] ?? ''));
        $type = trim((string) ($context['type'] ?? ''));
        $customer = trim((string) ($context['customer'] ?? ''));
        $topic = $this->topicFromTranscript($transcript) ?: $this->topicFromQuestion($normalized);
        $workspaceSummary = is_array($context['workspace_summary'] ?? null) ? $context['workspace_summary'] : [];

        if ($questionText === '') {
            return $this->reply(
                'Ask me about Everbranch, the demo workspace, or how a specific business process could live in one place.',
                confidence: 'low',
                uncertain: true,
                followUp: 'Try asking what Bud would do first, or what Everbranch is best at.',
            );
        }

        if ($this->isUnclearOrUnknown($normalized)) {
            return $this->reply(
                'I’m not sure yet, and I don’t want to make that up. I can explain the parts of Everbranch I know, or help narrow it down if you tell me which screen, workflow, or business process you mean.',
                confidence: 'low',
                uncertain: true,
                followUp: 'If you want, give me the customer, job, or task and I’ll try again with more context.',
            );
        }

        if ($this->matches($normalized, ['connect a domain', 'connect my domain', 'custom domain', 'dns', 'domain setup'])) {
            return $this->reply('Open Website → Domains. Publish to your Everbranch address first, then choose Connect my domain. Add the displayed DNS records and check the connection. Your domain becomes live after ownership, routing, and its security certificate are verified. Choose Handle this for me if you need help.', confidence: 'high', followUp: 'Already connected? Tell me which connection step needs attention.');
        }

        if ($this->matches($normalized, ['publish my website', 'publish a website', 'build a website', 'website setup', 'create a website'])) {
            return $this->reply('Start in Website: choose a template, replace the photos and content, then preview on desktop and mobile. Save a draft before publishing. Your Everbranch address is the first publishing option; custom domains and selling need their own completed setup.', confidence: 'high', followUp: 'You can prepare your pages while payment or domain connections are being completed.');
        }

        if ($this->matches($normalized, ['choose branches', 'which branches', 'branch cost', 'included branches'])) {
            return $this->reply('Core branches are included with Everbranch Simple. Choose a few that match your work, review their dependencies, and add more as you need them. Teams, Trajectory, and Markets & Makers have separate pricing and agreements. Text, email, and AI usage can have additional charges.', confidence: 'high', followUp: 'Explore Branches to compare each branch and its setup requirements.');
        }

        if ($this->matches($normalized, ['onboarding', 'setup help', 'getting started', 'set up my business'])) {
            return $this->reply('Start with your business details, choose your branches, and review the purchase summary. Then complete the setup steps in your workspace. Start with one useful result: a customer record, a job, or a website draft. Optional integrations can be connected when you need them.', confidence: 'high', followUp: 'Tell me whether you are working on branches, your website, a domain, or payments.');
        }

        if ($this->matches($normalized, ['what is everbranch', 'what is this', 'what does everbranch do', 'tell me about everbranch'])) {
            return $this->reply(
                'Everbranch is a workspace for small businesses that keeps customers, jobs, tasks, files, reminders, and team context together so the next step does not get lost in a text thread, inbox, or notebook.',
                confidence: 'high',
                followUp: 'If you want, I can also show you what that looks like for retail, trades, projects, or service work.',
            );
        }

        if ($this->matches($normalized, ['who is it best for', 'who is everbranch for', 'best for', 'what kind of business'])) {
            return $this->reply(
                'It’s a better fit for businesses with a lot of moving parts: retail teams, service shops, trades, project work, and owner-led operations that need one place for notes, follow-ups, and accountability.',
                confidence: 'high',
                followUp: 'If you tell me your business type, I can translate that into a more specific example.',
            );
        }

        if ($this->matches($normalized, ['could it help my business', 'would this help', 'how could it help', 'how could this help', 'how can this help', 'could this help', 'what problem does it solve'])) {
            $customerPhrase = $customer !== '' ? " For {$customer}," : '';

            return $this->reply(
                "Probably, if the pain is scattered details.{$customerPhrase} Everbranch helps when customer notes, job notes, follow-ups, and next steps are spread across texts, emails, spreadsheets, paper notes, and memory. It pulls that into one operating record so people know what happened, what matters now, and what to do next.",
                confidence: 'high',
                followUp: $customer !== '' ? "If you want, I can explain how that would look for {$customer}." : 'Tell me what you keep losing today and I’ll map it to the demo.',
            );
        }

        if ($this->matches($normalized, ['what can you do', 'what can bud do', 'what do you help with', 'what would bud do', 'help me organize first', 'organize first'])) {
            return $this->reply(
                'I’d start by naming the places where work disappears: customer questions, job notes, follow-ups, task ownership, and whatever lives only in someone’s head. Then I’d help turn that into a short, obvious path from issue to next step.',
                confidence: 'high',
                followUp: 'If you want, I can do that for customers, work, tasks, files, or reporting.',
            );
        }

        if ($this->matches($normalized, ['what needs attention', 'what needs my attention', 'need attention', 'what should i do next', 'whats next', 'what is next'])) {
            if (($context['surface'] ?? null) === 'public') {
                return $this->reply('Start by choosing what you want to improve: customers, work, growth, or your website. Explore a branch, try the demo, then use Get started to choose your setup. Sign in to see next steps for an existing workspace.', confidence: 'high', followUp: 'Tell me which part of your business you want to simplify first.');
            }
            $open = (int) ($workspaceSummary['open_customer_loop_actions'] ?? 0);
            $customerPhrase = $open === 1 ? 'There is 1 open Customer Loop action' : "There are {$open} open Customer Loop actions";

            return $this->reply(
                "{$customerPhrase} in this workspace. Open Follow-up to see the reason, a prepared draft when available, and the person responsible. Bud will never send or publish anything without the final confirmation button.",
                confidence: 'high',
                followUp: 'Ask me to explain a follow-up template or how to build an if/then workflow for it.',
            );
        }

        if ($this->matches($normalized, ['customer loop', 'followup loop', 'follow up loop', 'relationship'])) {
            return $this->reply(
                'Customer Loop connects a useful business moment to the next human step. It can prepare a follow-up, review request, email, text, or social draft, but a person still reviews before anything leaves Everbranch.',
                confidence: 'high',
                followUp: 'Open Follow-up to start with a template, or Workflow Automations to build your own if/then path.',
            );
        }

        if ($this->matches($normalized, ['social', 'instagram', 'facebook', 'linkedin', 'post'])) {
            return $this->reply(
                'Bud can prepare a social draft from a real business moment today. Direct publishing is intentionally not enabled yet, so your team can review the words, image, and timing before connecting any social account.',
                confidence: 'high',
                followUp: 'Tell me what happened and I can point you to the right Customer Loop template.',
            );
        }

        if ($this->matches($normalized, ['who made everbranch', 'who built everbranch', 'who created everbranch', 'who made this', 'who built this', 'who created this'])) {
            return $this->reply(
                'Everbranch was built by John Collins and the team around the product. Bud is the assistant layer on top of it, so if you’re asking about the product itself, that’s the short answer.',
                confidence: 'high',
                followUp: 'If you want the longer origin story, I can keep it to the product side or the team side.',
            );
        }

        if ($this->matches($normalized, ['how can i get on board', 'get on board', 'sign me up', 'how do i sign up', 'how do i join', 'how do i get access', 'request access', 'want to give you money', 'i want to buy', 'pricing', 'price', 'subscribe'])
            || ($this->matches($normalized, ['cost']) && ! $this->matches($normalized, ['shipping']))) {
            return $this->reply(
                trim((string) ($context['offer_summary'] ?? '')).' Core branches are included. Premium products have separate pricing. Get started shows the current purchase or access steps; existing customers keep their agreed terms.',
                confidence: 'high',
                followUp: 'Tell me whether you want access, pricing, or a demo walkthrough, and I’ll point you to the right step.',
            );
        }

        if ($this->matches($normalized, ['you just lost my money', 'lost my money', 'waste of money', 'not worth it', 'too expensive', 'this is frustrating', 'i am frustrated', 'i\'m frustrated'])) {
            return $this->reply(
                'I’m sorry. If something felt unclear or too much effort, tell me exactly where it broke down and I’ll be direct about the next step. If the issue is pricing, access, or setup, I can help separate those cleanly.',
                confidence: 'high',
                followUp: 'If you want, tell me what you were trying to do and I’ll answer without the fluff.',
            );
        }

        if ($this->matches($normalized, ['candle club', 'subscription', 'shopify', 'card', 'payment', 'billing', 'shipping address'])) {
            return $this->reply(
                'I’m not sure yet about the exact live billing detail, but I can explain the flow and help you think through it. I can’t see a live billing record from this public page, so if you point me at the exact action I can tell you what the app is designed to do and where the handoff should go.',
                confidence: 'medium',
                uncertain: true,
                followUp: 'If you need a live account action, open the matching Shopify surface and I can help you reason through the steps.',
            );
        }

        if ($this->matches($normalized, ['customer', 'customers', 'client', 'account'])) {
            return $this->reply(
                'On the customer side, Everbranch keeps the person or company, open questions, follow-ups, files, and related work attached to the same record so your team is not reconstructing the story from memory.',
                confidence: 'high',
                followUp: 'If you want, I can show how that differs for customers in retail, trades, or service work.',
            );
        }

        if ($this->matches($normalized, ['task', 'tasks', 'todo', 'to-do', 'follow-up'])) {
            return $this->reply(
                'Tasks in Everbranch are meant to stay connected to the thing they belong to. That makes ownership, due timing, and context visible instead of leaving a task floating on a separate list with no story behind it.',
                confidence: 'high',
                followUp: 'If you want, I can explain how that changes for jobs, projects, or recurring service work.',
            );
        }

        if ($this->matches($normalized, ['job', 'jobs', 'work order', 'service call', 'service'])) {
            return $this->reply(
                'For jobs and active work, Everbranch keeps notes, photos, parts questions, customer timing, and crew handoff details together so the office and field are looking at the same version of the work.',
                confidence: 'high',
                followUp: 'I can also map that to the demo tab you’re looking at right now if you want.',
            );
        }

        if ($this->matches($normalized, ['report', 'reports', 'metric', 'dashboard', 'analytics'])) {
            return $this->reply(
                'It can surface the signals behind the work too: what is overdue, what is waiting on someone, where revenue is coming from, and which team members are carrying the most load.',
                confidence: 'high',
                followUp: 'If you want the honest version, I can tell you what I think Everbranch is strongest at versus what it does not try to be.',
            );
        }

        if ($scenario !== '' || $pane !== '' || $type !== '' || $customer !== '') {
            $scenarioLabel = $scenario !== '' ? Str::headline($scenario) : 'Business';
            $paneLabel = $pane !== '' ? Str::headline($pane) : 'workflow';
            $customerPhrase = $customer !== '' ? " for {$customer}" : '';
            $typePhrase = $type !== '' ? " ({$type})" : '';

            return $this->reply(
                "In a {$scenarioLabel}{$typePhrase} workflow{$customerPhrase}, Everbranch would help by turning scattered {$paneLabel} details into one visible record with ownership, related files, customer context, and a clear next step.",
                confidence: 'medium',
                followUp: 'If that’s not the part you meant, point me to the screen and I’ll adjust.',
            );
        }

        if ($topic !== null) {
            return $this->reply(
                "I think you’re circling around {$topic}. I can help with the business-process side of that, but I don’t want to invent details I can’t see from here. If you point me at the specific record or action, I can stay concrete.",
                confidence: 'low',
                uncertain: true,
                followUp: 'Give me the customer, job, task, or page and I’ll be more specific.',
            );
        }

        return $this->reply(
            'I can help with how Everbranch organizes customers, jobs, tasks, files, reminders, and team ownership. If you ask me something specific, I’ll answer it plainly; if I don’t know, I’ll say so and tell you what I do know.',
            confidence: 'medium',
            uncertain: true,
            followUp: 'Try asking who made Everbranch, how to get on board, or what Bud would do first.',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $transcript
     */
    protected function topicFromTranscript(array $transcript): ?string
    {
        $lastUserMessage = collect($transcript)
            ->filter(fn (mixed $entry): bool => is_array($entry) && strtolower((string) ($entry['role'] ?? '')) === 'user')
            ->last();

        if (! is_array($lastUserMessage)) {
            return null;
        }

        return $this->topicFromQuestion($this->normalize((string) ($lastUserMessage['text'] ?? '')));
    }

    protected function topicFromQuestion(string $normalized): ?string
    {
        foreach ([
            'customer' => ['customer', 'client', 'account'],
            'task' => ['task', 'todo', 'follow-up'],
            'job' => ['job', 'service call', 'work order', 'service'],
            'report' => ['report', 'dashboard', 'metric', 'analytics'],
            'subscription' => ['subscription', 'billing', 'card', 'shipping address'],
        ] as $topic => $needles) {
            if ($this->matches($normalized, $needles)) {
                return $topic;
            }
        }

        return null;
    }

    protected function isUnclearOrUnknown(string $normalized): bool
    {
        return $this->matches($normalized, [
            'not sure',
            'dont know',
            'don\'t know',
            'guess',
            'made up',
            'unknown',
            'what are you unsure',
            'what do you not know',
        ]);
    }

    /**
     * @param  array<int, string>  $needles
     */
    protected function matches(string $normalized, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     reply:string,
     *     confidence:string,
     *     uncertain:bool,
     *     follow_up:string|null
     * }
     */
    protected function reply(string $reply, string $confidence = 'medium', bool $uncertain = false, ?string $followUp = null): array
    {
        return [
            'reply' => $reply,
            'confidence' => $confidence,
            'uncertain' => $uncertain,
            'follow_up' => $followUp,
        ];
    }

    protected function normalize(string $value): string
    {
        $value = trim(mb_strtolower($value));

        // Bud Core is deterministic, so handle the common human typos for its
        // highest-value workspace questions instead of pretending it understood.
        return preg_replace_callback(
            '/\\b(wat|custmer|custmor|cusotmer|folow|follw|messege|mssage|socal|socail)\\b/u',
            fn (array $match): string => [
                'wat' => 'what',
                'custmer' => 'customer',
                'custmor' => 'customer',
                'cusotmer' => 'customer',
                'folow' => 'follow',
                'follw' => 'follow',
                'messege' => 'message',
                'mssage' => 'message',
                'socal' => 'social',
                'socail' => 'social',
            ][$match[1]],
            $value,
        ) ?? $value;
    }
}
