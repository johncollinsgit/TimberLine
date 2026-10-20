<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_markets')) {
            Schema::create('marketplace_markets', function (Blueprint $t): void {
                $t->id();
                $t->string('name', 160);
                $t->string('slug', 180);
                $t->string('kind', 30)->default('market');
                $t->string('status', 30)->default('draft');
                $t->text('description')->nullable();
                $t->string('image_url', 1000)->nullable();
                $t->text('address');
                $t->string('timezone', 80)->default('America/New_York');
                $t->dateTime('starts_at');
                $t->dateTime('ends_at');
                $t->dateTime('pickup_cutoff_at')->nullable();
                $t->unsignedBigInteger('pickup_location_id')->nullable();
                $t->unsignedBigInteger('created_by');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('marketplace_market_makers')) {
            Schema::create('marketplace_market_makers', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('market_id');
                $t->unsignedBigInteger('maker_id');
                $t->string('status', 30)->default('applied');
                $t->string('booth', 100)->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('marketplace_store_links')) {
            Schema::create('marketplace_store_links', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('maker_id');
                $t->string('platform', 40);
                $t->string('url', 1000);
                $t->string('label', 160)->nullable();
                $t->string('status', 30)->default('shared');
                $t->unsignedBigInteger('identity_id');
                $t->timestamps();
            });
        }
        if (! Schema::hasColumn('marketplace_makers', 'private_details')) {
            Schema::table('marketplace_makers', fn (Blueprint $t) => $t->longText('private_details')->nullable());
        }
        if (! Schema::hasColumn('marketplace_markets', 'pickup_instructions')) {
            Schema::table('marketplace_markets', fn (Blueprint $t) => $t->text('pickup_instructions')->nullable());
        }
        if (! Schema::hasColumn('marketplace_orders', 'fulfillment_details')) {
            Schema::table('marketplace_orders', fn (Blueprint $t) => $t->longText('fulfillment_details')->nullable());
        }
        if (! Schema::hasColumn('marketplace_pickup_locations', 'tax_address')) {
            Schema::table('marketplace_pickup_locations', fn (Blueprint $t) => $t->json('tax_address')->nullable());
        }
        foreach ([['marketplace_markets', ['slug'], 'mm_market_slug', true], ['marketplace_markets', ['status', 'starts_at'], 'mm_market_upcoming', false], ['marketplace_market_makers', ['market_id', 'maker_id'], 'mm_market_maker', true], ['marketplace_store_links', ['maker_id', 'platform'], 'mm_store_link_maker', false]] as [$table,$columns,$key,$unique]) {
            if (! Schema::hasIndex($table, $key)) {
                Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($columns, $key) : $t->index($columns, $key));
            }
        }
    }

    public function down(): void
    { /* Preserve market participation and maker business records. */
    }
};
