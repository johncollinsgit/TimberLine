<?php

use App\Models\Tenant;
use App\Services\Soccer\SoccerFamilyHelpService;
use App\Services\Soccer\SoccerStore;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('soccer.help_ai_enabled', false);
    $this->tenant = Tenant::create(['name' => 'Wren', 'slug' => 'wren-family-soccer']);
    $this->store = app(SoccerStore::class);
    $this->season = $this->store->insert('seasons', $this->tenant->id, ['season_key' => 'spring-2027', 'name' => 'Spring 2027', 'starts_on' => '2027-03-15', 'age_as_of' => '2027-03-15', 'registration_open' => false, 'teams' => json_encode(config('soccer.teams'))]);
    $family = $this->store->insert('families', $this->tenant->id, ['email' => 'example@example.com', 'profile' => $this->store->encrypt([])]);
    $this->family = $this->store->row('families', $this->tenant->id, $family);
    $this->help = app(SoccerFamilyHelpService::class);
    Http::preventStrayRequests();
});

it('answers casual toddler questions without suggesting an exception or denying profile storage', function ($question) {
    $answer = $this->help->ask($this->tenant->id, $this->family, $question);
    expect($answer['needs_review'])->toBeFalse()
        ->and($answer['answer'])->toContain('at least 3 by March 15, 2027')->toContain('no exceptions')->toContain('save their profile')->toContain('without enrolling or paying');
    Http::assertNothingSent();
})->with(['Can my 2yo play?', 'Can my 2-year-old play?', 'My child turns 3 in April. Can she play?', 'Can I register my toddler?', 'What is the minimum age?', 'She is almost 3', 'My child turns three after the season starts']);

it('provides a human contact immediately without creating an unanswered review', function ($question) {
    $answer = $this->help->ask($this->tenant->id, $this->family, $question);
    expect($answer['answer'])->toContain('Text or call Sarah Collins at 678-644-1829')->not->toContain('saved')
        ->and($answer['needs_review'])->toBeFalse()
        ->and($this->store->query('events', $this->tenant->id)->where('action', 'family_help_unanswered')->count())->toBe(0);
})->with(['I want to talk to a real person', 'Can I speak to someone?', 'Who can I contact?', 'Can someone call me?', 'I need help with registration', 'I have a question for Sarah', 'talk to a person']);

it('separates current and scheduled registration status from season dates', function () {
    $this->travelTo(now()->setDate(2026, 10, 19));
    $this->store->query('seasons', $this->tenant->id)->where('id', $this->season)->update(['registration_opens_at' => '2026-11-01 04:00:00']);
    foreach (['When does registration open?', 'Can I register yet?', 'When can I sign up?', 'Is registration open?', 'When will spring registration begin?'] as $question) {
        $answer = $this->help->ask($this->tenant->id, $this->family, $question);
        expect($answer['answer'])->toContain('currently closed')->toContain('November 1, 2026, 12:00 AM EDT')->not->toContain('March 15');
    }
    $this->store->query('seasons', $this->tenant->id)->where('id', $this->season)->update(['registration_open' => true]);
    expect($this->help->ask($this->tenant->id, $this->family, 'Is registration open?')['answer'])->toContain('currently open')->not->toContain('scheduled opening');
    $this->store->query('seasons', $this->tenant->id)->where('id', $this->season)->update(['registration_open' => false, 'registration_opens_at' => '2026-10-18 04:00:00']);
    expect($this->help->ask($this->tenant->id, $this->family, 'Can I register yet?')['answer'])->toContain('currently closed')->not->toContain('scheduled opening');
});

it('answers confirmed season and location facts without requiring AI', function () {
    foreach (['When does the spring season start?', 'When is the first practice?', 'When is opening night?', 'What day does soccer begin?', 'What are the season dates?'] as $question) {
        $answer = $this->help->ask($this->tenant->id, $this->family, $question);
        expect($answer['needs_review'])->toBeFalse()
            ->and($answer['answer'])->toContain('Monday, March 15, 2027')->toContain('May 17, 2027')->toContain('5:30–6:30 PM')->toContain('no weekend')->not->toContain('registration');
    }
    foreach (["what's the address?", 'Where are the soccer fields?', 'Where is practice?', 'How do I find you?'] as $question) {
        $answer = $this->help->ask($this->tenant->id, $this->family, $question);
        expect($answer['answer'])->toContain('8001 Highway 81, Easley, SC 29642')->toContain('All three age groups');
    }
});

it('discusses moving up after practice without promising a team change', function ($question) {
    $answer = $this->help->ask($this->tenant->id, $this->family, $question);
    expect($answer['needs_review'])->toBeFalse()
        ->and($answer['answer'])->toContain('assigned age groups')->toContain('John or Sarah')->toContain('After the first practice')->toContain('not guaranteed');
})->with(['Can my child move up to an older age group?', 'My child is advanced', 'Can my 7-year-old play with the older kids?', 'Can siblings be on the same team?']);

it('explains form eligibility accurately including older alumni and younger saved profiles', function ($question) {
    $answer = $this->help->ask($this->tenant->id, $this->family, $question);
    expect($answer['needs_review'])->toBeFalse()
        ->and($answer['answer'])->toContain('March 15, 2027')->toContain('New players must be ages 3–10')->toContain('through age 12')->toContain('younger children can still be saved')->toContain('678-644-1829');
})->with(['My child is 11. Can they play?', 'Can older children register?', 'My child is too old for the system', 'Why won’t the registration system let me add my child?', 'My child’s birth date isn’t accepted', 'The registration form won’t work']);

it('keeps birthday correction ahead of general age advice and does not promise an unsaved review', function () {
    expect($this->help->ask($this->tenant->id, $this->family, 'My child’s birthday is wrong')['action'])->toBe('correct_birthday');
    $answer = $this->help->ask($this->tenant->id, $this->family, 'Can you guarantee a special exception?');
    expect($answer['needs_review'])->toBeTrue()
        ->and($answer['answer'])->toContain('Sarah Collins')->not->toContain('saved');
    $event = $this->store->query('events', $this->tenant->id)->where('action', 'family_help_unanswered')->sole();
    expect($this->store->decrypt($event->data)['summary'])->toBe('Can you guarantee a special exception?');
});

it('gives AI verified dates and current status without family data or a claim to save records', function () {
    config()->set('soccer.help_ai_enabled', true);
    config()->set('services.openai.api_key', 'test-key');
    Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['can_answer' => false, 'answer' => ''])]]]]])]);
    $player = $this->store->insert('players', $this->tenant->id, ['family_id' => $this->family->id, 'name' => 'Private Child', 'birthday' => '2020-02-21', 'profile' => $this->store->encrypt([])]);
    $this->help->ask($this->tenant->id, $this->family, 'Which team is my child on?');
    $this->help->correctBirthday($this->tenant->id, $this->family, $player, '2019-02-21');
    $this->help->ask($this->tenant->id, $this->family, 'Do you have a tournament?');
    Http::assertSent(function ($request) {
        $instructions = $request['instructions'];

        return $request['store'] === false
            && str_contains($instructions, '8001 Highway 81')
            && str_contains($instructions, 'May 17, 2027')
            && str_contains($instructions, 'registration is currently closed')
            && str_contains($instructions, 'Never suggest exceptions to the minimum age')
            && str_contains($instructions, 'through age 12')
            && str_contains($instructions, 'Never claim to update a record or save a question')
            && ! str_contains(json_encode($request->data()), 'example@example.com')
            && ! str_contains(json_encode($request->data()), 'Private Child')
            && ! str_contains(json_encode($request->data()), 'February 21')
            && ! str_contains($request['input'], 'recent_conversation');
    });
});

it('rejects AI claims that it saved or changed a record', function ($claim) {
    config()->set('soccer.help_ai_enabled', true);
    config()->set('services.openai.api_key', 'test-key');
    Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['can_answer' => true, 'answer' => $claim])]]]]])]);
    $answer = $this->help->ask($this->tenant->id, $this->family, 'Can you arrange a tournament?');
    expect($answer['needs_review'])->toBeTrue()
        ->and($answer['answer'])->toContain('Sarah Collins')->not->toContain($claim);
})->with(['I’ve saved your question for review.', 'Your question has been saved for the team.', 'I updated your record.']);
