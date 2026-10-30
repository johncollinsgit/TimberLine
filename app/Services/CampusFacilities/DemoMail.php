<?php

namespace App\Services\CampusFacilities;

use Illuminate\Support\Facades\Http;
use Throwable;

class DemoMail
{
    public function configured(): bool
    {
        return (bool) config('campus_facilities.mail_enabled') && (string) config('services.sendgrid.api_key') !== '';
    }

    public function deliver(string $recipient, string $subject, string $content, string $link, string $button): string
    {
        if (! $this->configured()) {
            return 'unavailable';
        }
        $logo = 'https://'.config('tenancy.domains.canonical.landlord_host').'/brand/everbranch-mark.png';
        $html = '<!doctype html><html><body style="margin:0;background:#f1f5ef;font-family:Arial,Helvetica,sans-serif;color:#294c3e"><table role="presentation" width="100%"><tr><td align="center" style="padding:28px 12px"><table role="presentation" width="600" style="max-width:100%;background:#ffffff;border-radius:14px;overflow:hidden"><tr><td style="padding:27px 30px;background:#f7faf4;border-bottom:3px solid #ffc629"><img src="'.e($logo).'" width="36" height="36" alt="" style="vertical-align:middle;margin-right:9px"><strong style="font-size:25px;vertical-align:middle">Everbranch</strong><div style="margin-top:13px;font-size:11px;letter-spacing:2px">LEES-McRAE FACILITIES DEMO</div></td></tr><tr><td style="padding:30px"><h1 style="font-family:Georgia,serif;font-size:26px;font-weight:400;margin:0 0 20px">'.e($subject).'</h1><div style="font-size:14px;line-height:1.7;white-space:pre-wrap">'.nl2br(e($content)).'</div><p style="margin:26px 0"><a href="'.e($link).'" style="display:inline-block;background:#235b4e;color:#ffffff;padding:14px 23px;text-decoration:none;border-radius:7px">'.e($button).'</a></p><p style="font-size:11px;color:#7a8d7d">This is a public, fictional demonstration. No official campus work order or alert is represented.</p></td></tr></table></td></tr></table></body></html>';
        try {
            $response = Http::timeout(15)->withToken((string) config('services.sendgrid.api_key'))->post('https://api.sendgrid.com/v3/mail/send', [
                'personalizations' => [['to' => [['email' => $recipient]]]],
                'from' => ['email' => (string) config('campus_facilities.mail_from'), 'name' => 'Everbranch'],
                'subject' => '[DEMO] '.$subject,
                'content' => [['type' => 'text/plain', 'value' => $subject."\n\n".$content."\n\n".$button.': '.$link."\n\nPublic fictional demonstration."], ['type' => 'text/html', 'value' => $html]],
                'tracking_settings' => ['click_tracking' => ['enable' => false, 'enable_text' => false], 'open_tracking' => ['enable' => false]],
                'categories' => ['campus-facilities-demo'],
            ]);

            return $response->status() === 202 ? 'accepted' : 'failed';
        } catch (Throwable) {
            // Unknown provider outcomes remain held; never automatically send again.
            return 'unknown';
        }
    }
}
