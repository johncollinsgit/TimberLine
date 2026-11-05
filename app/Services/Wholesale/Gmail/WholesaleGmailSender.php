<?php

namespace App\Services\Wholesale\Gmail;

use App\Models\Tenant;
use App\Services\Wholesale\WholesaleCustomerOutreachDraft;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class WholesaleGmailSender
{
    public const SEND = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    public function __construct(private WholesaleGmailJournal $journal, private WholesaleGmailConnection $connections, private WholesaleCustomerOutreachDraft $drafts) {}

    private function content(int $tenant, string $account, string $subject, string $message): array
    {
        abort_unless(trim($subject) !== '' && mb_strlen($subject) <= 200 && ! preg_match('/[\r\n]/', $subject)
            && trim($message) !== '' && mb_strlen($message) <= 4000, 422, 'Enter a subject and personal message.');
        $draft = $this->drafts->forCustomer(Tenant::query()->findOrFail($tenant), $account);
        abort_if(app(\App\Services\Marketing\MarketingEmailUnsubscribeService::class)->suppressed($tenant, $draft['recipient']), 409, 'This email address is suppressed.');
        abort_if($draft['catalog_issue'], 409, 'Reload the product photos before sending.');
        $draft['subject'] = $subject;
        $draft['message'] = $message;
        $html = view('emails.wholesale.customer-outreach', compact('draft'))->render();
        $plain = html_entity_decode(strip_tags(preg_replace('/<\/(p|h[1-6]|tr|div)>|<br\s*\/?>/i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return ['draft' => $draft, 'html' => $html, 'plain' => $plain, 'hash' => hash('sha256', json_encode($draft, JSON_THROW_ON_ERROR))];
    }

    public function review(int $tenant, int $actor, string $shop, string $account, string $subject, string $message): array
    {
        $this->journal->operator($tenant, $actor, $shop);
        $status = $this->connections->status($tenant, $shop);
        abort_unless($status['can_review'], 409, $status['message']);
        $content = $this->content($tenant, $account, $subject, $message);
        $connection = $this->connections->connection($tenant, $shop);
        $binding = $this->connections->settings($tenant, $shop)['binding'];
        $footer = '<p style="font-size:12px;color:#566a64;">A wholesale reorder invitation from '.e($binding['name']).'.<br>'.e($binding['mailing_address']).'</p>';
        $content['html'] .= $footer;
        [$content['plain'], $prepared] = app(\App\Services\Marketing\MarketingEmailUnsubscribeService::class)->prepare($tenant, $content['draft']['recipient'], $content['plain']."\n".$binding['mailing_address'], ['html_body' => $content['html']]);
        $content['html'] = $prepared['html_body'];
        $content['headers'] = $prepared['headers'];
        $key = hash('sha256', $shop.'|'.$account.'|'.$content['draft']['source_order_id']);
        $row = $this->journal->create($tenant, 'review', hash('sha256', Str::random(64)), [
            'actor' => $actor, 'shop' => $shop, 'account' => $account, 'intent' => $key,
            'generation' => $connection->data['generation'], 'fingerprint' => $connection->data['fingerprint'],
            'expires' => now()->addMinutes(15)->timestamp, ...$content,
        ], 'reviewed');

        return ['id' => $row->id, 'to' => $content['draft']['recipient'], 'subject' => $subject,
            'from' => $status['from'], 'reply_to' => $status['reply_to'], 'name' => $status['name'], 'can_send' => $status['can_send'], 'can_test' => $status['can_test'], 'test_recipient' => $status['test_recipient'], 'html' => $content['html']];
    }

    public function send(int $tenant, int $actor, string $shop, string $reviewId, bool $test = false): array
    {
        $this->journal->operator($tenant, $actor, $shop);
        $review = $this->journal->rows($tenant, 'review')->findOrFail($reviewId);
        $d = $review->data;
        abort_unless($d['actor'] === $actor && $d['shop'] === $shop, 403);
        $kind = $test ? 'test' : 'send';
        $intent = $test ? hash('sha256', 'internal-test|'.$reviewId) : $d['intent'];
        $existing = $this->journal->rows($tenant, $kind)->where('source_key', $intent)->first();
        if ($existing) {
            return $this->result($existing, $reviewId);
        }
        abort_unless($d['expires'] > now()->timestamp && $review->status === 'reviewed', 409, 'Review this email again before sending.');
        abort_unless(config($test ? 'wholesale_gmail.test_enabled' : 'wholesale_gmail.send_enabled'), 503, 'Gmail sending is paused.');
        $auth = $this->connections->accessToken($tenant, $actor, $shop);
        abort_unless($auth['generation'] === $d['generation'] && $auth['config']['fingerprint'] === $d['fingerprint'], 409, 'The mailbox changed. Review this email again.');
        $current = $this->content($tenant, $d['account'], $d['draft']['subject'], $d['draft']['message']);
        abort_unless(hash_equals($d['hash'], $current['hash']), 409, 'The customer or order changed. Review the current email again.');
        $binding = $auth['config']['binding'];
        $recipient = $test ? $binding['test_recipient'] : $d['draft']['recipient'];
        $html = $d['html'];
        $plain = $d['plain'];
        $headers = $d['headers'];
        if ($test) {
            // Team tests must not contain a working opt-out token for the real buyer.
            $oldUrl = trim($headers['List-Unsubscribe'], '<>');
            $newUrl = app(\App\Services\Marketing\MarketingEmailUnsubscribeService::class)->url($tenant, $recipient);
            $html = str_replace(e($oldUrl), e($newUrl), $html);
            $plain = str_replace($oldUrl, $newUrl, $plain);
            $headers['List-Unsubscribe'] = '<'.$newUrl.'>';
        }
        $email = (new Email)->from(new Address($binding['from'], $binding['name']))->replyTo($binding['reply_to'])
            ->to($recipient)->subject($d['draft']['subject'])->html($html)->text($plain);
        foreach ($headers as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }
        $email->getHeaders()->addIdHeader('Message-ID', ($test ? 'test-' : '').$reviewId.'@'.explode('@', $binding['from'], 2)[1]);
        $raw = WholesaleGmailConnection::encode($email->toString());
        abort_if(strlen($raw) > 1000000, 422, 'This email is too large.');
        // Commit a durable claim before the only send HTTP call. Different reviews share the same intent key.
        $claim = $this->journal->locked($tenant, function () use ($tenant, $actor, $shop, $d, $reviewId, $binding, $kind, $intent, $recipient, $test, $raw) {
            $this->journal->operator($tenant, $actor, $shop);
            abort_unless(config($test ? 'wholesale_gmail.test_enabled' : 'wholesale_gmail.send_enabled'), 503);
            abort_unless($d['expires'] > now()->timestamp, 409, 'Review this email again before sending.');
            $c = $this->connections->settings($tenant, $shop);
            $connection = $this->connections->connection($tenant, $shop);
            abort_unless($connection?->status === 'connected' && $connection->data['generation'] === $d['generation'] && $c['fingerprint'] === $d['fingerprint'], 409);
            if ($existing = $this->journal->rows($tenant, $kind)->where('source_key', $intent)->first()) {
                return ['row' => $existing, 'new' => false];
            }
            // Recheck suppression/history under the claim lock; the server is authoritative for every field.
            $current = $this->content($tenant, $d['account'], $d['draft']['subject'], $d['draft']['message']);
            abort_unless(hash_equals($d['hash'], $current['hash']), 409, 'This email changed. Review it again.');
            $row = $this->journal->create($tenant, $kind, $intent, [
                'actor' => $actor, 'shop' => $shop, 'account' => $d['account'], 'review' => $reviewId,
                'to' => $recipient, 'internal_test' => $test, 'from' => $binding['from'], 'reply_to' => $binding['reply_to'],
                'content_hash' => $d['hash'], 'mime_sha256' => hash('sha256', $raw), 'rfc_message_id' => ($test ? 'test-' : '').$reviewId.'@'.explode('@', $binding['from'], 2)[1],
            ], 'sending');
            $this->journal->audit($tenant, $actor, 'send_claimed', $row->id);

            return ['row' => $row, 'new' => true];
        });
        $row = $claim['row'];
        if (! $claim['new']) {
            return $this->result($row, $reviewId);
        }
        try {
            $response = Http::withToken($auth['token'])->acceptJson()->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(20)->post(self::SEND, ['raw' => $raw]);
            if (in_array($response->status(), [401, 403], true)) {
                $this->connections->invalidate($tenant, $shop, $auth['generation']);
            }
            $value = $response->json();
            abort_unless($response->status() === 200 && preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $value['id'] ?? '')
                && preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $value['threadId'] ?? ''), 503);
            $this->journal->locked($tenant, function () use ($row, $value) {
                $row->update(['status' => 'accepted', 'data' => [...$row->data, 'provider_message_id' => $value['id'], 'provider_thread_id' => $value['threadId'], 'accepted_at' => now()->toIso8601String()]]);
            });
            $this->journal->audit($tenant, $actor, 'send_accepted', $row->id, ['provider_message_id' => $value['id'], 'provider_thread_id' => $value['threadId']]);
        } catch (\Throwable) {
            // Do not overwrite a persisted acceptance if a later audit write fails.
            if ($row->fresh()->status !== 'accepted') {
                $row->update(['status' => 'uncertain']);
            }
        }

        return $this->result($row->fresh(), $reviewId);
    }

    private function result($row, string $reviewId): array
    {
        return ['id' => $row->id, 'internal_test' => $row->data['internal_test'] ?? false, 'to' => $row->data['to'], 'status' => $row->status, 'message_id' => $row->data['provider_message_id'] ?? null,
            'thread_id' => $row->data['provider_thread_id'] ?? null,
            'message' => ($row->data['review'] !== $reviewId ? 'An email for this order already has a recorded attempt. ' : '').($row->status === 'accepted' ? 'Accepted by Gmail. Delivery has not been confirmed.'
                : 'This send is pending or uncertain. Check Gmail Sent before taking further action. It will not be resent automatically.')];
    }
}
