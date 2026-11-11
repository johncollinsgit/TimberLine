<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_properties')) {
            Schema::create('rental_properties', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('version')->default(0);
                $table->longText('payload');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('rental_events')) {
            Schema::create('rental_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->uuid('property_id');
                $table->unsignedBigInteger('actor_id');
                $table->uuid('request_key');
                $table->string('request_hash', 64);
                $table->unsignedBigInteger('version');
                $table->longText('payload');
                $table->timestamp('created_at');
            });
        }
        if (! Schema::hasIndex('rental_properties', 'rprop_tenant')) {
            Schema::table('rental_properties', fn (Blueprint $table) => $table->index('tenant_id', 'rprop_tenant'));
        }
        if (! Schema::hasIndex('rental_events', 'revent_request')) {
            Schema::table('rental_events', fn (Blueprint $table) => $table->unique(['tenant_id', 'property_id', 'actor_id', 'request_key'], 'revent_request'));
        }
        if (! Schema::hasIndex('rental_events', 'revent_version')) {
            Schema::table('rental_events', fn (Blueprint $table) => $table->unique(['property_id', 'version'], 'revent_version'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_events');
        Schema::dropIfExists('rental_properties');
    }
};
