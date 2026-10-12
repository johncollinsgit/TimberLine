<?php

test('jarvis pages are public on evergrove hosts and link their own policies', function (): void {
    config()->set('evergrove.hosts', ['evergrovesoftware.com', 'www.evergrovesoftware.com']);

    foreach (['evergrovesoftware.com', 'www.evergrovesoftware.com'] as $host) {
        foreach (['/jarvis', '/jarvis/privacy', '/jarvis/terms'] as $path) {
            $this->get('https://'.$host.$path)
                ->assertOk()
                ->assertSee('Jarvis')
                ->assertSee('href="/jarvis/privacy"', false)
                ->assertSee('href="/jarvis/terms"', false)
                ->assertSee('brand/jarvis-mark.svg', false)
                ->assertSee('johncollinsemail@gmail.com')
                ->assertSee('href="tel:+18646165468"', false)
                ->assertSeeText('(864) 616-5468')
                ->assertDontSee('noindex, nofollow', false);
        }
    }
});

test('jarvis branding pages cannot appear on app or tenant hosts', function (): void {
    config()->set('evergrove.hosts', ['evergrovesoftware.com', 'www.evergrovesoftware.com']);

    foreach (['app.theeverbranch.com', 'theeverbranch.com', 'unknown.example'] as $host) {
        foreach (['/jarvis', '/jarvis/privacy', '/jarvis/terms'] as $path) {
            $this->get('https://'.$host.$path)->assertNotFound();
        }
    }
});

test('jarvis privacy distinguishes provider permission from installed behavior and persisted records', function (): void {
    config()->set('evergrove.hosts', ['evergrovesoftware.com']);

    $this->get('https://evergrovesoftware.com/jarvis/privacy')
        ->assertOk()
        ->assertSeeText('including deleting drafts')
        ->assertSeeText('changing, and deleting events')
        ->assertSeeText('Not every database record is encrypted.')
        ->assertSeeText('Google can return full message content, including HTML')
        ->assertDontSeeText('It does not fetch attachments or HTML bodies.')
        ->assertSeeText('does not automatically delete previously saved local records')
        ->assertSeeText('locally hosted or remote providers')
        ->assertSee('https://myaccount.google.com/connections', false);
});
