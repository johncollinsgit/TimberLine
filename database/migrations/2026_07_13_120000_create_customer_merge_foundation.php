<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_merge_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('store_key', 80)->nullable()->index();
            $table->string('status', 40)->default('draft')->index();
            $table->string('source', 80)->default('everbranch_wizard')->index();
            $table->string('idempotency_key', 190);
            $table->unsignedBigInteger('survivor_profile_id')->nullable()->index();
            $table->string('shopify_kept_customer_gid', 190)->nullable()->index();
            $table->string('shopify_deleted_customer_gid', 190)->nullable()->index();
            $table->string('shopify_job_id', 190)->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('shopify_admin_user_id', 190)->nullable();
            $table->json('field_choices')->nullable();
            $table->json('reward_resolution')->nullable();
            $table->json('shopify_preview')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key'], 'customer_merge_operation_tenant_idempotency_unique');
        });

        Schema::create('customer_merge_operation_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_merge_operation_id')->constrained('customer_merge_operations')->cascadeOnDelete();
            $table->unsignedBigInteger('marketing_profile_id')->index();
            $table->string('shopify_customer_gid', 190)->nullable()->index();
            $table->string('role', 40)->default('donor');
            $table->string('outcome', 40)->default('pending');
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->unique(
                ['customer_merge_operation_id', 'marketing_profile_id'],
                'customer_merge_member_operation_profile_unique'
            );
        });

        Schema::table('marketing_profiles', function (Blueprint $table): void {
            $table->string('normalized_first_name')->nullable()->index()->after('last_name');
            $table->string('normalized_last_name')->nullable()->index()->after('normalized_first_name');
            $table->string('first_name_phonetic', 120)->nullable()->index()->after('normalized_last_name');
            $table->string('last_name_phonetic', 120)->nullable()->index()->after('first_name_phonetic');
            $table->json('tags')->nullable()->after('notes');
            $table->foreignId('merged_into_profile_id')->nullable()->after('tags')->constrained('marketing_profiles')->nullOnDelete();
            $table->foreignId('merge_operation_id')->nullable()->after('merged_into_profile_id')->constrained('customer_merge_operations')->nullOnDelete();
            $table->timestamp('merged_at')->nullable()->after('merge_operation_id')->index();
        });

        DB::table('marketing_profiles')
            ->orderBy('id')
            ->chunkById(500, function ($profiles): void {
                foreach ($profiles as $profile) {
                    $first = $this->normalizeName((string) ($profile->first_name ?? ''));
                    $last = $this->normalizeName((string) ($profile->last_name ?? ''));
                    DB::table('marketing_profiles')->where('id', $profile->id)->update([
                        'normalized_first_name' => $first ?: null,
                        'normalized_last_name' => $last ?: null,
                        'first_name_phonetic' => $first !== '' ? metaphone($first) : null,
                        'last_name_phonetic' => $last !== '' ? metaphone($last) : null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('marketing_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('merge_operation_id');
            $table->dropConstrainedForeignId('merged_into_profile_id');
            $table->dropColumn([
                'normalized_first_name',
                'normalized_last_name',
                'first_name_phonetic',
                'last_name_phonetic',
                'tags',
                'merged_at',
            ]);
        });

        Schema::dropIfExists('customer_merge_operation_members');
        Schema::dropIfExists('customer_merge_operations');
    }

    private function normalizeName(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($value))) ?? '');
    }
};
