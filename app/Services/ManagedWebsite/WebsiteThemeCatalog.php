<?php

namespace App\Services\ManagedWebsite;

class WebsiteThemeCatalog
{
    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return [
            $this->hvac(),
            $this->collinsElectric(),
            $this->outdoorElements(),
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(string $key): ?array
    {
        return collect($this->all())->firstWhere('key', $key);
    }

    /** @return array<string,mixed> */
    protected function hvac(): array
    {
        return [
            'key' => 'hvac-service', 'name' => 'HVAC Service', 'eyebrow' => 'Service-first',
            'description' => 'A calm, clear service website built for urgent calls, seasonal work, and trust.',
            'thumbnail' => '/images/website-themes/hvac-service-hero.png',
            'settings' => $this->settings('hvac-service', 'HVAC Service', ['ink' => '#17343b', 'brand' => '#167f8c', 'surface' => '#f8fbfb', 'soft' => '#e9f4f2', 'accent' => '#d48835'], 'system', 'rounded', 'Need help? Request service and we will help you find the right next step.'),
            'pages' => [
                $this->page('/', 'Home', 'home', [
                    $this->hero('Comfort for every season.', 'Clear service, dependable technicians, and an easy way to request help.', 'Request service', '#contact', '/images/website-themes/hvac-service-hero.png', 'A comfortable home exterior at dusk with an HVAC unit.'),
                    $this->cards('The help you need, made easy to understand', 'Start with the service that best fits your home or business.', [['Seasonal care', 'Keep your system dependable before the next temperature swing.'], ['Repairs', 'Clear diagnosis and a practical next step when comfort changes.'], ['Replacement planning', 'Compare efficient options with useful, plain-English guidance.']]),
                    $this->feature('A clear next step from the first call', 'Explain your process, response times, and what customers can expect before a technician arrives.', '/images/website-themes/hvac-service-diagnostic.png', 'Original starter image of an HVAC diagnostic visit.'),
                    $this->faq('Questions before you book?', [['What happens after I request service?', 'A team member reviews your request and follows up with the next available step.'], ['Do you serve both homes and businesses?', 'Use this answer to explain the service areas and property types your team supports.']]),
                    $this->contact('Request service'),
                ]),
                $this->page('services', 'Services', 'services', [$this->hero('HVAC services built around your day.', 'Use this page to make every service clear before a customer calls.'), $this->cards('How we help', 'Choose the services that are ready to promote.', [['Maintenance', 'Seasonal inspections and practical upkeep.'], ['Repair', 'Troubleshooting and repairs explained clearly.'], ['Replacement', 'Thoughtful system planning and installation.']]), $this->contact('Talk with our service team')]),
                $this->page('maintenance', 'Maintenance', 'landing', [$this->hero('Keep comfort on schedule.', 'A focused landing page for seasonal maintenance plans.'), $this->feature('A calmer way to stay ahead', 'Explain visits, reminders, and what customers receive.'), $this->contact('Ask about maintenance')]),
                $this->page('about', 'About', 'about', [$this->hero('Service that feels straightforward.', 'Tell the story, standards, and people behind your work.'), $this->text('Built around clear communication', 'Add approved business history, team details, and service values here.'), $this->contact('Start a conversation')]),
                $this->page('faq', 'FAQ', 'faq', [$this->hero('Helpful answers, before you need them.', 'Make common questions easy to find.'), $this->faq('Common questions', [['How do I schedule?', 'Add your reviewed booking process.'], ['What areas do you serve?', 'Add accurate service-area details.']])]),
                $this->page('contact', 'Contact', 'contact', [$this->hero('Let’s get your comfort back on track.', 'Share a few details and the right person can follow up.'), $this->contact('Request service')]),
            ],
        ];
    }

    /** @return array<string,mixed> */
    protected function collinsElectric(): array
    {
        $panelImage = '/images/website-themes/collins-electric-panel-service.png';

        return [
            'key' => 'collins-electric', 'name' => 'Collins Upstate Electric', 'eyebrow' => 'Clean trade',
            'description' => 'A crisp white and navy service theme for clear electrical work and easy customer action.',
            'thumbnail' => '/images/website-themes/collins-electric-hero.png',
            'settings' => $this->settings('collins-electric', 'Collins Upstate Electric', ['ink' => '#13243b', 'brand' => '#164b7a', 'surface' => '#ffffff', 'soft' => '#f3f7fa', 'accent' => '#1464e8'], 'sans', 'soft', 'Residential · Commercial · Reliable Power'),
            'source_manifest' => [['url' => 'https://www.whodoyou.com/biz/1731846/collins-upstate-electrical-pendleton-sc', 'retrieved_on' => '2026-07-27', 'use' => 'business name, Pendleton, phone, email']],
            'pages' => [
                $this->page('/', 'Home', 'home', [
                    $this->hero('Electrical work you can feel confident about.', 'Collins Upstate Electric helps Pendleton-area homes and businesses with clear communication and dependable electrical service.', 'Call 864-640-6642', 'tel:+18646406642', '/images/website-themes/collins-electric-hero.png', 'An original starter image of a clean electrical work setting.'),
                    $this->trust('Reliable power. Clear next steps.', [['Residential', 'Projects and service work for safer, more useful homes.'], ['Commercial', 'Electrical support for local businesses and working spaces.'], ['Power upgrades', 'Panels, EV charging, generators, lighting, and new circuits.']]),
                    $this->feature('Start with a clear plan.', 'From a service call to a larger project, explain the scope, answer questions, and make the next step easy.', $panelImage, 'Original starter image of an electrician inspecting a residential electrical panel.'),
                    $this->gallery('Built for the work ahead.', 'Replace these starter images with approved project photography as it becomes available.', [['image_url' => '/images/website-themes/collins-electric-hero.png', 'image_alt' => 'Original starter electrical work image.'], ['image_url' => $panelImage, 'image_alt' => 'Original starter panel service image.']]),
                    $this->faq('Questions before you call?', [['What services do you offer?', 'Residential and commercial electrical work, including panel and service upgrades, EV charging, generators, lighting, and new construction.'], ['How do I get started?', 'Call or email with a short description of the project. Collins Upstate Electric can help identify the right next step.'], ['Where are you based?', 'Collins Upstate Electric is based in Pendleton, South Carolina.']]),
                    $this->contact('Talk to an electrician'),
                ]),
                $this->page('residential', 'Residential', 'services', [$this->hero('Residential electrical work, made clearer.', 'From service issues to upgrades, start with a practical conversation.', 'Call 864-640-6642', 'tel:+18646406642', $panelImage, 'Original starter image of residential panel service.'), $this->cards('Residential services', 'Make each project type easy to understand.', [['Panel and service upgrades', 'Build capacity for how your home works today.'], ['EV charging', 'Plan a charging setup that fits your vehicle and home.'], ['Lighting and circuits', 'Bring safer, more useful power where you need it.']]), $this->contact('Ask about residential work')]),
                $this->page('commercial', 'Commercial', 'services', [$this->hero('Electrical support for the places you work.', 'Clear planning for commercial spaces, improvements, and dependable power.'), $this->cards('Commercial capabilities', 'Use this page to describe your strongest business-service offerings.', [['New construction', 'Coordinate electrical work for new spaces.'], ['Lighting', 'Improve visibility, comfort, and everyday function.'], ['Service and troubleshooting', 'Find a clear path forward when work cannot wait.']]), $this->contact('Discuss a commercial project')]),
                $this->page('power-upgrades', 'Power Upgrades', 'landing', [$this->hero('Ready for more power?', 'Panels, EV charging, generators, and upgrades planned around the way you live and work.', 'Call 864-640-6642', 'tel:+18646406642', $panelImage, 'Original starter electrical panel image.'), $this->feature('Plan the upgrade before the pressure hits.', 'Use this landing page for generators, EV charging, service upgrades, and reliable-power projects.', $panelImage), $this->contact('Talk through an upgrade')]),
                $this->page('about', 'About', 'about', [$this->hero('A local electrician who keeps the next step clear.', 'Collins Upstate Electric serves Pendleton-area customers with residential, commercial, and reliable-power work.'), $this->text('Straight answers from the first call', 'Use this page to add the approved business story, team details, licenses, and project approach.'), $this->contact('Get in touch')]),
                $this->page('contact', 'Contact', 'contact', [$this->hero('Let’s talk about your project.', 'Call or email Collins Upstate Electric to describe the work and discuss the next step.', 'Call 864-640-6642', 'tel:+18646406642'), $this->contact('Contact Collins Upstate Electric')]),
            ],
        ];
    }

    /** @return array<string,mixed> */
    protected function outdoorElements(): array
    {
        return [
            'key' => 'outdoor-elements', 'name' => 'Outdoor Elements', 'eyebrow' => 'Outdoor living',
            'description' => 'A warm, premium starter for outdoor structures, furniture, cabinetry, and fire features.',
            'thumbnail' => '/images/website-themes/outdoor-elements-hero.png',
            'settings' => $this->settings('outdoor-elements', 'Outdoor Elements', ['ink' => '#26352e', 'brand' => '#6d7d56', 'surface' => '#fbfaf6', 'soft' => '#f1efe7', 'accent' => '#b36b3e'], 'serif', 'rounded', 'Designed for more time outside.'),
            'pages' => [
                $this->page('/', 'Home', 'home', [$this->hero('Elevate your outdoor space.', 'Considered structures, furniture, cabinetry, and fire features for life outside.', 'Explore the possibilities', '#contact', '/images/website-themes/outdoor-elements-hero.png', 'A refined outdoor living terrace at dusk.'), $this->cards('Make outside feel like home.', 'Start with the outdoor category that best fits your space.', [['Structures', 'Shade, shelter, and room to gather.'], ['Furniture and cabinetry', 'Useful pieces made for the way you host.'], ['Fire features', 'A focal point for longer evenings outside.']]), $this->feature('Designed around the way you live.', 'Share your process, materials, and what makes your work distinct.', '/images/website-themes/outdoor-elements-hero.png'), $this->contact('Start your outdoor project')]),
                $this->page('collections', 'Collections', 'services', [$this->hero('Outdoor living, thoughtfully composed.', 'A clear place to introduce collections, materials, and signature pieces.'), $this->cards('Explore the collection', 'Use these cards for your most important offerings.', [['Outdoor kitchens', 'Bring function to the heart of the gathering.'], ['Structures', 'Create shelter and a sense of place.'], ['Fire features', 'Add warmth and an evening destination.']]), $this->contact('Ask about a collection')]),
                $this->page('projects', 'Projects', 'landing', [$this->hero('Ideas shaped for real spaces.', 'Use this page for approved project photography and short case studies.'), $this->gallery('Featured spaces', 'Replace starter imagery with your approved project portfolio.', [['image_url' => '/images/website-themes/outdoor-elements-hero.png', 'image_alt' => 'Original starter outdoor living image.']]), $this->contact('Start a project conversation')]),
                $this->page('about', 'About', 'about', [$this->hero('A more considered way to live outside.', 'Use this page to tell the story behind the work.'), $this->text('Materials, craft, and gathering', 'Add approved team, material, and process details.'), $this->contact('Meet the team')]),
                $this->page('faq', 'FAQ', 'faq', [$this->hero('Useful answers for a better project.', 'Help customers understand timing, materials, and next steps.'), $this->faq('Common questions', [['How do we begin?', 'Start with a conversation about your space and priorities.'], ['What can be customized?', 'Use this answer to describe available materials, sizes, and finishes.']])]),
                $this->page('contact', 'Contact', 'contact', [$this->hero('Let’s make space for more outside.', 'Tell us a little about your project.'), $this->contact('Start your project')]),
            ],
        ];
    }

    /** @return array<string,mixed> */
    protected function settings(string $key, string $name, array $palette, string $type, string $corners, string $announcement): array
    {
        return ['theme_key' => $key, 'theme_name' => $name, 'theme_palette' => $palette, 'typography' => $type, 'corners' => $corners, 'content_width' => 'wide', 'announcement' => ['enabled' => true, 'text' => $announcement, 'url' => '#contact'], 'footer' => ['copyright' => '© '.now()->year.' '.$name, 'tagline' => 'Thoughtful service. Clear next steps.'], 'social_links' => []];
    }

    /** @return array<string,mixed> */
    protected function page(string $slug, string $title, string $type, array $blocks): array
    {
        return ['slug' => $slug, 'title' => $title, 'page_type' => $type, 'blocks' => $blocks, 'seo' => ['title' => $title, 'description' => 'Learn more about '.$title.'.']];
    }

    /** @return array<string,mixed> */
    protected function hero(string $heading, string $body, string $label = '', string $url = '', string $image = '', string $alt = ''): array
    {
        return ['type' => 'hero', 'heading' => $heading, 'body' => $body, 'cta_label' => $label, 'cta_url' => $url, 'image_url' => $image, 'image_alt' => $alt];
    }

    /** @return array<string,mixed> */
    protected function text(string $heading, string $body): array
    {
        return ['type' => 'text', 'heading' => $heading, 'body' => $body];
    }

    /** @return array<string,mixed> */
    protected function contact(string $heading): array
    {
        return ['type' => 'contact_form', 'heading' => $heading];
    }

    /** @return array<string,mixed> */
    protected function feature(string $heading, string $body, string $image = '', string $alt = ''): array
    {
        return ['type' => 'image_with_text', 'heading' => $heading, 'body' => $body, 'image_url' => $image, 'image_alt' => $alt];
    }

    /** @return array<string,mixed> */
    protected function cards(string $heading, string $body, array $items): array
    {
        return ['type' => 'service_cards', 'heading' => $heading, 'body' => $body, 'items' => $this->items($items)];
    }

    /** @return array<string,mixed> */
    protected function trust(string $heading, array $items): array
    {
        return ['type' => 'trust_bar', 'heading' => $heading, 'items' => $this->items($items)];
    }

    /** @return array<string,mixed> */
    protected function faq(string $heading, array $items): array
    {
        return ['type' => 'faq_list', 'heading' => $heading, 'items' => collect($items)->map(fn (array $item): array => ['heading' => $item[0], 'body' => $item[1]])->all()];
    }

    /** @return array<string,mixed> */
    protected function gallery(string $heading, string $body, array $items): array
    {
        return ['type' => 'gallery', 'heading' => $heading, 'body' => $body, 'items' => $items];
    }

    /** @return array<int,array<string,string>> */
    protected function items(array $items): array
    {
        return collect($items)->map(fn (array $item): array => ['heading' => $item[0], 'body' => $item[1]])->all();
    }
}
