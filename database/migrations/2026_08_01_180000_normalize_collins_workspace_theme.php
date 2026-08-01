<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $collinsTenantIds = DB::table('tenants')
            ->whereIn('slug', ['collins-electric', 'collins-upstate-electric'])
            ->pluck('id');

        if ($collinsTenantIds->isEmpty()) {
            return;
        }

        DB::table('tenant_brand_profiles')
            ->whereIn('tenant_id', $collinsTenantIds)
            ->update([
                'primary_color' => '#123C43',
                'accent_color' => '#1E5A63',
                'surface_color' => '#FFFFFF',
                'text_color' => '#0F1C1F',
                'display_style' => 'classic',
                'corner_style' => 'soft',
                'decor_preset' => 'none',
                'theme_key' => 'custom',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // The previous bespoke palette is intentionally not restored.
    }
};
