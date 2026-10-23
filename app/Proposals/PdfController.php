<?php

namespace App\Proposals;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PdfController
{
    public function __construct(private Access $access, private ProposalService $proposals, private PdfDocument $pdf) {}

    public function owner(Request $request, string $workspace, string $draftId)
    {
        $tenant = $this->access->owner($request);
        $draft = $this->proposals->draft($tenant, $draftId);
        $bytes = $this->pdf->render($draft->payload, 'Owner draft · revision '.$draft->revision, $draft->updated_at->toIso8601String());
        // Rendering may outlive actor deactivation or loss of verification.
        $actor = $request->user()?->fresh();
        abort_unless($actor, 403);
        $request->setUserResolver(fn () => $actor);
        $this->access->owner($request);

        return $this->download($bytes, $draft->payload['title']);
    }

    public function recipient(Request $request, string $token)
    {
        $version = $this->access->recipient($request, $token);
        abort_unless($this->access->unlocked($request, $version), 403);
        $bytes = $this->pdf->render($version->payload, 'Private published version '.$version->number, $version->created_at->toIso8601String(), $version->content_hash);
        // Expiry, revocation and the exact browser grant remain authoritative.
        abort_unless($this->access->unlocked($request, $this->access->recipient($request, $token)), 403);

        return $this->download($bytes, $version->payload['title']);
    }

    private function download(string $bytes, string $title)
    {
        $filename = Str::limit(Str::slug($title), 100, '').'.pdf';

        return response($bytes)->withHeaders([
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
