<?php

namespace App\Proposals;

class LeesMcRaeDetails
{
    public const IMPLEMENTATION_TIMELINE_HEADING = 'Implementation Timeline, Client Dependencies, and Billing Commencement';

    public const IMPLEMENTATION_TIMELINE = 'Evergrove Software and Lees-McRae College will establish a mutually agreed implementation schedule for each workspace, including anticipated milestones, required institutional participation, testing, and activation. Evergrove will make commercially reasonable efforts to deliver each workspace according to the agreed schedule. The College agrees to provide timely access to necessary personnel, information, systems, approvals, and feedback. If Evergrove has substantially completed the agreed deliverables and the workspace is ready for its intended operational use, but activation is delayed due to institutional scheduling, internal approvals, unavailable personnel, or other circumstances outside Evergrove’s reasonable control, monthly subscription billing will commence 30 calendar days after Evergrove provides written notice of operational readiness. Any outstanding material defects attributable to Evergrove that prevent the workspace’s intended use must be resolved before billing commences under this provision. Delays attributable to Evergrove will not accelerate billing. This provision applies independently to each workspace and does not require simultaneous activation of multiple departments.';

    public static function content(): array
    {
        return [
            'implementation_timeline_heading' => self::IMPLEMENTATION_TIMELINE_HEADING,
            'implementation_timeline' => self::IMPLEMENTATION_TIMELINE,
            'usage' => 'Lees-McRae AI, SMS and email usage is offered without markup. The selected usage approach, allowance, measured provider costs and approval controls must be agreed in writing before usage starts. No unlimited allowance, $150 fee or automatic unapproved overage is included.',
            'support' => 'Human support: Monday–Friday, 9 am–5 pm Eastern Time. Initial human response within one business day. This is a response target, not a promise that every issue will be resolved within that time.',
            'travel' => 'Fixed routine travel is included. Overnight travel requires an all-in quote approved in advance. No overnight trip or extra travel charge is authorized by this proposal.',
            'activation' => 'Plan department-head setup first, then COO approval of the activation plan. Staff access begins only after explicit acceptance. This proposal changes no permissions, access or billing.',
            'rollout' => 'One department at a time: discover, design, build, pilot, train and accept the agreed scoped workflows before adding and appropriately connecting the next department. Acceptance covers trained users, permission and data checks, and support handover. A three-workspace bundle reserves the selected scope; departments activate sequentially. The $2,500/month bundle price is the TOTAL when all three are fully active. Phased billing amounts and rollout duration remain to be agreed. Subscription billing commencement is governed independently for each workspace by the Implementation Timeline, Client Dependencies, and Billing Commencement provision.',
        ];
    }
}
