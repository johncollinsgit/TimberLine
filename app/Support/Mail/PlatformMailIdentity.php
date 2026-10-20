<?php

namespace App\Support\Mail;

use App\Models\CustomerAccessRequest;
use App\Models\PlatformEmailMessage;
use Illuminate\Support\Facades\Schema;

class PlatformMailIdentity
{
    public static function wholesale(CustomerAccessRequest $request, string $kind): string
    {
        $generation = data_get($request->metadata, 'delivery.'.$kind.'.logical_message_id')
            ?: 'legacy:'.$request->id.':'.$kind.':'.($kind === 'decision' ? $request->status : 'review');

        return 'wholesale-'.$kind.':'.hash('sha256', (string) $generation);
    }

    /** Reconcile a frozen notification before token/link rendering can run again. */
    public static function frozen(string $identity): ?PlatformEmailMessage
    {
        if (! in_array(config('mail.mailers.'.config('mail.default').'.transport'), ['ses_platform', 'ses_platform_confirmed'], true)
            || ! Schema::hasTable('platform_email_messages')) {
            return null;
        }

        return PlatformEmailMessage::where('group_key', hash('sha256', 'explicit:'.$identity))->first();
    }
}
