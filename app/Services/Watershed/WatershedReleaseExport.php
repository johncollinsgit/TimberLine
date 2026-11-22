<?php

namespace App\Services\Watershed;

class WatershedReleaseExport
{
    /** The church renderer receives the signed snapshot, never the current profile. */
    public function response(object $release)
    {
        $store = app(WatershedStore::class);
        $application = $store->row('applications', $release->tenant_id, $release->application_id);
        $term = $store->row('terms', $release->tenant_id, $application->term_id);
        $answers = $store->decrypt($release->answers);

        return response()->json(['playerName' => $answers['child1Name'] ?? 'Child', 'answers' => $answers, 'season_key' => $term->term_key, 'signed_at' => $release->signed_at, 'release_id' => $release->id])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
