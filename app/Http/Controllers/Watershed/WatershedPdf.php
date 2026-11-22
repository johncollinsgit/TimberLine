<?php

namespace App\Http\Controllers\Watershed;

use App\Services\Watershed\WatershedStore;
use Dompdf\Dompdf;

class WatershedPdf
{
    public function response(object $release)
    {
        $pdf = new Dompdf;
        $pdf->loadHtml(view('watershed.release', ['release' => $release, 'answers' => app(WatershedStore::class)->decrypt($release->answers)])->render());
        $pdf->setPaper('letter');
        $pdf->render();

        return response($pdf->output())->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="watershed-release.pdf"')->header('Cache-Control', 'private, no-store');
    }
}
