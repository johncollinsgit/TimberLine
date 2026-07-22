<?php

test('everbranch legal pages are public and disclose the quickbooks integration posture', function () {
    $this->get('http://theeverbranch.com/privacy')
        ->assertOk()
        ->assertSee('Privacy Policy')
        ->assertSee('QuickBooks Online')
        ->assertSee('read/import only')
        ->assertSee('does not initiate payments');

    $this->get('http://theeverbranch.com/terms')
        ->assertOk()
        ->assertSee('Terms of Use and End-User License Agreement')
        ->assertSee('read/import only')
        ->assertSee('does not process payments');
});

test('everbranch support page is public and provides app and account help', function () {
    $this->get('http://theeverbranch.com/support')
        ->assertOk()
        ->assertSee('Everbranch Support')
        ->assertSee('Account')
        ->assertSee('Help and support')
        ->assertSee('Request account deletion')
        ->assertSee('john@evergrovesoftware.com');
});

test('evergrove hosts receive the evergrove legal presentation', function () {
    config()->set('evergrove.hosts', ['evergrovesoftware.com']);

    $this->get('http://evergrovesoftware.com/privacy')
        ->assertOk()
        ->assertSee('Evergrove Software')
        ->assertSee('Privacy Policy');

    $this->get('http://evergrovesoftware.com/terms')
        ->assertOk()
        ->assertSee('Evergrove Software')
        ->assertSee('End-User License Agreement');
});
