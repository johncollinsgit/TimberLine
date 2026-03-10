<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\MarketingSegment;
use Illuminate\Support\Collection;

class MarketingSegmentPreviewService
{
    public function __construct(
        protected MarketingSegmentEvaluator $evaluator
    ) {
    }

    /**
     * @return array{count:int,profiles:Collection<int,MarketingProfile>,matches:array<int,array{profile_id:int,reasons:array<int,string>}>}
     */
    public function preview(MarketingSegment $segment, int $sampleSize = 25, string $search = ''): array
    {
        $sampleSize = max(1, min($sampleSize, 100));

        $query = MarketingProfile::query()->orderByDesc('updated_at');
        $search = trim($search);
        if ($search !== '') {
            $query->where(function ($nested) use ($search): void {
                $nested->where('first_name', 'like', '%' . $search . '%')
                    ->orWhere('last_name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%');
            });
        }

        $profiles = $query->get();
        $count = 0;
        $matched = collect();
        $matchDetails = [];
        foreach ($profiles as $profile) {
            $result = $this->evaluator->evaluateProfile($segment, $profile);
            if ($result['matched']) {
                $count++;
                if ($matched->count() < $sampleSize) {
                    $matched->push($profile);
                    $matchDetails[] = [
                        'profile_id' => (int) $profile->id,
                        'reasons' => $result['reasons'],
                    ];
                }
            }
        }

        return [
            'count' => $count,
            'profiles' => $matched,
            'matches' => $matchDetails,
        ];
    }
}
