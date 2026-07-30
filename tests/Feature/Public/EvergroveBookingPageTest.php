<?php

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('evergrove.hosts', ['evergrovesoftware.com', 'www.evergrovesoftware.com']);
});

test('evergrove booking page links to the configured Google appointment schedule', function (): void {
    config()->set(
        'services.google_booking.url',
        'https://calendar.google.com/calendar/appointments/schedules/example'
    );

    $this->get('http://evergrovesoftware.com/book')
        ->assertOk()
        ->assertSee('<title>Book a Consultation | Evergrove Software</title>', false)
        ->assertSee(
            '<meta name="description" content="Schedule a consultation with Evergrove Software to discuss websites, custom software, automation, AI, and digital business solutions.">',
            false
        )
        ->assertSeeText('Book a Consultation')
        ->assertSeeText('Choose a Time')
        ->assertSeeText('John Collins of Evergrove Software')
        ->assertSee(
            'href="https://calendar.google.com/calendar/appointments/schedules/example"',
            false
        )
        ->assertSee('target="_blank"', false)
        ->assertSee('rel="noopener noreferrer"', false);
});

test('evergrove booking page falls back safely when scheduling is unavailable', function (): void {
    config()->set('services.google_booking.url', null);

    $this->get('http://evergrovesoftware.com/book')
        ->assertOk()
        ->assertSeeText('Online scheduling is being finalized.')
        ->assertSeeText('john@evergrovesoftware.com')
        ->assertSee('href="mailto:john@evergrovesoftware.com"', false)
        ->assertDontSee('href=""', false)
        ->assertDontSee('target="_blank"', false);
});

test('evergrove booking page rejects unsafe configured booking URLs', function (): void {
    config()->set('services.google_booking.url', 'javascript:alert(document.domain)');

    $this->get('http://evergrovesoftware.com/book')
        ->assertOk()
        ->assertSeeText('Online scheduling is being finalized.')
        ->assertDontSee('javascript:alert', false);
});

test('evergrove booking page is unavailable on non-evergrove hosts', function (): void {
    config()->set(
        'services.google_booking.url',
        'https://calendar.google.com/calendar/appointments/schedules/example'
    );

    $this->get('http://theeverbranch.com/book')
        ->assertNotFound()
        ->assertDontSeeText('Book a Consultation');
});
