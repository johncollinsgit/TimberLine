<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Marketing\MarketingAllOptedInSendService;
use App\Services\Marketing\TwilioSenderConfigService;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketingAllOptedInSendController extends Controller
{
    public function show(
        Request $request,
        MarketingAllOptedInSendService $sendService,
        TwilioSenderConfigService $senderConfigService
    ): View
    {
        return view('marketing/send/all-opted-in', [
            'section' => MarketingSectionRegistry::section('messages'),
            'sections' => $this->navigationItems(),
            'audience' => $sendService->selectedAudienceSummary((string) old('channel', 'both')),
            'channelSelection' => (string) old('channel', 'both'),
            'defaultTestEmail' => old('test_email', (string) auth()->user()?->email),
            'confirmationToken' => $this->issueConfirmationToken($request),
            'testResult' => session('quick_send_all_opted_in_test_result'),
            'sendResult' => session('quick_send_all_opted_in_send_result'),
            'smsSenders' => $senderConfigService->all(),
            'defaultSmsSenderKey' => (string) ($senderConfigService->defaultSender()['key'] ?? ''),
        ]);
    }

    public function submit(Request $request, MarketingAllOptedInSendService $sendService): RedirectResponse
    {
        $intent = strtolower(trim((string) $request->input('intent', 'send')));
        if (! in_array($intent, ['test', 'send'], true)) {
            $intent = 'send';
        }

        $validated = $this->validatedPayload($request, $intent);
        /** @var User $actor */
        $actor = $request->user();

        if ($intent === 'test') {
            $result = $sendService->sendTest($actor, $validated);

            return redirect()
                ->route('marketing.send.all-opted-in')
                ->withInput($request->except(['intent']))
                ->with('toast', [
                    'style' => 'success',
                    'message' => 'Test send complete.',
                ])
                ->with('quick_send_all_opted_in_test_result', $result);
        }

        $this->consumeConfirmationToken($request, (string) ($validated['confirmation_token'] ?? ''));

        $result = $sendService->createAndSend($actor, $validated);

        return redirect()
            ->route('marketing.send.all-opted-in')
            ->with('toast', [
                'style' => 'success',
                'message' => 'All opted-in send completed.',
            ])
            ->with('quick_send_all_opted_in_send_result', $result);
    }

    /**
     * @return array<string,mixed>
     */
    protected function validatedPayload(Request $request, string $intent): array
    {
        $data = $request->validate([
            'intent' => ['required', 'in:test,send'],
            'channel' => ['required', 'in:sms,email,both'],
            'sms_body' => ['nullable', 'string', 'max:1600'],
            'email_subject' => ['nullable', 'string', 'max:255'],
            'email_body' => ['nullable', 'string', 'max:10000'],
            'cta_link' => ['nullable', 'url', 'max:2000'],
            'sender_key' => ['nullable', 'string', 'max:80'],
            'test_phone' => ['nullable', 'string', 'max:50'],
            'test_email' => ['nullable', 'string', 'max:255'],
            'confirm_send' => ['nullable'],
            'confirmation_token' => ['nullable', 'string', 'max:120'],
        ]);

        $channel = (string) $data['channel'];
        $errors = [];

        if (in_array($channel, ['sms', 'both'], true) && trim((string) ($data['sms_body'] ?? '')) === '') {
            $errors['sms_body'] = 'SMS message is required when SMS is selected.';
        }

        if (in_array($channel, ['email', 'both'], true)) {
            if (trim((string) ($data['email_subject'] ?? '')) === '') {
                $errors['email_subject'] = 'Email subject is required when email is selected.';
            }
            if (trim((string) ($data['email_body'] ?? '')) === '') {
                $errors['email_body'] = 'Email body is required when email is selected.';
            }
        }

        if ($intent === 'send' && ! (bool) ($data['confirm_send'] ?? false)) {
            $errors['confirm_send'] = 'Confirm the final send before sending to all opted-in customers.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'channel' => $channel,
            'sms_body' => trim((string) ($data['sms_body'] ?? '')),
            'email_subject' => trim((string) ($data['email_subject'] ?? '')),
            'email_body' => trim((string) ($data['email_body'] ?? '')),
            'cta_link' => trim((string) ($data['cta_link'] ?? '')),
            'sender_key' => trim((string) ($data['sender_key'] ?? '')),
            'test_phone' => trim((string) ($data['test_phone'] ?? '')),
            'test_email' => trim((string) ($data['test_email'] ?? '')),
            'confirmation_token' => trim((string) ($data['confirmation_token'] ?? '')),
        ];
    }

    protected function issueConfirmationToken(Request $request): string
    {
        $token = Str::random(40);
        $request->session()->put($this->confirmationSessionKey(), $token);

        return $token;
    }

    protected function consumeConfirmationToken(Request $request, string $providedToken): void
    {
        $expectedToken = (string) $request->session()->pull($this->confirmationSessionKey(), '');
        if ($providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            throw ValidationException::withMessages([
                'confirm_send' => 'This send confirmation expired. Refresh the page and confirm again before sending.',
            ]);
        }
    }

    protected function confirmationSessionKey(): string
    {
        return 'marketing.send.all_opted_in.confirmation_token';
    }

    /**
     * @return array<int,array{key:string,label:string,href:string,current:bool}>
     */
    protected function navigationItems(): array
    {
        $items = [];
        foreach (MarketingSectionRegistry::sections() as $key => $section) {
            $current = request()->routeIs($section['route']) || request()->routeIs($section['route'] . '.*');
            if ($key === 'messages' && request()->routeIs('marketing.send.all-opted-in*')) {
                $current = true;
            }

            $items[] = [
                'key' => $key,
                'label' => $section['label'],
                'href' => route($section['route']),
                'current' => $current,
            ];
        }

        return $items;
    }
}
