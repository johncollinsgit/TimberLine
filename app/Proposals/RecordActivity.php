<?php

namespace App\Proposals;

use Closure;
use Illuminate\Http\Request;
use Throwable;

class RecordActivity
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if ($response->getStatusCode() >= 400) {
            return $response;
        }
        try {
            $route = $request->route()->getName();
            $kind = match ($route) {
                'service-proposals.recipient.show' => 'proposal_view',
                'service-proposals.recipient.unlock' => 'unlocked',
                'service-proposals.recipient.reviewer-name', 'service-proposals.recipient.campus.name' => 'name_selected',
                'service-proposals.recipient.campus' => 'campus_page',
                'service-proposals.recipient.pdf' => 'pdf_download',
                'service-proposals.recipient.comment', 'service-proposals.recipient.campus.comment' => 'comment_saved',
                default => null,
            };
            if ($kind === null) {
                return $response;
            }
            $version = app(Access::class)->recipient($request, $request->route('token'));
            $unlocked = app(Access::class)->unlocked($request, $version);
            if ($kind !== 'proposal_view' && ! $unlocked) {
                return $response;
            }
            $page = null;
            if ($kind === 'proposal_view') {
                $kind = $unlocked ? 'proposal_view' : 'link_opened';
                $section = $request->query('section', 'overview');
                $page = is_string($section) && in_array($section, Activity::SECTIONS, true) ? $section : 'overview';
            } elseif ($kind === 'campus_page') {
                $page = $request->route('page', 'home');
            }
            app(Activity::class)->record($request, $version, $kind, $page);
        } catch (Throwable $error) {
            // Collection does not change the recipient's access or response.
        }

        return $response;
    }
}
