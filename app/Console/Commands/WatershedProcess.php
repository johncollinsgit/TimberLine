<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Watershed\WatershedStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class WatershedProcess extends Command
{
    protected $signature = 'watershed:process';

    protected $description = 'Deliver pending Watershed registration emails without replaying uncertain sends';

    public function handle(): int
    {
        if (! config('watershed.enabled')) {
            return self::SUCCESS;
        }$tenant = Tenant::where('slug', config('watershed.tenant_slug'))->firstOrFail();
        $s = app(WatershedStore::class);
        foreach ($s->query('deliveries', $tenant->id)->where('status', 'pending')->orderBy('created_at')->limit(50)->get() as $item) {
            $claimed = $s->query('deliveries', $tenant->id)->where('id', $item->id)->where('status', 'pending')->update(['status' => 'sending', 'updated_at' => now()]);
            if (! $claimed) {
                continue;
            }
            $p = $s->decrypt($item->payload);
            if (($p['leader'] ?? false) && ! in_array($p['email'], config('watershed.leaders'), true)) {
                $s->query('deliveries', $tenant->id)->where('id', $item->id)->update(['status' => 'suppressed']);

                continue;
            }
            try {
                $sent = Mail::to($p['email'])->send(new \App\Mail\WatershedMail($p['title'], $p['body'], null, ($p['leader'] ?? false) ? 'https://app.theeverbranch.com/watershed?tenant='.$tenant->slug : config('watershed.origin').'/register'));
                $s->query('deliveries', $tenant->id)->where('id', $item->id)->update(['status' => 'accepted', 'provider_message_id' => $sent?->getMessageId(), 'updated_at' => now()]);
            } catch (\Throwable $e) {
                $s->query('deliveries', $tenant->id)->where('id', $item->id)->update(['status' => 'uncertain', 'updated_at' => now()]);
            }
        }

        return self::SUCCESS;
    }
}
