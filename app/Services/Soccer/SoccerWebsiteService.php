<?php

namespace App\Services\Soccer;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\User;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class SoccerWebsiteService
{
    public function fields(): array
    {
        return ['hero_title' => 'Hero title', 'hero_subtitle' => 'Hero introduction', 'hero_image' => 'Hero photo URL', 'announcement' => 'Announcement', 'about_title' => 'About title', 'about_body' => 'About text', 'registration_title' => 'Registration heading', 'registration_body' => 'Registration introduction', 'footer' => 'Footer text', 'facebook_url' => 'Facebook link', 'primary_color' => 'Primary color', 'accent_color' => 'Accent color', 'background_color' => 'Background color', 'heading_font' => 'Heading font', 'show_about' => 'Show about section', 'show_schedule' => 'Show schedule section', 'menu_labels' => 'Navigation labels (About|Schedule|Register|Family login)'];
    }

    public function defaults(): array
    {
        return ['hero_title' => 'Joy in the game. Rooted in faith.', 'hero_subtitle' => 'Wren Family Soccer is a Monday-night community for kids and families to play, grow, and belong.', 'hero_image' => '/youth-soccer.jpg', 'announcement' => 'Spring 2027 registration', 'about_title' => 'All the good parts of soccer. None of the pressure.', 'about_body' => 'A welcoming space where kids can learn real skills, grow in character, and enjoy being part of a team.', 'registration_title' => 'Play with Wren.', 'registration_body' => 'Choose your returning players, review your family details, and get ready for the new season.', 'footer' => 'Wren Family Soccer · Easley, South Carolina', 'facebook_url' => 'https://www.facebook.com/p/Wren-Family-Soccer-61577868965701/', 'primary_color' => '#061827', 'accent_color' => '#b8e85b', 'background_color' => '#f2f5ef', 'heading_font' => 'Oswald', 'show_about' => 'yes', 'show_schedule' => 'yes', 'menu_labels' => 'About|Schedule|Register|Family login'];
    }

    public function editor(Tenant $t, User $u, bool $publish = false): void
    {
        abort_unless(app(SoccerAccess::class)->admin($t, $u) && app(TenantModuleAccessResolver::class)->canAccess($t->id, 'managed_website') && app(ManagedWebsiteService::class)->editorEnabledFor($t), 403);
        if ($publish) {
            abort_unless(app(ManagedWebsiteService::class)->publishingEnabled(), 423);
        }
    }

    public function site(Tenant $t): ?TenantSite
    {
        return TenantSite::query()->forTenant($t)->first();
    }

    public function initialize(Tenant $t, User $u): TenantSite
    {
        $this->editor($t, $u);
        $site = $this->site($t) ?? app(ManagedWebsiteService::class)->createSite($t, $u);
        if (data_get($site->settings, 'connected_renderer') === 'wren_soccer_v1') {
            return $site;
        }

        return DB::transaction(function () use ($site, $u) {
            $site->settings = [...($site->settings ?? []), 'connected_renderer' => 'wren_soccer_v1'];
            $site->save();
            $v = $this->version($site, $this->defaults(), $u, 'draft');
            $site->update(['draft_site_version_id' => $v->id]);

            return $site;
        });
    }

    private function version(TenantSite $site, array $content, User $u, string $status)
    {
        return $site->siteVersions()->create(['tenant_id' => $site->tenant_id, 'version_number' => $site->siteVersions()->max('version_number') + 1, 'status' => $status, 'settings' => ['connected_renderer' => 'wren_soccer_v1', 'connected_content' => $content], 'navigation' => [], 'seo' => [], 'source_manifest' => ['origin' => config('soccer.origin')], 'created_by_user_id' => $u->id, 'published_at' => $status === 'published' ? now() : null]);
    }

    public function content(TenantSite $site, int $id): array
    {
        $v = $site->siteVersions()->where('tenant_id', $site->tenant_id)->findOrFail($id);
        abort_unless(data_get($v->settings, 'connected_renderer') === 'wren_soccer_v1', 404);

        return data_get($v->settings, 'connected_content');
    }

    public function save(Tenant $t, User $u, array $content, int $expected, bool $publish = false, ?int $restore = null): void
    {
        $this->editor($t, $u, $publish);
        DB::transaction(function () use ($t, $u, $content, $expected, $publish, $restore) {
            $site = $this->site($t);
            $site = TenantSite::whereKey($site->id)->lockForUpdate()->firstOrFail();
            abort_unless($site->draft_site_version_id === $expected, 409, 'A newer draft exists. Reload before saving.');
            if ($publish) {
                $content = $this->content($site, $expected);
            } elseif ($restore) {
                $content = $this->content($site, $restore);
            }
            abort_unless(count($content) === count($this->fields()) && ! array_diff_key($content, $this->fields()), 422, 'Website field list changed.');
            foreach ($content as $key => $v) {
                abort_unless(is_string($v) && strlen($v) <= 8000, 422);
                if (str_ends_with($key, '_color')) {
                    abort_unless(preg_match('/^#[0-9a-f]{6}$/i', $v), 422);
                }if ($key === 'heading_font') {
                    abort_unless(in_array($v, ['Oswald', 'Arial', 'Georgia'], true), 422);
                }if (in_array($key, ['hero_image', 'facebook_url'], true)) {
                    abort_unless(preg_match('~^/(?!/)[\w/.%-]+$~', $v) || str_starts_with($v, 'https://') && filter_var($v, FILTER_VALIDATE_URL), 422);
                }
            }
            $v = $this->version($site, $content, $u, $publish ? 'published' : 'draft');
            $site->update($publish ? ['published_site_version_id' => $v->id, 'status' => 'published', 'public_enabled' => true, 'published_at' => now()] : ['draft_site_version_id' => $v->id]);
            app(ManagedWebsiteService::class)->recordEvent($site, null, $u, $publish ? 'soccer.website_published' : 'soccer.website_draft', ['version_id' => $v->id, 'previous_draft' => $expected]);
        });
    }

    public function preview(Tenant $t, User $u): string
    {
        $this->editor($t, $u);
        $site = $this->site($t);

        return config('soccer.origin').'/?__preview='.urlencode(Crypt::encryptString(json_encode(['tenant' => $t->id, 'version' => $site->draft_site_version_id, 'actor' => $u->id, 'expires' => now()->addMinutes(15)->timestamp])));
    }

    public function publicContent(?string $preview): array
    {
        $t = Tenant::where('slug', config('soccer.tenant_slug'))->firstOrFail();
        $site = $this->site($t);
        abort_unless($site && data_get($site->settings, 'connected_renderer') === 'wren_soccer_v1', 404);
        if ($preview) {
            try {
                $c = json_decode(Crypt::decryptString($preview), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                abort(403);
            }abort_unless($c['tenant'] === $t->id && $c['expires'] > time(), 403);
            $u = User::findOrFail($c['actor']);
            $this->editor($t, $u);
            $id = $c['version'];
        } else {
            abort_unless(app(ManagedWebsiteService::class)->publicRenderingEnabled() && app(TenantModuleAccessResolver::class)->canAccess($t->id, 'managed_website') && $site->public_enabled && $site->status === 'published', 423);
            $id = $site->published_site_version_id;
        }

        return ['content' => $this->content($site, $id), 'version' => $id, 'preview' => (bool) $preview];
    }
}
