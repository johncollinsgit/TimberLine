<?php

use App\Models\User;
use App\Notifications\SelfServiceVerifyEmail;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    config()->set('mail.default', 'array');
    config()->set('mail.from', ['address' => 'no-reply@theeverbranch.com', 'name' => 'Everbranch']);
    config()->set('app.name', 'Laravel');
    config()->set('tenancy.domains.canonical.landlord_host', 'app.theeverbranch.com');
    config()->set('tenancy.domains.canonical.scheme', 'https');
    Mail::purge('array');
});

test('actual self service MIME disables tracking and retains direct signed links in both alternatives', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'fictional-verification@example.test']);
    $transport = Mail::mailer('array')->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(ArrayTransport::class);

    app(MailChannel::class)->send($user, new SelfServiceVerifyEmail);

    expect($transport->messages())->toHaveCount(1);
    $email = $transport->messages()->first()->getOriginalMessage();
    expect($email)->toBeInstanceOf(Email::class);
    $header = $email->getHeaders()->get('X-SMTPAPI');
    expect($header)->not->toBeNull();
    expect(json_decode($header->getBodyAsString(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'filters' => [
            'clicktrack' => ['settings' => ['enable' => 0, 'enable_text' => false]],
            'opentrack' => ['settings' => ['enable' => 0]],
        ],
    ]);
    expect($email->toString())->toContain('X-SMTPAPI:', 'Content-Type: multipart/alternative')
        ->and($email->getFrom()[0]->getAddress())->toBe('no-reply@theeverbranch.com')
        ->and($email->getFrom()[0]->getName())->toBe('Everbranch')
        ->and($email->getHtmlBody())->toContain('Everbranch', 'https://app.theeverbranch.com/email/confirm/')
        ->and($email->getTextBody())->toContain('Everbranch', 'https://app.theeverbranch.com/email/confirm/', 'expires in 60 minutes.')
        ->not->toContain('Laravel', 'sendgrid.net', '&amp;');
    preg_match('~https://app\.theeverbranch\.com/email/confirm/\S+~', $email->getTextBody(), $matches);
    $url = $matches[0];
    expect(html_entity_decode($email->getHtmlBody()))->toContain($url);
    $this->withoutVite()->get($url)->assertOk()->assertSeeText('Email confirmed');
    $this->assertGuest();
});

test('the per-message tracking override does not alter other notifications or shared mail configuration', function (): void {
    $user = User::factory()->unverified()->create();
    $before = config('mail');

    app(MailChannel::class)->send($user, new SelfServiceVerifyEmail);
    app(MailChannel::class)->send($user, new VerifyEmail);

    $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(2)
        ->and($messages->last()->getOriginalMessage()->getHeaders()->has('X-SMTPAPI'))->toBeFalse()
        ->and(config('mail'))->toBe($before);
});
