<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('trajectory_allocation_details')) {
            Schema::create('trajectory_allocation_details', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('transaction_id');
                $table->unsignedBigInteger('allocation_id');
                $table->unsignedBigInteger('space_id');
                $table->unsignedBigInteger('revision');
                $table->unsignedSmallInteger('line_index');
                $table->bigInteger('amount_cents');
                $table->string('category', 40);
                $table->unsignedBigInteger('subcategory_record_id')->nullable();
                $table->unsignedBigInteger('program_record_id')->nullable();
                $table->string('flow', 30);
                $table->string('settlement_status', 20)->default('none');
                $table->text('settlement_note')->nullable();
                $table->string('source_fingerprint', 64);
                $table->boolean('active')->default(true);
                $table->timestamp('invalidated_at')->nullable();
                $table->string('invalidation_reason', 80)->nullable();
                $table->timestamps();
            });
        }
        foreach ([
            'traj_detail_revision' => ['transaction_id', 'revision', 'line_index'],
            'traj_detail_tx_active' => ['transaction_id', 'active'],
            'traj_detail_space_active' => ['space_id', 'active'],
            'traj_detail_allocation' => ['allocation_id', 'active'],
        ] as $name => $columns) {
            $index = collect(Schema::getIndexes('trajectory_allocation_details'))->firstWhere('name', $name);
            if ($index && ($index['columns'] !== $columns || $index['unique'] !== ($name === 'traj_detail_revision'))) {
                throw new RuntimeException('Unexpected attribution index definition: '.$name);
            }
            if (! $index) {
                Schema::table('trajectory_allocation_details', function (Blueprint $table) use ($name, $columns): void {
                    $name === 'traj_detail_revision' ? $table->unique($columns, $name) : $table->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        // Attribution and its history survive an application rollback.
    }
};
