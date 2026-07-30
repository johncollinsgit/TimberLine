<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landlord_prospects', function (Blueprint $table): void {
            $table->string('google_place_id', 255)->nullable()->unique()->after('source');
            $table->string('google_maps_url', 500)->nullable()->after('google_place_id');
            $table->string('formatted_address', 500)->nullable()->after('google_maps_url');
            $table->string('website_status', 40)->nullable()->index()->after('website');
            $table->unsignedTinyInteger('fit_score')->nullable()->index()->after('website_status');
            $table->string('opportunity_priority', 20)->nullable()->index()->after('fit_score');
            $table->decimal('google_rating', 3, 2)->nullable()->after('opportunity_priority');
            $table->unsignedInteger('google_review_count')->nullable()->after('google_rating');
            $table->string('discovery_query', 255)->nullable()->after('google_review_count');
            $table->json('source_snapshot')->nullable()->after('discovery_query');
            $table->timestamp('last_verified_at')->nullable()->index()->after('source_snapshot');
        });

        Schema::create('landlord_prospect_discovery_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40)->default('google_places');
            $table->string('trade', 80)->index();
            $table->string('search_region', 160);
            $table->string('search_query', 255);
            $table->string('website_preference', 30)->default('missing_only')->index();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedSmallInteger('maximum_results')->default(10);
            $table->unsignedSmallInteger('api_request_count')->default(0);
            $table->decimal('estimated_api_cost', 10, 4)->default(0);
            $table->decimal('actual_api_cost', 10, 4)->default(0);
            $table->unsignedInteger('results_discovered')->default(0);
            $table->unsignedInteger('results_created')->default(0);
            $table->unsignedInteger('duplicates_suppressed')->default(0);
            $table->unsignedInteger('website_missing_count')->default(0);
            $table->json('source_log')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        $now = now();
        $prospects = [
            [
                'business_name' => 'Advance HVAC Services',
                'trade' => 'HVAC',
                'county' => 'Greenville',
                'city' => 'Piedmont',
                'phone' => '(877) 303-0638',
                'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query=Advance+HVAC+Services+Piedmont+SC',
                'formatted_address' => '117 Cooper Ln, Piedmont, SC',
                'google_rating' => 5.0,
                'google_review_count' => 2,
                'fit_score' => 76,
            ],
            [
                'business_name' => 'Heyward Electrical Services Inc',
                'trade' => 'Electrical',
                'county' => 'Greenville',
                'city' => 'Greenville',
                'phone' => '(864) 731-2461',
                'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query=Heyward+Electrical+Services+Inc+Greenville+SC',
                'formatted_address' => '1085 Park W Blvd, Greenville, SC',
                'google_rating' => 5.0,
                'google_review_count' => 2,
                'fit_score' => 76,
            ],
            [
                'business_name' => 'Garcia Landscape LLC',
                'trade' => 'Landscaping',
                'county' => 'Pickens',
                'city' => 'Easley',
                'phone' => '(864) 382-8680',
                'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query=Garcia+Landscape+LLC+Easley+SC',
                'formatted_address' => '105 Jyniece Ct, Easley, SC',
                'google_rating' => 4.7,
                'google_review_count' => 17,
                'fit_score' => 82,
            ],
        ];

        foreach ($prospects as $prospect) {
            if (DB::table('landlord_prospects')->where('phone', $prospect['phone'])->exists()) {
                continue;
            }

            DB::table('landlord_prospects')->insert(array_merge($prospect, [
                'website' => null,
                'website_status' => 'missing_verified',
                'opportunity_priority' => 'high',
                'status' => 'new',
                'source' => 'Google Maps manual verification',
                'discovery_query' => $prospect['trade'].' near '.$prospect['city'].' SC',
                'source_snapshot' => json_encode([
                    'provider' => 'google_maps',
                    'website_link_present' => false,
                    'verification_method' => 'Safari review of the current Google Maps result card',
                    'verified_at' => '2026-07-30T00:00:00-04:00',
                ], JSON_THROW_ON_ERROR),
                'last_verified_at' => $now,
                'notes' => 'Current Google Maps listing has public contact information and reviews but no linked website. Verify the listing again before outreach and lead with a useful website example, not an assumption about the business.',
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('landlord_prospect_discovery_runs');

        Schema::table('landlord_prospects', function (Blueprint $table): void {
            $table->dropUnique(['google_place_id']);
            $table->dropColumn([
                'google_place_id',
                'google_maps_url',
                'formatted_address',
                'website_status',
                'fit_score',
                'opportunity_priority',
                'google_rating',
                'google_review_count',
                'discovery_query',
                'source_snapshot',
                'last_verified_at',
            ]);
        });
    }
};
