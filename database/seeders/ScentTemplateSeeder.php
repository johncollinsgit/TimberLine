<?php

namespace Database\Seeders;

use App\Models\ScentTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class ScentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('scent_templates')) {
            return;
        }

        ScentTemplate::query()->firstOrCreate(
            [
                'type' => 'top_shelf',
                'is_default' => true,
            ],
            [
                'name' => 'Top Shelf Default',
                'configuration' => [],
            ]
        );
    }
}
