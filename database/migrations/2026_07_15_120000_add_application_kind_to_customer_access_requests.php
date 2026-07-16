<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_access_requests') || Schema::hasColumn('customer_access_requests', 'application_kind')) {
            return;
        }

        Schema::table('customer_access_requests', function (Blueprint $table): void {
            $table->string('application_kind', 40)
                ->default('platform_access')
                ->after('intent')
                ->index();
        });

        if (Schema::hasTable('form_submissions')) {
            DB::table('customer_access_requests')
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('form_submissions')
                        ->whereColumn('form_submissions.customer_access_request_id', 'customer_access_requests.id')
                        ->where(function ($wholesale): void {
                            $wholesale->where('form_submissions.source', 'wholesale_storefront')
                                ->orWhereExists(function ($form): void {
                                    $form->selectRaw('1')
                                        ->from('tenant_forms')
                                        ->join('form_templates', 'form_templates.id', '=', 'tenant_forms.form_template_id')
                                        ->whereColumn('tenant_forms.id', 'form_submissions.tenant_form_id')
                                        ->where(function ($template): void {
                                            $template->where('form_templates.key', 'wholesale_application')
                                                ->orWhere('form_templates.handler_key', 'wholesale_application');
                                        });
                                });
                        });
                })
                ->update(['application_kind' => 'wholesale_application']);
        }

        DB::table('customer_access_requests')
            ->where('requested_tenant_slug', 'modern-forestry-wholesale')
            ->update(['application_kind' => 'wholesale_application']);

        DB::table('customer_access_requests')
            ->where('requested_tenant_slug', 'modern-forestry')
            ->where('application_kind', 'platform_access')
            ->orderBy('id')
            ->chunkById(250, function ($rows): void {
                foreach ($rows as $row) {
                    $metadata = json_decode((string) ($row->metadata ?? ''), true);
                    $metadata = is_array($metadata) ? $metadata : [];
                    $wholesaleSignals = [
                        'phone',
                        'address',
                        'address2',
                        'retail_license_number',
                        'position',
                        'referral',
                        'current_suppliers',
                        'contact_preference',
                    ];
                    $recognized = collect($wholesaleSignals)->contains(
                        fn (string $key): bool => trim((string) ($metadata[$key] ?? '')) !== ''
                    );
                    if (! $recognized && ! str_starts_with(trim((string) ($row->message ?? '')), 'Wholesale Application')) {
                        continue;
                    }

                    DB::table('customer_access_requests')
                        ->where('id', (int) $row->id)
                        ->update(['application_kind' => 'wholesale_application']);
                }
            }, 'id');
    }

    public function down(): void
    {
        if (! Schema::hasTable('customer_access_requests') || ! Schema::hasColumn('customer_access_requests', 'application_kind')) {
            return;
        }

        Schema::table('customer_access_requests', function (Blueprint $table): void {
            $table->dropIndex(['application_kind']);
            $table->dropColumn('application_kind');
        });
    }
};
