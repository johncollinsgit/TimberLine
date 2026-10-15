<?php

use App\Models\ServiceInquiry;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('evergrove.hosts', ['evergrovesoftware.com', 'www.evergrovesoftware.com']);
    config()->set('services.google_booking.url', 'https://calendar.google.com/calendar/appointments/schedules/example');
});

function consultationBrief(string $token): array
{
    return ['submission_token' => $token, 'name' => 'Test Owner', 'email' => 'OWNER@example.test', 'company' => 'Example Services', 'business_type' => 'Electrical contractor', 'goals' => ['automation', 'customers'], 'pain_point' => 'Too much double entry', 'desired_outcome' => 'A simpler job handoff', 'current_tools' => 'Spreadsheets', 'timeline' => 'researching'];
}

test('booking page leads with the business outcome and offers both intake forms', function (): void {
    $this->get('http://evergrovesoftware.com/book')->assertOk()
        ->assertSee('<title>Book a Consultation | Evergrove Software</title>', false)
        ->assertSeeText('I help business owners run their business better.')
        ->assertSeeText('Book your 1-hour video consultation')
        ->assertSeeText('What type of business do you run?')
        ->assertSeeText('What’s the biggest thing making your business harder to run?')
        ->assertSee('action="http://evergrovesoftware.com/book/brief"', false)
        ->assertSee('action="http://evergrovesoftware.com/book/hurdle"', false)
        ->assertSee('src="https://calendar.google.com/calendar/appointments/schedules/example?gv=true"', false);
});

test('brief saves business context before revealing Google scheduling and replay does not duplicate it', function (): void {
    $token = (string) Str::uuid();
    $this->withSession(['consultation_token' => $token]);
    $this->post('http://evergrovesoftware.com/book/brief', consultationBrief($token))
        ->assertRedirect('http://evergrovesoftware.com/book#choose-time')->assertSessionHas('consultation_saved', true);
    $inquiry = ServiceInquiry::query()->sole();
    expect($inquiry->email)->toBe('owner@example.test')
        ->and($inquiry->source_page)->toBe('evergrove/book')
        ->and($inquiry->calculator_payload['business_type'])->toBe('Electrical contractor')
        ->and($inquiry->pain_point)->toContain('A simpler job handoff');
    $this->get('http://evergrovesoftware.com/book')->assertOk()
        ->assertSeeText('Your brief is saved')->assertSeeText('Choose a Time in Google')
        ->assertSee('src="https://calendar.google.com/calendar/appointments/schedules/example?gv=true"', false)
        ->assertSee('rel="noopener noreferrer"', false)
        ->assertSeeText('Google will send the confirmation');
    $this->post('http://evergrovesoftware.com/book/brief', consultationBrief($token))->assertRedirect();
    expect(ServiceInquiry::query()->count())->toBe(1);
});

test('brief validates business context and rejects untrusted submissions', function (): void {
    $token = (string) Str::uuid();
    $this->withSession(['consultation_token' => $token]);
    $payload = consultationBrief($token);
    $payload['goals'] = ['unknown'];
    $payload['business_type'] = '';
    $payload['website'] = 'javascript:alert(1)';
    $this->post('http://evergrovesoftware.com/book/brief', $payload)
        ->assertSessionHasErrors(['business_type', 'goals.0', 'website']);
    $this->post('http://evergrovesoftware.com/book/brief', consultationBrief((string) Str::uuid()))->assertStatus(419);
    expect(ServiceInquiry::query()->count())->toBe(0);
});

test('missing or unsafe schedules preserve useful inquiry forms and honest appointment status', function (mixed $url): void {
    config()->set('services.google_booking.url', $url);
    $this->get('http://evergrovesoftware.com/book')->assertOk()
        ->assertSeeText('Request my 1-hour video consultation')->assertSeeText('Online scheduling is being finalized.')
        ->assertDontSee('<iframe', false);
    $this->withSession(['consultation_saved' => true])->get('http://evergrovesoftware.com/book')->assertOk()
        ->assertSeeText('An appointment has not been reserved yet.')->assertDontSee('<iframe', false);
})->with([null, 'javascript:alert(1)', 'https://evil.example/booking', 'https://calendar.google.com.evil.example/booking']);

test('short contact form saves the main hurdle and remains separate from appointment booking', function (): void {
    $token = (string) Str::uuid();
    $this->withSession(['hurdle_token' => $token]);
    $payload = ['hurdle_token' => $token, 'name' => 'Test Owner', 'email' => 'owner@example.test', 'pain_point' => 'Missed customer follow-ups'];
    $this->post('http://evergrovesoftware.com/book/hurdle', $payload)
        ->assertRedirect('http://evergrovesoftware.com/book#business-hurdle')->assertSessionHas('hurdle_saved');
    $this->assertDatabaseHas('service_inquiries', ['pain_point' => 'Missed customer follow-ups', 'source_page' => 'evergrove/book/hurdle']);
    expect(session('consultation_saved'))->toBeNull();
    $this->post('http://evergrovesoftware.com/book/hurdle', $payload)->assertStatus(419);
    expect(ServiceInquiry::query()->count())->toBe(1);
});

test('short contact form validates the hurdle and isolates its errors', function (): void {
    $this->post('http://evergrovesoftware.com/book/hurdle', ['email' => 'invalid'])
        ->assertSessionHasErrors(['name', 'email', 'pain_point', 'hurdle_token'], null, 'hurdle');
    expect(ServiceInquiry::query()->count())->toBe(0);
});

test('booking routes are unavailable on unrelated hosts', function (): void {
    $this->get('http://theeverbranch.com/book')->assertNotFound();
    $this->post('http://theeverbranch.com/book/brief', [])->assertNotFound();
    $this->post('http://theeverbranch.com/book/hurdle', [])->assertNotFound();
});
