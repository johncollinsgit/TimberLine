<?php

namespace Database\Factories;

use App\Models\MarketingProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MarketingProfileFactory extends Factory
{
    protected $model = MarketingProfile::class;

    public function definition(): array
    {
        $email = $this->faker->unique()->safeEmail();

        return [
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $email,
            'normalized_email' => Str::lower($email),
            'phone' => null,
            'normalized_phone' => null,
        ];
    }
}
