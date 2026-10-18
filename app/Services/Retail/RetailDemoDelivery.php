<?php

namespace App\Services\Retail;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** Owner-reviewed one-message demonstrations. Never touches a merchant CRM or legacy messaging connection. */
class RetailDemoDelivery
{
    /** Embed the reviewed local artwork so the actual email keeps the same logo and photo. */
    protected function emailAttachments(array $variant): array
    {
        $photo = (string) ($variant['image'] ?? '');
        if (! preg_match('~^/retail/assets/avery-[0-9]+\.webp$~', $photo)) {
            throw new \RuntimeException('A reviewed catalog photograph is required.');
        }

        return array_map(function (array $asset): array {
            [$relative, $id, $type] = $asset;
            $path = public_path($relative);
            if (! is_file($path)) {
                throw new \RuntimeException('Email artwork is unavailable.');
            }

            return ['content' => base64_encode(file_get_contents($path)), 'type' => $type,
                'filename' => basename($path), 'disposition' => 'inline', 'content_id' => $id];
        }, [['retail/assets/avery-logo.png', 'avery-logo', 'image/png'], [ltrim($photo, '/'), 'avery-knife-photo', 'image/webp']]);
    }

    protected function directory(): string
    {
        return storage_path('app/private/retail-demo-deliveries');
    }

    protected function settings(): array
    {
        // The private presenter transport is local only. Production needs a separately reviewed rollout.
        $path = storage_path('app/private/retail-demo-delivery.json');
        if (! app()->environment('local') || ! is_file($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function send(array $input, int $owner): array
    {
        $channel = $input['channel'];
        $settings = $this->settings();
        $provider = $settings[$channel] ?? [];
        if (empty($provider['enabled'])) {
            throw ValidationException::withMessages(['channel' => $channel === 'sms'
                ? 'Live texting is blocked: Evergrove business verification and an approved SMS sender are still pending. No text was sent.'
                : 'Live email is not configured on this demo server. No email was sent.']);
        }
        $customer = collect(app(AveryWorkspaceDemo::class)->snapshot()['customers'])->firstWhere('id', $input['customer_id']);
        $catalog = json_decode(file_get_contents(resource_path('data/avery-catalog-demo.json')), true, 512, JSON_THROW_ON_ERROR);
        $product = collect($catalog['products'])->firstWhere('id', $input['product_id']);
        $variant = collect($product['variants'] ?? [])->firstWhere('id', $input['variant_id']);
        if (! $customer || ! $product || ! $variant) {
            throw ValidationException::withMessages(['customer_id' => 'Choose a fictional Avery customer and an exact catalog combination.']);
        }
        $recipient = trim($input['recipient']);
        if ($channel === 'sms') {
            $recipient = preg_replace('/[\s().-]/', '', $recipient);
            if (preg_match('/^\d{10}$/', $recipient)) {
                $recipient = '+1'.$recipient;
            }
            if (! preg_match('/^\+[1-9]\d{7,14}$/', $recipient)) {
                throw ValidationException::withMessages(['recipient' => 'Enter a mobile number with country code.']);
            }
            if (! in_array($recipient, (array) ($provider['allowed_recipients'] ?? []), true) && ($provider['presenter_confirmed_recipients'] ?? false) !== true) {
                throw ValidationException::withMessages(['recipient' => 'This demo sender has not been enabled for a separately confirmed recipient. No text was sent.']);
            }
            if (($provider['verification_status'] ?? '') !== 'approved' || empty($provider['account_sid']) || empty($provider['auth_token']) || empty($provider['from'])) {
                throw ValidationException::withMessages(['channel' => 'An approved dedicated Evergrove SMS sender is required. No text was sent.']);
            }
        } elseif (! filter_var($recipient, FILTER_VALIDATE_EMAIL) || str_ends_with(strtolower($recipient), '.invalid')) {
            throw ValidationException::withMessages(['recipient' => 'Enter your real delivery email in this confirmation form.']);
        }
        $signature = hash_hmac('sha256', json_encode([$owner, $channel, strtolower($recipient), $customer['id'], $product['id'], $variant['id']]), config('app.key'));
        $key = hash('sha256', $owner.'|'.$input['idempotency_key']);
        $dir = $this->directory();
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $dir.'/'.$key.'.json';
        $lock = fopen($path, 'c+');
        chmod($path, 0600);
        if (! $lock || ! flock($lock, LOCK_EX)) {
            throw new \RuntimeException('The delivery audit could not be locked. No send was attempted.');
        }
        try {
            $stored = stream_get_contents($lock);
            if ($stored !== '') {
                $row = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
                if ($row['signature'] !== $signature) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This delivery reference belongs to a different message.']);
                }

                return $this->result($row, true);
            }
            $row = ['reference' => 'DEMO-MSG-'.substr($key, 0, 12), 'owner_id' => $owner, 'channel' => $channel, 'provider' => $channel === 'email' ? 'sendgrid' : 'twilio', 'signature' => $signature, 'customer_id' => $customer['id'], 'product_id' => $product['id'], 'variant_id' => $variant['id'], 'consent_at' => now()->toIso8601String(), 'consent_scope' => 'One presenter-requested demonstration message; recipient ownership confirmed on tablet; no mailing-list enrollment', 'status' => 'submitting'];
            $this->write($lock, $row); // Reserve durably before a network attempt. Ambiguous outcomes never auto-resend.
            $subject = 'A knife worth another look: '.$product['name'];
            $body = "Hi {$customer['name']}, you saved an interest in the {$product['name']} with {$variant['name']}. The catalog snapshot lists it at $".number_format($variant['price_cents'] / 100, 2).". Would you like an update about the next release? Availability and current pricing need confirmation.\n\nEvergrove demonstration for Avery Knifeworks — fictional customer data. This one message was requested on the presenter tablet; you have not joined a marketing list.";
            try {
                if ($channel === 'email') {
                    if (($provider['provider'] ?? '') !== 'sendgrid' || ! str_ends_with($provider['from']['address'] ?? '', '@theeverbranch.com') || empty($provider['api_key'])) {
                        throw new \RuntimeException('An authenticated platform SendGrid sender is required.');
                    }
                    $html = view('emails.retail-demo-product', compact('subject', 'body', 'product', 'variant'))->render();
                    $response = Http::withToken($provider['api_key'])->timeout(15)->post('https://api.sendgrid.com/v3/mail/send', [
                        'personalizations' => [['to' => [['email' => $recipient]]]],
                        'from' => ['email' => $provider['from']['address'], 'name' => $provider['from']['name'] ?? 'Evergrove Software'],
                        'subject' => $subject, 'content' => [['type' => 'text/plain', 'value' => $body], ['type' => 'text/html', 'value' => $html]],
                        'attachments' => $this->emailAttachments($variant),
                        'custom_args' => ['demo_reference' => $row['reference']],
                    ]);
                    if ($response->status() !== 202) {
                        throw new \RuntimeException('SendGrid did not accept this message.');
                    }
                    $row['provider_reference'] = $response->header('X-Message-Id');
                    $row['provider'] = 'sendgrid';
                    $row['status'] = 'accepted';
                } else {
                    $sms = 'Modern Forestry: Owner-authorized Everbranch demo. Fictional '.$customer['name'].' prefers Avery '.$product['name'].', '.$variant['name'].' ($'.number_format($variant['price_cents'] / 100, 2).'). Catalog only; availability unconfirmed. Reply STOP to opt out.';
                    $response = Http::asForm()->withBasicAuth($provider['account_sid'], $provider['auth_token'])->timeout(15)->post('https://api.twilio.com/2010-04-01/Accounts/'.urlencode($provider['account_sid']).'/Messages.json', ['To' => $recipient, 'From' => $provider['from'], 'Body' => $sms]);
                    if (! $response->successful() || ! str_starts_with((string) $response->json('sid'), 'SM')) {
                        throw new \RuntimeException('The SMS provider did not accept this message.');
                    }
                    $row['provider_reference'] = $response->json('sid');
                    $row['status'] = $response->json('status', 'accepted');
                }
                $row['updated_at'] = now()->toIso8601String();
            } catch (\Throwable $error) {
                // Do not expose provider credentials or ambiguous transport details in browser responses/logs.
                $row['status'] = 'unconfirmed';
            }
            $this->write($lock, $row);

            return $this->result($row);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function status(string $reference, int $owner): array
    {
        foreach (glob($this->directory().'/*.json') ?: [] as $path) {
            $lock = fopen($path, 'r+');
            if (! $lock || ! flock($lock, LOCK_EX)) {
                continue;
            }
            try {
                $row = json_decode(stream_get_contents($lock), true, 512, JSON_THROW_ON_ERROR);
                if (($row['reference'] ?? '') !== $reference || ($row['owner_id'] ?? null) !== $owner) {
                    continue;
                }
                $provider = $this->settings()['sms'] ?? [];
                if ($row['channel'] === 'sms' && ! empty($row['provider_reference']) && ! empty($provider['enabled'])) {
                    $response = Http::withBasicAuth($provider['account_sid'], $provider['auth_token'])->timeout(10)->get('https://api.twilio.com/2010-04-01/Accounts/'.urlencode($provider['account_sid']).'/Messages/'.urlencode($row['provider_reference']).'.json');
                    $status = $response->json('status');
                    if ($response->successful() && in_array($status, ['queued', 'sending', 'sent', 'delivered', 'undelivered', 'failed'], true)) {
                        $row['status'] = $status;
                        $row['provider_error_code'] = $response->json('error_code');
                        $row['updated_at'] = now()->toIso8601String();
                        $this->write($lock, $row);
                    }
                }

                return $this->result($row, true);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        abort(404);
    }

    private function write($file, array $row): void
    {
        rewind($file);
        ftruncate($file, 0);
        fwrite($file, json_encode($row, JSON_THROW_ON_ERROR));
        fflush($file);
        if (function_exists('fsync')) {
            fsync($file);
        }
    }

    private function result(array $row, bool $reused = false): array
    {
        $accepted = in_array($row['status'], ['accepted', 'queued', 'sending', 'sent', 'delivered'], true);

        return ['reference' => $row['reference'], 'status' => $row['status'], 'reused' => $reused,
            'message' => $row['status'] === 'delivered' ? 'Twilio confirmed delivery to the authorized test mobile. Reference '.$row['reference'].'.' : (in_array($row['status'], ['failed', 'undelivered'], true) ? 'Twilio reports that this text was not delivered. Reference '.$row['reference'].'.' : ($accepted ? 'The '.($row['channel'] === 'email' ? 'email transport' : 'SMS provider').' accepted this message. Reference '.$row['reference'].'. Acceptance is not delivery confirmation.'
                : 'Delivery is unconfirmed. Reference '.$row['reference'].'. The demo will not retry this message automatically; check the provider before sending again.'))];
    }
}
