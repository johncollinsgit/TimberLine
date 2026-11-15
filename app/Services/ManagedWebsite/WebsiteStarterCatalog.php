<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;

/** Business starting points, never products, policy promises or paid access. */
class WebsiteStarterCatalog
{
    public function preset(Tenant $tenant): string
    {
        $key = (string) data_get($tenant->accessProfile?->metadata, 'tenant_blueprint.business_template', 'generic');

        return is_array(config('website_starters.'.$key)) ? $key : 'generic';
    }

    public function recommendedKey(Tenant $tenant): string
    {
        return 'starter-'.$this->preset($tenant);
    }

    /** @return array<int,array<string,mixed>> */
    public function all(Tenant $tenant): array
    {
        $keys = array_keys((array) config('website_starters'));
        $recommended = $this->preset($tenant);
        $keys = array_values(array_unique([$recommended, ...$keys]));

        return array_map(fn (string $key): array => $this->definition($tenant, $key), $keys);
    }

    public function find(Tenant $tenant, string $key, ?string $businessName = null): ?array
    {
        $preset = str_starts_with($key, 'starter-') ? substr($key, 8) : '';

        return is_array(config('website_starters.'.$preset)) && $preset !== '' ? $this->definition($tenant, $preset, $businessName) : null;
    }

    /** Only approved local files enter the picker; never scan a shared disk. */
    public function photos(Tenant $tenant): array
    {
        return collect((array) config('website_starters.'.$this->preset($tenant).'.photos', []))
            ->map(fn (array $photo, int $index): array => [
                'id' => 'starter-'.$this->preset($tenant).'-'.$index,
                'name' => 'Starter photo '.($index + 1), 'url' => $photo['url'],
                'alt_text' => $photo['alt'], 'source' => 'starter', 'context' => 'Example photography',
            ])->all();
    }

    public function approvedPhotoPaths(): array
    {
        return collect((array) config('website_starters'))->flatMap(fn (array $preset): array => array_column($preset['photos'], 'url'))->unique()->values()->all();
    }

    public function modes(Tenant $tenant): array
    {
        $preset = (array) config('website_starters.'.$this->preset($tenant));

        return ['business_mode' => $preset['mode'], 'offering_mode' => $preset['offering'], 'visitor_actions' => ['contact_business']];
    }

    private function definition(Tenant $tenant, string $preset, ?string $businessName = null): array
    {
        $data = (array) config('website_starters.'.$preset);
        $name = strip_tags(mb_substr(trim((string) ($businessName ?: $tenant->brandProfile?->display_name ?: $tenant->name)), 0, 120));
        $photos = $data['photos'];
        $photo = $photos[0];
        $detail = $photos[1] ?? $photo;
        $retail = ($data['mode'] ?? '') === 'retail';
        $offers = array_map(fn (string $title): array => ['heading' => $title, 'body' => 'Ask us about the options for your needs.', 'image_url' => $detail['url'], 'image_alt' => $detail['alt']], $data['offers']);
        $gallery = array_map(fn (array $image): array => ['image_url' => $image['url'], 'image_alt' => $image['alt']], $photos);
        $pages = [
            $this->page('/', 'Home', 'home', [$this->hero($name, $data['intro'], $photo), ['type' => 'service_cards', 'heading' => $data['offer_title'], 'items' => $offers], ['type' => 'image_with_text', 'heading' => 'Let’s talk', 'body' => 'Tell us what you have in mind.', 'image_url' => $detail['url'], 'image_alt' => $detail['alt'], 'cta_label' => 'Contact', 'cta_url' => '/contact']]),
            $this->page('offerings', $data['offer_title'], 'services', [$this->hero($data['offer_title'], $data['offer_intro'], $detail), ['type' => 'service_cards', 'heading' => 'Explore the options', 'items' => $offers], $this->contact()]),
            $this->page('gallery', 'Gallery', 'landing', [$this->hero('A closer look', 'Example photography to set the scene. Ask us about our current work.', $detail), ['type' => 'gallery', 'heading' => 'Inspiration', 'items' => $gallery], $this->contact()]),
            $this->page('about', 'About', 'about', [$this->hero('About '.$name, 'Get to know our approach and the people behind the business.', $photo), ['type' => 'text', 'heading' => 'Start a conversation', 'body' => 'We would like to hear what matters to you. Contact us to learn more about our work.'], $this->contact()]),
            $this->page('faq', 'Questions', 'faq', [$this->hero('Questions', 'A simple place to begin.', $detail), ['type' => 'faq_list', 'heading' => 'Good to know', 'items' => [['heading' => 'How do I get started?', 'body' => 'Send a message with a few details about what you need.'], ['heading' => 'Where can I learn more?', 'body' => 'Explore our offerings or contact us to discuss your questions.']]], $this->contact()]),
            $this->page('contact', 'Contact', 'contact', [$this->hero('Contact '.$name, 'Share a few details so we can discuss the next step.', $photo), $this->contact()]),
        ];
        if ($retail) {
            // Catalog cards are resolved from this site's actual products, never
            // starter records, invented availability or example product photos.
            $catalog = ['type' => 'product_grid', 'heading' => 'Shop', 'body' => '', 'cta_label' => 'View all', 'cta_url' => '/shop'];
            $pages[0]['blocks'][1] = $catalog;
            $pages[1]['blocks'] = [$this->hero('Collections', 'Explore our current catalog.', $detail), $catalog, $this->contact()];
        }
        foreach ($pages as &$page) {
            $page['seo'] = ['title' => $page['title'].' · '.$name, 'description' => $data['intro']];
        }
        unset($page);

        return [
            'key' => 'starter-'.$preset, 'name' => $data['name'], 'description' => 'Six private pages. Your business, your photos.',
            'thumbnail' => $photo['url'], 'recommended' => $preset === $this->preset($tenant),
            'settings' => ['theme_key' => 'starter-'.$preset, 'theme_name' => $name, 'theme_palette' => ['ink' => '#183f35', 'brand' => '#246a55', 'surface' => '#fffefa', 'soft' => '#f0f4ee', 'accent' => '#e5c18a'], 'typography' => 'sans', 'corners' => 'rounded', 'content_width' => 'wide', 'announcement' => ['enabled' => false, 'text' => '', 'url' => '/contact'], 'footer' => ['copyright' => '© '.now()->year.' '.$name, 'tagline' => ''], 'social_links' => []],
            'pages' => $pages,
            'navigation' => array_map(fn (array $page): array => ['label' => $page['title'], 'url' => $page['slug'] === '/' ? '/' : '/'.$page['slug'], 'type' => 'page'], $pages),
        ];
    }

    private function hero(string $heading, string $body, array $photo): array
    {
        return ['type' => 'hero', 'heading' => $heading, 'body' => $body, 'image_url' => $photo['url'], 'image_alt' => $photo['alt'], 'cta_label' => 'Contact', 'cta_url' => '/contact'];
    }

    private function contact(): array
    {
        return ['type' => 'contact_form', 'heading' => 'Get in touch', 'body' => 'Tell us what you are looking for.'];
    }

    private function page(string $slug, string $title, string $type, array $blocks): array
    {
        return ['slug' => $slug, 'title' => $title, 'page_type' => $type, 'blocks' => $blocks, 'seo' => ['title' => $title, 'description' => 'Explore '.$title.'.']];
    }
}
