<?php

namespace App\Console\Commands;

use App\Models\TenantMarketingSetting;
use App\Services\Marketing\SquareCustomerOAuth;
use Illuminate\Console\Command;

/** Explicit operator maintenance of existing source grants; does not import or activate anything. */
class RenewSquareCustomerSource extends Command
{
    protected $signature = 'customer-sources:renew-square {--tenant-id= : Restrict to one existing tenant} {--limit=50 : Maximum existing source grants to inspect (1..200)}';

    protected $description = 'Renew due encrypted Core Square customer-source grants without importing history.';

    public function handle(SquareCustomerOAuth $oauth): int
    {
        $input = validator($this->options(), ['tenant-id' => 'nullable|integer|min:1', 'limit' => 'required|integer|min:1|max:200'])->validate();
        $rows = TenantMarketingSetting::where('key', SquareCustomerOAuth::KEY)
            ->where('value->credential_flow', 'core_source_authorization_code')->where('value->status', 'active')
            ->whereNull('value->credential_refresh')
            ->when($input['tenant-id'] ?? null, fn ($query, $tenantId) => $query->where('tenant_id', (int) $tenantId))
            ->orderBy('value->credential_issued_at')
            ->orderBy('id')->limit((int) $input['limit'])->get();
        $failed = 0;
        foreach ($rows as $row) {
            try {
                $oauth->renewIfNeeded((int) $row->tenant_id, $row->value['connection_id'] ?? null);
            } catch (\Throwable) {
                $failed++;
                $this->error('Square source renewal requires review for tenant '.$row->tenant_id.'.');
            }
        }
        $this->info('Inspected '.$rows->count().' existing source grants; '.$failed.' require review.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
