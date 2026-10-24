<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Native domain/mailbox/grant schema only. No accounts, credentials, grants or inbound copies.
        if (! Schema::hasTable('tenant_mail_domains')) {
            Schema::create('tenant_mail_domains', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('domain', 253);
                $table->string('transport', 32)->default('direct');
                $table->string('status', 32)->default('pending_dns');
                $table->string('provider_domain_id', 120)->nullable();
                $table->json('dns_records')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('tenant_mailboxes')) {
            Schema::create('tenant_mailboxes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('tenant_mail_domain_id');
                $table->string('address', 320);
                $table->string('display_name', 120);
                $table->string('status', 32)->default('pending_domain');
                $table->string('provider_account_id', 120)->nullable();
                $table->text('provider_credentials')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('tenant_mailbox_users')) {
            Schema::create('tenant_mailbox_users', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('tenant_mailbox_id');
                $table->unsignedBigInteger('user_id');
                $table->string('permission', 16)->default('read_only');
                $table->timestamps();
            });
        }
        $this->index('tenant_mail_domains', ['domain'], 'ee_mail_domain', true);
        $this->index('tenant_mail_domains', ['tenant_id', 'status'], 'ee_mail_domain_status');
        $this->index('tenant_mailboxes', ['address'], 'ee_mail_address', true);
        $this->index('tenant_mailboxes', ['tenant_id', 'status'], 'ee_mail_status');
        $this->index('tenant_mailbox_users', ['tenant_mailbox_id', 'user_id'], 'ee_mail_user', true);
        $this->index('tenant_mailbox_users', ['tenant_id', 'user_id'], 'ee_mail_user_tenant');
        $this->foreign('tenant_mail_domains', 'tenant_id', 'tenants', 'ee_mail_domain_tenant_fk');
        $this->foreign('tenant_mailboxes', 'tenant_id', 'tenants', 'ee_mail_tenant_fk');
        $this->foreign('tenant_mailboxes', 'tenant_mail_domain_id', 'tenant_mail_domains', 'ee_mail_domain_fk');
        $this->foreign('tenant_mailbox_users', 'tenant_id', 'tenants', 'ee_mail_grant_tenant_fk');
        $this->foreign('tenant_mailbox_users', 'tenant_mailbox_id', 'tenant_mailboxes', 'ee_mail_grant_box_fk');
        $this->foreign('tenant_mailbox_users', 'user_id', 'users', 'ee_mail_grant_user_fk');
    }

    private function index(string $table, array $columns, string $name, bool $unique = false): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] === $name) {
                if ($index['columns'] !== $columns || (bool) $index['unique'] !== $unique) {
                    throw new RuntimeException('Enterprise mailbox index mismatch: '.$name);
                }

                return;
            }
        }
        Schema::table($table, fn (Blueprint $blueprint) => $unique ? $blueprint->unique($columns, $name) : $blueprint->index($columns, $name));
    }

    private function foreign(string $table, string $column, string $parent, string $name): void
    {
        foreach (Schema::getForeignKeys($table) as $foreign) {
            if (($foreign['name'] ?? null) === $name || $foreign['columns'] === [$column]) {
                if ($foreign['columns'] !== [$column] || $foreign['foreign_table'] !== $parent || $foreign['foreign_columns'] !== ['id']
                    || strtolower($foreign['on_delete']) !== 'cascade') {
                    throw new RuntimeException('Enterprise mailbox constraint mismatch: '.$name);
                }

                return;
            }
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column, $name)->references('id')->on($parent)->cascadeOnDelete());
    }

    public function down(): void
    {
        // Code rollback must retain domain proof, encrypted mailbox details and explicit grant evidence.
    }
};
