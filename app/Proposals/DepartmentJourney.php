<?php

namespace App\Proposals;

class DepartmentJourney
{
    public static function content(): array
    {
        return [
            ['title' => 'Start on site, with your people.', 'body' => 'Meet department heads and staff where the work happens. Listen to the day-to-day needs, handoffs and major pain points.', 'deliverable' => 'A shared picture of needs and priorities.', 'participation' => 'Department head and the people doing the work.', 'gate' => 'Agree the discovery sessions and the initial department focus. No fixed onsite frequency or hours are assumed.'],
            ['title' => 'Understand the tools and the process.', 'body' => 'Review current software and how work actually moves. Identify opportunities to reduce friction, avoid duplicate effort, save money or support revenue.', 'deliverable' => 'A current-process map and an opportunity shortlist.', 'participation' => 'Staff explain existing systems, costs and constraints.', 'gate' => 'Validate the findings together. Savings and revenue are opportunities, not guarantees.'],
            ['title' => 'Design the right next step, together.', 'body' => 'Shape department-specific software, processes and workflows on the shared Everbranch Enterprise platform. Decide which connections across campus are useful and appropriate.', 'deliverable' => 'A proposed workflow, workspace design and bounded written scope.', 'participation' => 'Department leads and the relevant university stakeholders.', 'gate' => 'Approve scope, responsibilities, timeline and price before implementation; estimate additional work separately.'],
            ['title' => 'Build, connect and pilot.', 'body' => 'Implement the approved scope and pilot it with the people who will use it. Add approved integrations where their access and technical readiness are verified.', 'deliverable' => 'A working pilot and a record of feedback and issues.', 'participation' => 'A named pilot group and authorized system owners.', 'gate' => 'Review the pilot against the agreed scope. Custom integrations, major migrations and system replacement require separate approval.'],
            ['title' => 'Train the team. Launch with care.', 'body' => 'Walk staff through their work, clarify responsibilities and prepare the agreed transition. For Roll Call, keep the optional printout while reviewing the technician web-app experience.', 'deliverable' => 'An agreed launch plan, staff guidance and handoff.', 'participation' => 'Managers and staff validate the real day-to-day flow.', 'gate' => 'Confirm readiness and device acceptance before launch. No installation or deployment is claimed by this proposal.'],
            ['title' => 'Keep learning. Improve deliberately.', 'body' => 'Review how the agreed workflows are working and prioritize improvements together. Connect departments in phases toward a university operating-system vision: clearer, connected campus operations.', 'deliverable' => 'A reviewed improvement backlog and next-step recommendations.', 'participation' => 'Department heads and relevant cross-campus partners.', 'gate' => 'Agree follow-on work explicitly. This is not unlimited development or a commitment to replace all SIS, LMS or ERP systems.'],
        ];
    }
}
