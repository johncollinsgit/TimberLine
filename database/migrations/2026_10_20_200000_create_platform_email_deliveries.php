<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_email_messages')) {
            Schema::create('platform_email_messages', function (Blueprint $table): void {
                $table->id();
                $table->char('group_key', 64);
                $table->char('payload_hash', 64);
                $table->longText('mime');
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->string('from_email', 254);
                $table->string('configuration_set', 64);
                $table->timestamps();
            });
        }
        $this->index('platform_email_messages', 'pem_group_uq', ['group_key'], true);
        if (! Schema::hasTable('platform_email_deliveries')) {
            Schema::create('platform_email_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('platform_email_message_id');
                $table->char('recipient_hash', 64);
                $table->text('recipient');
                $table->string('provider_message_id', 255)->nullable();
                $table->string('state', 16)->default('queued');
                $table->string('hold_reason', 64)->nullable();
                $table->string('event_state', 32)->nullable();
                $table->json('event_hashes')->nullable();
                $table->unsignedInteger('provider_cost_micros');
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('event_at')->nullable();
                $table->timestamps();
            });
        }
        foreach ([
            'ped_message_recipient_uq' => ['platform_email_message_id', 'recipient_hash'],
            'ped_recipient_state_idx' => ['recipient_hash', 'state'],
            'ped_pending_idx' => ['state', 'created_at'],
        ] as $name => $columns) {
            $this->index('platform_email_deliveries', $name, $columns, $name === 'ped_message_recipient_uq');
        }
        $foreign = collect(Schema::getForeignKeys('platform_email_deliveries'))->firstWhere('name', 'ped_message_fk');
        if ($foreign && ($foreign['columns'] !== ['platform_email_message_id'] || $foreign['foreign_table'] !== 'platform_email_messages'
            || $foreign['foreign_columns'] !== ['id'] || strtolower($foreign['on_delete']) !== 'restrict')) {
            throw new RuntimeException('Platform mail parent constraint differs from the reviewed schema.');
        }
        if (! $foreign) {
            Schema::table('platform_email_deliveries', fn (Blueprint $table) => $table->foreign('platform_email_message_id', 'ped_message_fk')->references('id')->on('platform_email_messages')->restrictOnDelete());
        }
    }

    private function index(string $table, string $name, array $columns, bool $unique): void
    {
        $existing = collect(Schema::getIndexes($table))->firstWhere('name', $name);
        if ($existing && ($existing['columns'] !== $columns || $existing['unique'] !== $unique)) {
            throw new RuntimeException('Platform mail index differs from the reviewed schema: '.$name);
        }
        if (! $existing) {
            Schema::table($table, fn (Blueprint $blueprint) => $unique ? $blueprint->unique($columns, $name) : $blueprint->index($columns, $name));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_email_deliveries');
        Schema::dropIfExists('platform_email_messages');
    }
};
