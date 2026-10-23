<?php

namespace App\Enterprise\Conversation;

use Closure;
use Illuminate\Http\Request;

/** A campus conversation trial cannot coexist with older provider/sending authorities. */
class OtherPaidPaths
{
    public function handle(Request $request, Closure $next)
    {
        abort_if(config('enterprise.conversation.enabled'), 404);

        return $next($request);
    }
}
