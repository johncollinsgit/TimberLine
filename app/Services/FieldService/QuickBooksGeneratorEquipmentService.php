<?php

namespace App\Services\FieldService;

use App\Models\CustomerEquipment;
use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceJob;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class QuickBooksGeneratorEquipmentService
{
    /**
     * @param  array<int,array<string,mixed>>  $invoices
     * @return array<string,int>
     */
    public function syncInvoices(Tenant $tenant, array $invoices, bool $dryRun = false): array
    {
        $summary = [
            'generator_installations_detected' => 0,
            'generator_equipment_created' => 0,
            'generator_equipment_updated' => 0,
            'generator_services_detected' => 0,
            'generator_services_linked' => 0,
            'generator_services_needing_review' => 0,
        ];

        usort($invoices, fn (array $left, array $right): int => strcmp((string) ($left['TxnDate'] ?? ''), (string) ($right['TxnDate'] ?? '')));

        foreach ($invoices as $invoice) {
            $invoiceId = trim((string) ($invoice['Id'] ?? ''));
            $document = $invoiceId === '' ? null : FieldServiceFinancialDocument::query()
                ->forTenantId((int) $tenant->id)
                ->where('source', 'quickbooks')->where('document_type', 'invoice')->where('external_id', $invoiceId)->first();
            if (! $document instanceof FieldServiceFinancialDocument || ! $document->marketing_profile_id) {
                continue;
            }

            $date = filled($invoice['TxnDate'] ?? null) ? Carbon::parse((string) $invoice['TxnDate'])->startOfDay() : now()->startOfDay();
            foreach ($this->installations($invoice) as $index => $installation) {
                $summary['generator_installations_detected']++;
                if ($dryRun) {
                    continue;
                }

                $equipment = CustomerEquipment::query()->firstOrNew([
                    'tenant_id' => (int) $tenant->id,
                    'external_source' => 'quickbooks',
                    'external_id' => 'invoice:'.$invoiceId.':generator:'.$index,
                ]);
                $created = ! $equipment->exists;
                $equipment->forceFill([
                    'marketing_profile_id' => (int) $document->marketing_profile_id,
                    'equipment_type' => 'generator',
                    'name' => $installation['name'],
                    'manufacturer' => $installation['manufacturer'],
                    'model_number' => $installation['model'],
                    'installed_at' => $date->toDateString(),
                    'maintenance_interval_days' => 365,
                    'next_service_due_at' => $equipment->next_service_due_at ?? $date->copy()->addYear()->toDateString(),
                    'status' => 'active',
                    'notes' => $this->notes($invoice, $installation['excerpt']),
                ])->save();
                $summary[$created ? 'generator_equipment_created' : 'generator_equipment_updated']++;
            }

            if (! $this->isMaintenance($invoice)) {
                continue;
            }

            $summary['generator_services_detected']++;
            $equipment = $this->equipmentForService($tenant, (int) $document->marketing_profile_id, $date, $this->kilowatts($this->text($invoice)));
            if (! $equipment instanceof CustomerEquipment) {
                $summary['generator_services_needing_review']++;

                continue;
            }
            if ($dryRun) {
                $summary['generator_services_linked']++;

                continue;
            }

            if (! $equipment->last_serviced_at || $equipment->last_serviced_at->lte($date)) {
                $equipment->forceFill([
                    'last_serviced_at' => $date->toDateString(),
                    'next_service_due_at' => $date->copy()->addYear()->toDateString(),
                ])->save();
            }
            FieldServiceJob::query()->updateOrCreate([
                'tenant_id' => (int) $tenant->id,
                'external_source' => 'quickbooks_generator_service',
                'external_id' => 'invoice:'.$invoiceId,
            ], [
                'marketing_profile_id' => (int) $document->marketing_profile_id,
                'customer_equipment_id' => (int) $equipment->id,
                'title' => $equipment->name.' service',
                'status' => 'complete',
                'operational_status' => 'complete',
                'status_source' => 'quickbooks_import',
                'priority' => 'normal',
                'customer_name' => trim((string) data_get($invoice, 'CustomerRef.name', '')),
                'description' => Str::limit($this->text($invoice), 4000, ''),
                'scheduled_for' => $date->copy()->setTime(9, 0),
                'completed_at' => $date->copy()->endOfDay(),
                'archived_at' => $date->copy()->endOfDay(),
                'metadata' => ['quickbooks' => ['invoice_id' => $invoiceId, 'invoice_number' => $invoice['DocNumber'] ?? null]],
            ]);
            $summary['generator_services_linked']++;
        }

        return $summary;
    }

    /** @param array<string,mixed> $invoice @return array<int,array{name:string,manufacturer:?string,model:?string,excerpt:string}> */
    protected function installations(array $invoice): array
    {
        $text = $this->text($invoice);
        if (! $this->isInstallation($text)) {
            return [];
        }

        $count = preg_match('/\b(\d+)\s*(?:-|x)\s*(?:\d{2}\s*kw\s*)?(?:whole house\s+)?(?:generac\s+|kohler\s+|duromax\s+)?generators?\b/i', $text, $match) ? min(10, (int) $match[1]) : 1;
        $manufacturer = preg_match('/\b(generac|kohler|duromax)\b/i', $text, $brand) ? Str::headline(strtolower($brand[1])) : null;
        $kw = $this->kilowatts($text);
        $model = preg_match('/\b(?:guardian\s+)?([A-Z]{1,4}[\d-]{3,}[A-Z0-9-]*)\b/i', $text, $modelMatch) ? strtoupper($modelMatch[1]) : null;
        $name = trim(($manufacturer ?: 'Home standby').' '.($kw ? $kw.'kW ' : '').'generator');

        return array_fill(0, $count, ['name' => $name, 'manufacturer' => $manufacturer, 'model' => $model, 'excerpt' => Str::limit($text, 1200, '')]);
    }

    protected function isInstallation(string $text): bool
    {
        if (! preg_match('/\b(?:\d{2}\s*kw\s+)?(?:generac\s+|kohler\s+|duromax\s+)?generator\b/i', $text) || $this->isMaintenanceText($text)) {
            return false;
        }
        if (preg_match('/\b(generator inlet|generator plug|generator hook ?up|generator cord|interlock kit)\b/i', $text)
            && ! preg_match('/\b(?:generac|kohler|duromax|\d{2}\s*kw)\b/i', $text)) {
            return false;
        }

        return (bool) preg_match('/\b(?:provide(?:d)?|install(?:ed|ation)?|set|placement)\b.{0,100}\bgenerator\b|\bgenerator\b.{0,100}\b(?:provide(?:d)?|install(?:ed|ation)?|set|placement)\b/is', $text);
    }

    /** @param array<string,mixed> $invoice */
    protected function isMaintenance(array $invoice): bool
    {
        return $this->isMaintenanceText($this->text($invoice));
    }

    protected function isMaintenanceText(string $text): bool
    {
        return (bool) preg_match('/\b(annual\s+)?(?:maintenance|service)\b.*\b(generator|generac|kohler)\b|\b(generator|generac|kohler)\b.*\b(?:maintenance|service)\b/i', $text);
    }

    protected function equipmentForService(Tenant $tenant, int $profileId, Carbon $serviceDate, ?int $kilowatts): ?CustomerEquipment
    {
        $equipment = CustomerEquipment::query()->forTenantId((int) $tenant->id)
            ->where('marketing_profile_id', $profileId)->where('equipment_type', 'generator')->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('installed_at')->orWhereDate('installed_at', '<=', $serviceDate->toDateString()))
            ->get();
        if ($kilowatts) {
            $matches = $equipment->filter(fn (CustomerEquipment $item): bool => str_contains(strtolower($item->name.' '.$item->model_number), strtolower($kilowatts.'kw')));
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return $equipment->count() === 1 ? $equipment->first() : null;
    }

    /** @param array<string,mixed> $invoice */
    protected function text(array $invoice): string
    {
        return collect([
            $invoice['PrivateNote'] ?? null,
            data_get($invoice, 'CustomerMemo.value'),
            ...collect((array) ($invoice['Line'] ?? []))->flatMap(fn (mixed $line): array => [data_get($line, 'Description'), data_get($line, 'SalesItemLineDetail.ItemRef.name')])->all(),
        ])->filter()->implode("\n");
    }

    protected function kilowatts(string $text): ?int
    {
        return preg_match('/\b(18|20|22|24|26)\s*k\s*w\b/i', $text, $match) ? (int) $match[1] : null;
    }

    /** @param array<string,mixed> $invoice */
    protected function notes(array $invoice, string $excerpt): string
    {
        return trim('Imported from QuickBooks invoice '.($invoice['DocNumber'] ?? $invoice['Id'] ?? '').".\n".$excerpt);
    }
}
