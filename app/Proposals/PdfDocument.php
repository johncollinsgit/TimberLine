<?php

namespace App\Proposals;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** A private, on-demand rendering of the supplied saved snapshot. */
class PdfDocument
{
    public function render(array $payload, string $edition, string $savedAt, ?string $contentHash = null): string
    {
        abort_if(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 200000, 422, 'This proposal is too long for a downloadable PDF. Use its interactive version.');
        $temporary = storage_path('app/private/proposal-pdf/'.Str::uuid());
        File::makeDirectory($temporary, 0700, true);

        try {
            $options = new Options;
            $options->setIsRemoteEnabled(false);
            $options->setIsPhpEnabled(false);
            $options->setIsJavascriptEnabled(false);
            $options->setChroot(base_path('public/images/proposals'));
            // Only server-encoded, fixed artwork is used; no payload URL is fetched.
            $options->setAllowedProtocols(['data://' => ['rules' => []]]);
            $options->setTempDir($temporary);
            $options->setFontCache($temporary);
            $options->setDefaultFont('DejaVu Sans');
            $document = new Dompdf($options);
            $wordmark = 'data:image/jpeg;base64,'.base64_encode(File::get(base_path('public/images/proposals/evergrove-software-wordmark.jpg')));
            $sections = CommercialSections::from($payload['client_details'] ?? []);
            $html = view('service-proposals.pdf', compact('payload', 'edition', 'savedAt', 'contentHash', 'wordmark', 'sections'))->render();
            abort_if(strlen($html) > 512000, 422, 'This proposal is too long for a downloadable PDF. Use its interactive version.');
            $document->loadHtml($html, 'UTF-8');
            $document->setPaper('letter');
            $document->render();
            $canvas = $document->getCanvas();
            $font = $document->getFontMetrics()->getFont('DejaVu Sans', 'normal');
            $canvas->page_text(42, 755, 'PRIVATE PROPOSAL  /  NONBINDING REVIEW', $font, 7, [0.24, 0.35, 0.31]);
            $canvas->page_text(513, 755, '{PAGE_NUM} / {PAGE_COUNT}', $font, 7, [0.24, 0.35, 0.31]);

            return $document->output();
        } finally {
            File::deleteDirectory($temporary);
        }
    }
}
