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
        $modules = $this->relevantModules($trade);
        $modulesIntro = "A few Everbranch modules we already have or could tailor for a {$trade} business:";
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
                    "I’m John, a local businessman in Powdersville. I have spent 12 years in online retail, so I understand how much time gets lost when customer information, day-to-day work, and follow-up live in separate places. {$proof}",
                    "I built Everbranch for owner-led businesses that have outgrown scattered notes and generic software. For a {$trade} company, {$workflow}",
                    $modulesIntro,
                    $modules,
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
                    'I’m a local businessman with 12 years in online retail. I now build clean, straightforward front-facing websites for local businesses, along with the Everbranch tools that keep the work behind those leads organized.',
                    $modulesIntro,
                    $modules,
                    'Would it be useful if I sent a one-page website example tailored to your business? No pitch deck—just something concrete you can react to.',
                    $signature,
                ],
            ],
            'follow_up' => [
                'subject' => 'Re: '.$prospect->business_name.' workflow',
                'paragraphs' => [
                    $greeting,
                    'Following up with one practical thought: '.$workflow,
                    "After 12 years in online retail, I tend to look first for the handoffs that make work harder than it needs to be. {$modulesIntro}",
                    $modules,
                    'If that is already handled well, I will leave it there. If it is still spread across texts, paper, and separate apps, would a short example be useful?',
                    $signature,
                ],
            ],
            'close_loop' => [
                'subject' => 'Close the loop?',
                'paragraphs' => [
                    $greeting,
                    'I have not heard back, so I will close the loop after this.',
                    'If improving your website or keeping customers, jobs, photos, materials, and follow-ups in one place becomes a priority, I would be glad to show you what Everbranch can do for a local trade business. I bring 12 years of online-retail experience to the conversation, and can start with only the pieces that matter to your business.',
                    $modulesIntro,
                    $modules,
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

    protected function relevantModules(string $trade): string
    {
        $modules = match (true) {
            str_contains($trade, 'hvac') => [
                'A front-facing website with service and estimate requests',
                'Service scheduling, dispatch, and technician job details',
                'Customer equipment history and maintenance follow-up',
                'Estimates, invoices, job photos, and customer updates',
            ],
            str_contains($trade, 'electric') => [
                'A front-facing website with quote and service-request forms',
                'Estimates, job scheduling, and crew task assignments',
                'Parts, materials, photos, documents, and job history',
                'Field time tracking, work-van context, and customer follow-up',
            ],
            str_contains($trade, 'plumb') => [
                'A front-facing website with emergency and routine-service intake',
                'Dispatch, estimates, and customer/job history',
                'Parts, job photos, and technician notes in one place',
                'Invoices, customer updates, and maintenance reminders',
            ],
            str_contains($trade, 'roof') => [
                'A front-facing website with inspection and estimate requests',
                'Lead-to-estimate and project-stage tracking',
                'Inspection photos, documents, materials, and crew tasks',
                'Customer updates, invoices, and post-project follow-up',
            ],
            str_contains($trade, 'landscap') || str_contains($trade, 'lawn') => [
                'A front-facing website with quote requests and service-area details',
                'Recurring-service schedules, routes, and crew assignments',
                'Job photos, materials, notes, and customer property history',
                'Estimates, invoices, seasonal reminders, and follow-up',
            ],
            default => [
                'A front-facing website with contact, quote, or booking requests',
                'Customer records, estimates, jobs, schedules, and follow-up',
                'Tasks, materials, photos, documents, and team coordination',
                'Invoices, reminders, and reporting tailored to the business',
            ],
        };

        return implode("\n", array_map(fn (string $module): string => '• '.$module, $modules));
    }
}
