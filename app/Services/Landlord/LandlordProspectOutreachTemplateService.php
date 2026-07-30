<?php

namespace App\Services\Landlord;

use App\Models\LandlordProspect;
use Illuminate\Support\Str;

class LandlordProspectOutreachTemplateService
{
    /** @return array<string,string> */
    public function options(): array
    {
        return [
            'first_touch' => 'Relevant first touch',
            'website_gap' => 'Google presence, no website',
            'follow_up' => 'Short useful follow-up',
            'close_loop' => 'Low-pressure close the loop',
        ];
    }

    /** @return array{subject:string,body:string,from_address:string,to_address:?string} */
    public function draft(LandlordProspect $prospect, string $template): array
    {
        $sender = (array) config('landlord_prospecting.sender', []);
        $firstName = trim(Str::before((string) $prospect->contact_name, ' '));
        $greeting = $firstName !== '' ? 'Hi '.$firstName.',' : 'Hi '.$prospect->business_name.' team,';
        $trade = Str::lower(trim((string) $prospect->trade)) ?: 'service';
        $proof = $this->publicProof($prospect);
        $workflow = $this->workflowValue($trade);
        $bookingUrl = trim((string) ($sender['booking_url'] ?? ''));
        $signature = implode("\n", array_filter([
            'John Collins',
            'Evergrove Software · Everbranch',
            trim((string) ($sender['location'] ?? 'Powdersville')).', SC',
            trim((string) ($sender['email'] ?? '')),
        ]));

        $messages = [
            'first_touch' => [
                'subject' => 'A practical workflow idea for '.$prospect->business_name,
                'paragraphs' => [
                    $greeting,
                    "I’m John, a local software builder in Powdersville. {$proof}",
                    "I built Everbranch for owner-led businesses that have outgrown scattered notes and generic software. For a {$trade} company, {$workflow}",
                    'Would a 15-minute look at your current workflow be useful? I can show a practical example and tell you honestly whether Everbranch fits.',
                    $bookingUrl,
                    $signature,
                ],
            ],
            'website_gap' => [
                'subject' => 'Website idea for '.$prospect->business_name,
                'paragraphs' => [
                    $greeting,
                    "I found {$prospect->business_name} on Google while researching local {$trade} businesses. The listing shows ".number_format((int) $prospect->google_review_count).' reviews, but I didn’t see a website linked.',
                    'I build clean, straightforward websites for local businesses, and I also built Everbranch to keep customers, jobs, addresses, tasks, materials, photos, follow-ups, and work vehicles together after a lead comes in.',
                    'Would it be useful if I sent a one-page website example tailored to your business? No pitch deck—just something concrete you can react to.',
                    $signature,
                ],
            ],
            'follow_up' => [
                'subject' => 'Re: '.$prospect->business_name.' workflow',
                'paragraphs' => [
                    $greeting,
                    'Following up with one practical thought: '.$workflow,
                    'If that is already handled well, I will leave it there. If it is still spread across texts, paper, and separate apps, would a short example be useful?',
                    $signature,
                ],
            ],
            'close_loop' => [
                'subject' => 'Close the loop?',
                'paragraphs' => [
                    $greeting,
                    'I have not heard back, so I will close the loop after this.',
                    'If improving your website or keeping customers, jobs, photos, materials, and follow-ups in one place becomes a priority, I would be glad to show you what Everbranch can do for a local trade business.',
                    'Should I send one short example, or is this not a priority right now?',
                    $signature,
                ],
            ],
        ];

        $selected = $messages[$template] ?? $messages['first_touch'];

        return [
            'subject' => $selected['subject'],
            'body' => implode("\n\n", array_values(array_filter($selected['paragraphs']))),
            'from_address' => trim((string) ($sender['email'] ?? '')),
            'to_address' => $prospect->email,
        ];
    }

    protected function publicProof(LandlordProspect $prospect): string
    {
        if ($prospect->google_review_count) {
            return 'I found '.$prospect->business_name.' on Google with '.number_format((int) $prospect->google_review_count).' public reviews.';
        }

        if ($prospect->website) {
            return 'I came across '.$prospect->business_name.' while looking at established local trade businesses.';
        }

        return 'I found '.$prospect->business_name.' while researching local trade businesses.';
    }

    protected function workflowValue(string $trade): string
    {
        return match (true) {
            str_contains($trade, 'hvac') => 'that can mean service calls, customer and equipment history, estimates, schedules, technician tasks, photos, and maintenance follow-up in one place.',
            str_contains($trade, 'electric') => 'that can mean customers, site jobs, estimates, parts, crew tasks, photos, documents, work vans, and follow-up in one place.',
            str_contains($trade, 'plumb') => 'that can mean intake, dispatch, estimates, job history, parts, photos, customer updates, and follow-up in one place.',
            str_contains($trade, 'roof') => 'that can mean inspection leads, estimates, project stages, photos, documents, crew tasks, and customer updates in one place.',
            str_contains($trade, 'landscap') || str_contains($trade, 'lawn') => 'that can mean recurring work, quotes, schedules, crew assignments, materials, job photos, and customer follow-up in one place.',
            default => 'that can mean customers, jobs, addresses, tasks, materials, photos, documents, work vehicles, and follow-up in one place.',
        };
    }
}
