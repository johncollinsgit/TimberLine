<?php

namespace App\Services\Mobile;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileScentQuizResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ModernForestryMobileScentQuizService
{
    public const QUIZ_VERSION = 'scent-v1';

    /**
     * @var array<int,array{id:string,label:string}>
     */
    protected const AXES = [
        ['id' => 'floral', 'label' => 'Floral'],
        ['id' => 'woodsy', 'label' => 'Woodsy'],
        ['id' => 'smoky', 'label' => 'Smoky'],
        ['id' => 'sweet', 'label' => 'Sweet'],
        ['id' => 'masculine', 'label' => 'Masculine'],
        ['id' => 'earthy', 'label' => 'Earthy'],
        ['id' => 'clean', 'label' => 'Clean'],
        ['id' => 'citrus', 'label' => 'Citrus'],
    ];

    /**
     * @return array<string,mixed>
     */
    public function definition(MarketingProfile $profile): array
    {
        return [
            'version' => self::QUIZ_VERSION,
            'intro' => [
                'title' => 'Find your scent personality',
                'body' => 'A 25-question profile that turns candle taste into a scent map, dominant traits, and personality-style copy.',
            ],
            'axes' => array_map(
                static fn (array $axis): array => [
                    'id' => $axis['id'],
                    'label' => $axis['label'],
                ],
                self::AXES
            ),
            'questions' => $this->questions(),
            'latestResult' => $this->latestResultPayload($profile),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $answers
     * @return array<string,mixed>
     */
    public function saveResult(MarketingProfile $profile, array $answers): array
    {
        $questions = collect($this->questions())->keyBy('id');
        $submitted = collect($answers)
            ->filter(fn (mixed $answer): bool => is_array($answer))
            ->mapWithKeys(function (array $answer): array {
                $questionId = trim((string) ($answer['question_id'] ?? ''));
                $optionId = trim((string) ($answer['option_id'] ?? ''));

                return $questionId !== '' && $optionId !== '' ? [$questionId => $optionId] : [];
            });

        $missing = $questions->keys()->filter(fn (string $id): bool => ! $submitted->has($id))->values()->all();
        if ($missing !== []) {
            throw new \InvalidArgumentException('Every scent quiz question must be answered before saving results.');
        }

        $axisScores = array_fill_keys(array_column(self::AXES, 'id'), 0);
        $answerPayload = [];

        foreach ($questions as $questionId => $question) {
            $selectedOptionId = (string) $submitted->get($questionId);
            $option = collect((array) ($question['options'] ?? []))
                ->first(fn (mixed $candidate): bool => is_array($candidate) && (string) ($candidate['id'] ?? '') === $selectedOptionId);

            if (! is_array($option)) {
                throw new \InvalidArgumentException('A selected scent quiz answer did not match an available option.');
            }

            foreach ((array) ($option['weights'] ?? []) as $axisId => $weight) {
                if (! array_key_exists($axisId, $axisScores)) {
                    continue;
                }

                $axisScores[$axisId] += max(0, (int) $weight);
            }

            $answerPayload[] = [
                'question_id' => $questionId,
                'option_id' => $selectedOptionId,
            ];
        }

        $normalizedScores = $this->normalizedAxisScores($axisScores, $questions->values()->all());
        arsort($normalizedScores);

        $dominantTraits = collect($normalizedScores)
            ->filter(fn (int $score): bool => $score > 0)
            ->keys()
            ->take(3)
            ->values()
            ->all();

        $headline = $this->headline($dominantTraits);
        $personality = $this->personalityCopy($dominantTraits, $normalizedScores);

        $record = MarketingProfileScentQuizResult::query()->updateOrCreate(
            ['marketing_profile_id' => $profile->id],
            [
                'tenant_id' => $profile->tenant_id,
                'quiz_version' => self::QUIZ_VERSION,
                'axis_scores' => $normalizedScores,
                'dominant_traits' => $dominantTraits,
                'headline' => $headline,
                'personality_title' => $personality['title'],
                'personality_body' => $personality['body'],
                'answers' => $answerPayload,
                'completed_at' => now(),
            ]
        );

        return $this->resultPayload($record);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function latestResultPayload(MarketingProfile $profile): ?array
    {
        $result = $profile->relationLoaded('scentQuizResult')
            ? $profile->scentQuizResult
            : $profile->scentQuizResult()->first();

        if (! $result instanceof MarketingProfileScentQuizResult) {
            return null;
        }

        return $this->resultPayload($result);
    }

    /**
     * @return array<string,mixed>
     */
    protected function resultPayload(MarketingProfileScentQuizResult $result): array
    {
        $scores = is_array($result->axis_scores) ? $result->axis_scores : [];
        $traits = is_array($result->dominant_traits) ? array_values($result->dominant_traits) : [];

        return [
            'version' => (string) ($result->quiz_version ?: self::QUIZ_VERSION),
            'headline' => (string) ($result->headline ?: $this->headline($traits)),
            'personalityTitle' => (string) ($result->personality_title ?: 'Scent personality'),
            'personalityBody' => (string) ($result->personality_body ?: 'Your scent personality will update as you take the quiz again.'),
            'dominantTraits' => $traits,
            'axes' => array_map(function (array $axis) use ($scores): array {
                return [
                    'id' => $axis['id'],
                    'label' => $axis['label'],
                    'score' => max(0, min(100, (int) ($scores[$axis['id']] ?? 0))),
                ];
            }, self::AXES),
            'completedAt' => optional($result->completed_at)->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,int>  $rawScores
     * @param  array<int,array<string,mixed>>  $questions
     * @return array<string,int>
     */
    protected function normalizedAxisScores(array $rawScores, array $questions): array
    {
        $maximums = array_fill_keys(array_keys($rawScores), 0);

        foreach ($questions as $question) {
            foreach ($maximums as $axisId => $currentMax) {
                $questionMax = collect((array) ($question['options'] ?? []))
                    ->map(function (mixed $option) use ($axisId): int {
                        if (! is_array($option)) {
                            return 0;
                        }

                        return max(0, (int) Arr::get($option, 'weights.'.$axisId, 0));
                    })
                    ->max();

                $maximums[$axisId] = $currentMax + max(0, (int) $questionMax);
            }
        }

        $normalized = [];

        foreach ($rawScores as $axisId => $score) {
            $max = max(1, (int) ($maximums[$axisId] ?? 1));
            $normalized[$axisId] = (int) round(($score / $max) * 100);
        }

        return $normalized;
    }

    /**
     * @param  array<int,string>  $dominantTraits
     */
    protected function headline(array $dominantTraits): string
    {
        if (count($dominantTraits) >= 2) {
            return $this->axisLabel($dominantTraits[0]).' + '.$this->axisLabel($dominantTraits[1]);
        }

        if (isset($dominantTraits[0])) {
            return $this->axisLabel($dominantTraits[0]).' signature';
        }

        return 'Still discovering your scent profile';
    }

    /**
     * @param  array<int,string>  $dominantTraits
     * @param  array<string,int>  $scores
     * @return array{title:string,body:string}
     */
    protected function personalityCopy(array $dominantTraits, array $scores): array
    {
        $primary = $dominantTraits[0] ?? 'clean';
        $secondary = $dominantTraits[1] ?? null;
        $accent = $dominantTraits[2] ?? null;

        $primaryTemplates = [
            'floral' => [
                'title' => 'The Romantic Curator',
                'body' => 'You gravitate toward beauty that feels intentional, expressive, and warm. Your scent style suggests someone who notices the atmosphere of a room and wants it to feel inviting, graceful, and a little memorable.',
            ],
            'woodsy' => [
                'title' => 'The Grounded Explorer',
                'body' => 'You lean into calm, depth, and texture. Your scent style reads like someone who values substance over noise and likes spaces that feel rooted, restorative, and quietly elevated.',
            ],
            'smoky' => [
                'title' => 'The Moody Minimalist',
                'body' => 'You prefer candles with edge, atmosphere, and a little drama. Your scent profile suggests someone confident in their taste who likes a room to feel cinematic, intimate, and unmistakably theirs.',
            ],
            'sweet' => [
                'title' => 'The Comfort Host',
                'body' => 'You are drawn to warmth, familiarity, and easy delight. Your scent taste suggests someone generous and inviting who wants home to feel soft, welcoming, and impossible to leave too quickly.',
            ],
            'masculine' => [
                'title' => 'The Bold Classic',
                'body' => 'You like structure, richness, and presence. Your scent profile suggests someone who values timeless style, steady confidence, and candles that make a room feel instantly more polished.',
            ],
            'earthy' => [
                'title' => 'The Restorative Soul',
                'body' => 'You are pulled toward natural, grounding scents that calm the nervous system. Your fragrance taste suggests someone reflective, centered, and happiest when their environment feels deeply lived-in and real.',
            ],
            'clean' => [
                'title' => 'The Crisp Editor',
                'body' => 'You like clarity, lightness, and a sense of order. Your scent profile suggests someone who appreciates fresh starts, polished spaces, and candles that make everything feel a little more put together.',
            ],
            'citrus' => [
                'title' => 'The Bright Optimist',
                'body' => 'You lean toward energy, sparkle, and lift. Your scent style suggests someone playful and momentum-driven who wants their space to feel alive, upbeat, and ready for whatever is next.',
            ],
        ];

        $secondaryCopy = $secondary
            ? ' The '.$this->axisLabel($secondary).' streak adds '.Str::lower($this->secondaryDescriptor($secondary)).'.'
            : '';
        $accentCopy = $accent && (($scores[$accent] ?? 0) >= 45)
            ? ' '.$this->axisLabel($accent).' also shows up as a supporting note, giving the profile extra range.'
            : '';

        $template = $primaryTemplates[$primary] ?? $primaryTemplates['clean'];

        return [
            'title' => $template['title'],
            'body' => $template['body'].$secondaryCopy.$accentCopy,
        ];
    }

    protected function secondaryDescriptor(string $axisId): string
    {
        return match ($axisId) {
            'floral' => 'a softer, expressive edge',
            'woodsy' => 'more depth and calm',
            'smoky' => 'a moodier finish',
            'sweet' => 'extra coziness',
            'masculine' => 'a stronger tailored feel',
            'earthy' => 'a natural grounding note',
            'clean' => 'a polished brightness',
            'citrus' => 'a brighter lift',
            default => 'a subtle extra note',
        };
    }

    protected function axisLabel(string $axisId): string
    {
        foreach (self::AXES as $axis) {
            if ($axis['id'] === $axisId) {
                return $axis['label'];
            }
        }

        return Str::title(str_replace('-', ' ', $axisId));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function questions(): array
    {
        return [
            $this->question('q01', 'What kind of room feels most like you?', [
                $this->option('garden', 'Bright, airy, and full of fresh flowers.', ['floral' => 3, 'clean' => 1]),
                $this->option('cabin', 'Warm wood, texture, and a little quiet.', ['woodsy' => 3, 'earthy' => 1]),
                $this->option('studio', 'Clean lines, sun, and open windows.', ['clean' => 3, 'citrus' => 1]),
                $this->option('lounge', 'Dim lighting, depth, and a touch of drama.', ['smoky' => 3, 'masculine' => 1]),
            ]),
            $this->question('q02', 'Which weekend plan sounds best?', [
                $this->option('brunch', 'Brunch and a flower market.', ['floral' => 2, 'citrus' => 2]),
                $this->option('hike', 'A trail, trees, and fresh air.', ['woodsy' => 2, 'earthy' => 2]),
                $this->option('reset', 'Cleaning up, organizing, and resetting the house.', ['clean' => 3]),
                $this->option('firepit', 'A backyard fire and late-night conversation.', ['smoky' => 2, 'sweet' => 1, 'masculine' => 1]),
            ]),
            $this->question('q03', 'Choose a favorite flavor profile.', [
                $this->option('berry', 'Soft fruit and floral tea.', ['floral' => 2, 'sweet' => 1]),
                $this->option('bourbon', 'Oak, spice, and something rich.', ['woodsy' => 1, 'masculine' => 2, 'smoky' => 1]),
                $this->option('citrus', 'Lemon, orange, and sparkling brightness.', ['citrus' => 3, 'clean' => 1]),
                $this->option('dessert', 'Vanilla, cream, and bakery comfort.', ['sweet' => 3]),
            ]),
            $this->question('q04', 'What should a candle do first?', [
                $this->option('calm', 'Calm me down.', ['earthy' => 2, 'woodsy' => 1, 'clean' => 1]),
                $this->option('lift', 'Wake the room up.', ['citrus' => 2, 'clean' => 2]),
                $this->option('cozy', 'Make everything feel cozy instantly.', ['sweet' => 2, 'smoky' => 1, 'woodsy' => 1]),
                $this->option('elevate', 'Make the space feel more elevated.', ['masculine' => 2, 'floral' => 1, 'woodsy' => 1]),
            ]),
            $this->question('q05', 'Pick a texture.', [
                $this->option('silk', 'Silk or soft petals.', ['floral' => 3]),
                $this->option('linen', 'Fresh linen and crisp cotton.', ['clean' => 3]),
                $this->option('leather', 'Leather, suede, or worn wood.', ['masculine' => 2, 'smoky' => 1, 'woodsy' => 1]),
                $this->option('stone', 'Stone, clay, or raw ceramic.', ['earthy' => 3]),
            ]),
            $this->question('q06', 'Which season do you decorate for hardest?', [
                $this->option('spring', 'Spring, when everything feels alive again.', ['floral' => 2, 'clean' => 1, 'citrus' => 1]),
                $this->option('summer', 'Summer, for the energy and sunshine.', ['citrus' => 3, 'clean' => 1]),
                $this->option('autumn', 'Autumn, for warmth and depth.', ['woodsy' => 2, 'smoky' => 1, 'earthy' => 1]),
                $this->option('winter', 'Winter, for coziness and mood.', ['sweet' => 2, 'smoky' => 2]),
            ]),
            $this->question('q07', 'What kind of gift do you usually give?', [
                $this->option('thoughtful', 'Something sentimental and personal.', ['floral' => 2, 'sweet' => 1]),
                $this->option('useful', 'Something beautiful but practical.', ['clean' => 2, 'earthy' => 1]),
                $this->option('luxury', 'Something rich, polished, or impressive.', ['masculine' => 2, 'woodsy' => 1]),
                $this->option('cozy', 'Something comforting everyone wants to keep.', ['sweet' => 2, 'woodsy' => 1]),
            ]),
            $this->question('q08', 'Pick a color palette.', [
                $this->option('petals', 'Blush, ivory, and muted green.', ['floral' => 3]),
                $this->option('forest', 'Olive, bark, and deep brown.', ['woodsy' => 2, 'earthy' => 1]),
                $this->option('charcoal', 'Black, tobacco, and bronze.', ['smoky' => 2, 'masculine' => 2]),
                $this->option('sunlit', 'Cream, gold, and pale citrus.', ['citrus' => 2, 'clean' => 1, 'sweet' => 1]),
            ]),
            $this->question('q09', 'How do you want guests to describe your home?', [
                $this->option('beautiful', 'Beautiful and thoughtfully styled.', ['floral' => 2, 'clean' => 1]),
                $this->option('cozy', 'Warm and comfortable.', ['sweet' => 2, 'woodsy' => 1]),
                $this->option('cool', 'Distinctive, moody, and cool.', ['smoky' => 2, 'masculine' => 1]),
                $this->option('fresh', 'Fresh, bright, and put together.', ['clean' => 2, 'citrus' => 1]),
            ]),
            $this->question('q10', 'Which natural note appeals most?', [
                $this->option('petals', 'Fresh petals.', ['floral' => 3]),
                $this->option('moss', 'Mossy earth after rain.', ['earthy' => 2, 'woodsy' => 1]),
                $this->option('cedar', 'Dry cedar and bark.', ['woodsy' => 3]),
                $this->option('orange', 'Orange peel and zest.', ['citrus' => 3]),
            ]),
            $this->question('q11', 'What kind of energy are you usually bringing?', [
                $this->option('gentle', 'Warm, soft, and welcoming.', ['floral' => 1, 'sweet' => 2]),
                $this->option('steady', 'Grounded and dependable.', ['woodsy' => 2, 'earthy' => 2]),
                $this->option('sharp', 'Clear, focused, and organized.', ['clean' => 3]),
                $this->option('bold', 'Confident and memorable.', ['masculine' => 2, 'smoky' => 1, 'citrus' => 1]),
            ]),
            $this->question('q12', 'Pick a morning ritual.', [
                $this->option('tea', 'Tea, sunlight, and a quiet start.', ['floral' => 2, 'clean' => 1]),
                $this->option('walk', 'A walk outside before everything starts.', ['earthy' => 2, 'woodsy' => 1]),
                $this->option('coffee', 'Strong coffee and a playlist.', ['masculine' => 1, 'smoky' => 2, 'sweet' => 1]),
                $this->option('juice', 'Cold citrus and fresh air.', ['citrus' => 3]),
            ]),
            $this->question('q13', 'Which word sounds most like your style?', [
                $this->option('romantic', 'Romantic', ['floral' => 3]),
                $this->option('tailored', 'Tailored', ['masculine' => 3]),
                $this->option('natural', 'Natural', ['earthy' => 2, 'woodsy' => 1]),
                $this->option('fresh', 'Fresh', ['clean' => 2, 'citrus' => 1]),
            ]),
            $this->question('q14', 'Choose a favorite evening setting.', [
                $this->option('porch', 'A breezy porch at golden hour.', ['citrus' => 2, 'clean' => 1]),
                $this->option('fireside', 'Fireside with blankets and low light.', ['smoky' => 2, 'sweet' => 1, 'woodsy' => 1]),
                $this->option('bath', 'A quiet bath with softer details.', ['floral' => 2, 'clean' => 1]),
                $this->option('reading', 'A book, lamplight, and total calm.', ['earthy' => 2, 'woodsy' => 1]),
            ]),
            $this->question('q15', 'What kind of packaging catches your eye?', [
                $this->option('elegant', 'Elegant and delicate.', ['floral' => 2, 'clean' => 1]),
                $this->option('minimal', 'Minimal and crisp.', ['clean' => 3]),
                $this->option('heritage', 'Heritage-inspired and substantial.', ['masculine' => 2, 'woodsy' => 1]),
                $this->option('handmade', 'Organic and artisanal.', ['earthy' => 2, 'sweet' => 1]),
            ]),
            $this->question('q16', 'Which dessert sounds best?', [
                $this->option('shortcake', 'Berry shortcake.', ['sweet' => 2, 'floral' => 1]),
                $this->option('citrus', 'Lemon tart.', ['citrus' => 3]),
                $this->option('smores', 'Something toasted by a fire.', ['smoky' => 2, 'sweet' => 1]),
                $this->option('none', 'Not dessert, give me something earthy or dry.', ['woodsy' => 1, 'earthy' => 2]),
            ]),
            $this->question('q17', 'Which store section do you wander into first?', [
                $this->option('home', 'Fresh home goods and textiles.', ['clean' => 2, 'floral' => 1]),
                $this->option('outdoors', 'Outdoor gear or natural materials.', ['woodsy' => 2, 'earthy' => 1]),
                $this->option('apothecary', 'Apothecary, grooming, or rich fragrances.', ['masculine' => 2, 'smoky' => 1]),
                $this->option('food', 'Bakery or specialty treats.', ['sweet' => 3]),
            ]),
            $this->question('q18', 'What do you want a signature scent to say?', [
                $this->option('soft', 'I am thoughtful and warm.', ['floral' => 2, 'sweet' => 1]),
                $this->option('steady', 'I am grounded and calm.', ['earthy' => 2, 'woodsy' => 1]),
                $this->option('sharp', 'I am polished and intentional.', ['clean' => 2, 'masculine' => 1]),
                $this->option('alive', 'I am energetic and impossible to ignore.', ['citrus' => 2, 'smoky' => 1]),
            ]),
            $this->question('q19', 'Pick a travel mood.', [
                $this->option('garden-city', 'A beautiful city in bloom.', ['floral' => 2, 'citrus' => 1]),
                $this->option('mountains', 'A mountain cabin.', ['woodsy' => 2, 'smoky' => 1]),
                $this->option('spa', 'A coastal spa with white linen.', ['clean' => 2, 'earthy' => 1]),
                $this->option('nightlife', 'A late-night hotel bar.', ['masculine' => 2, 'smoky' => 2]),
            ]),
            $this->question('q20', 'How strong should a candle personality be?', [
                $this->option('subtle', 'Subtle and soft.', ['clean' => 2, 'floral' => 1]),
                $this->option('comforting', 'Comforting and present.', ['sweet' => 2, 'earthy' => 1]),
                $this->option('grounded', 'Grounded and layered.', ['woodsy' => 2, 'earthy' => 1]),
                $this->option('statement', 'A statement piece.', ['smoky' => 2, 'masculine' => 2]),
            ]),
            $this->question('q21', 'Choose a favorite weather day.', [
                $this->option('sunny-bloom', 'Sunny with flowers opening.', ['floral' => 2, 'citrus' => 1]),
                $this->option('cool-forest', 'Cool and shaded under trees.', ['woodsy' => 2, 'earthy' => 1]),
                $this->option('clean-air', 'Crisp air after a deep clean kind of morning.', ['clean' => 3]),
                $this->option('storm', 'Stormy skies and candlelight.', ['smoky' => 2, 'sweet' => 1]),
            ]),
            $this->question('q22', 'What kind of compliment lands best?', [
                $this->option('beautiful', 'Beautiful taste.', ['floral' => 2]),
                $this->option('cozy', 'Your place feels so cozy.', ['sweet' => 2, 'woodsy' => 1]),
                $this->option('cool', 'Your style is cool.', ['smoky' => 2, 'masculine' => 1]),
                $this->option('fresh', 'Everything feels fresh and elevated.', ['clean' => 2, 'citrus' => 1]),
            ]),
            $this->question('q23', 'Pick a candle companion.', [
                $this->option('flowers', 'Fresh stems on the table.', ['floral' => 3]),
                $this->option('books', 'A stack of books and a throw blanket.', ['woodsy' => 1, 'earthy' => 2]),
                $this->option('playlist', 'A sharp playlist and clean counters.', ['clean' => 2, 'citrus' => 1]),
                $this->option('cocktail', 'A cocktail and low music.', ['masculine' => 2, 'smoky' => 1]),
            ]),
            $this->question('q24', 'How do you reset after a long week?', [
                $this->option('treat', 'Bake something or order dessert.', ['sweet' => 3]),
                $this->option('tidy', 'Tidy the house and open windows.', ['clean' => 2, 'citrus' => 1]),
                $this->option('nature', 'Go outside and get quiet.', ['earthy' => 2, 'woodsy' => 1]),
                $this->option('mood', 'Dim the lights and set a mood.', ['smoky' => 2, 'masculine' => 1]),
            ]),
            $this->question('q25', 'Which final phrase feels most like home?', [
                $this->option('soft-light', 'Soft light and bloom.', ['floral' => 2, 'sweet' => 1]),
                $this->option('clean-sheet', 'Fresh sheets and open air.', ['clean' => 2, 'citrus' => 1]),
                $this->option('forest-floor', 'Forest floor and worn wood.', ['woodsy' => 2, 'earthy' => 2]),
                $this->option('embers', 'Embers, leather, and nightfall.', ['smoky' => 2, 'masculine' => 2]),
            ]),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $options
     * @return array<string,mixed>
     */
    protected function question(string $id, string $prompt, array $options): array
    {
        return [
            'id' => $id,
            'prompt' => $prompt,
            'options' => $options,
        ];
    }

    /**
     * @param  array<string,int>  $weights
     * @return array<string,mixed>
     */
    protected function option(string $id, string $label, array $weights): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'weights' => $weights,
        ];
    }
}
