<?php

namespace App\Services\Marketing\Desk;

class MarketingWorkflowCatalog
{
    public function recipes(): array
    {
        $specs = [
            'retail_welcome' => ['Retail welcome', 'retail', 'subscription', 0, 3, 'Welcome to our community', 'Discover something you will love'],
            'retail_post_purchase' => ['Post-purchase care and review', 'retail', 'fulfilled', 7, 0, 'How are you enjoying your purchase?', ''],
            'retail_winback' => ['Retail win-back', 'retail', 'inactive_90', 0, 7, 'We would love to welcome you back', 'A little inspiration for your next visit'],
            'wholesale_inquiry' => ['Wholesale inquiry follow-up', 'wholesale', 'inquiry', 0, 2, 'Thank you for your wholesale inquiry', 'Can we help with your wholesale plans?'],
            'wholesale_sample' => ['Wholesale sample follow-up', 'wholesale', 'sample_delivered', 3, 0, 'How did your samples work out?', ''],
            'wholesale_reorder' => ['Wholesale reorder reminder', 'wholesale', 'inactive_60', 0, 7, 'Ready to plan your next order?', 'Can we help with your next order?'],
            'service_inquiry' => ['Service inquiry follow-up', 'services', 'inquiry', 0, 2, 'Thank you for getting in touch', 'Would you like help with the next step?'],
            'service_completed' => ['Completed service follow-up', 'services', 'completed', 7, 0, 'Thank you for choosing us', ''],
        ];
        $recipes = [];
        foreach ($specs as $key => [$name, $audience, $event, $delay, $followUp, $subject, $second]) {
            $steps = [];
            $configs = [];
            if ($delay) {
                $steps[] = 'core.delay_for';
                $configs[] = ['duration' => $delay, 'unit' => 'days'];
            }
            $send = ['marketing_profile_id' => ['type' => 'mapping', 'path' => 'trigger.output.marketing_profile_id'], 'audience' => $audience, 'subject' => $subject, 'body' => 'Write and approve your brand-specific message before activation.'];
            $steps[] = 'everbranch.marketing.email.send';
            $configs[] = $send;
            if ($followUp) {
                $steps[] = 'core.delay_for';
                $configs[] = ['duration' => $followUp, 'unit' => 'days'];
                $steps[] = 'everbranch.marketing.email.send';
                $configs[] = [...$send, 'subject' => $second];
            }
            if (in_array($key, ['wholesale_inquiry', 'wholesale_sample'], true)) {
                $steps[] = 'core.delay_for';
                $configs[] = ['duration' => $key === 'wholesale_inquiry' ? 5 : 7, 'unit' => 'days'];
                $steps[] = 'everbranch.marketing.follow_up.prepare';
                $configs[] = ['marketing_profile_id' => $send['marketing_profile_id'], 'title' => 'Follow up with wholesale customer'];
            }
            $recipes['marketing_'.$key] = [
                'key' => 'marketing_'.$key, 'name' => $name, 'description' => 'Approved '.$audience.' follow-up; stops after reply, purchase, opt-out, complaint or takeover.',
                'available' => true, 'trigger_component_key' => 'everbranch.marketing.event',
                'trigger_config' => ['event' => $event, 'audience' => $audience], 'step_component_keys' => $steps, 'step_configs' => $configs,
            ];
        }

        return $recipes;
    }
}
