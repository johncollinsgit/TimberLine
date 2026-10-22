<?php

namespace App\Enterprise;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

/** Generic install metadata only. No service worker, offline queue, tenant lookup or credential. */
final class AppManifest
{
    public static function links(Request $request): array
    {
        if (config('enterprise.runtime') !== true || config('enterprise.pwa_enabled') !== true
            || config('enterprise_runtime.trial_mount_only') === true || ! MountUrls::matches($request)) {
            return [];
        }

        return ['manifest' => MountUrls::url('/enterprise/app.webmanifest'), 'apple_icon' => MountUrls::url('/enterprise/app-icon-180.png'),
            'theme_color' => '#244d3d', 'name' => 'Everbranch Enterprise', 'version_url' => MountUrls::url('/enterprise/app-version'), 'version' => self::version()];
    }

    public static function version(): string
    {
        $release = config('app.release_id');
        if (is_string($release) && preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $release) && $release !== 'unknown') {
            return $release;
        }
        $hash = hash_init('sha256');
        foreach (['resources/views/enterprise/app.blade.php', 'resources/views/enterprise/workspace.blade.php',
            'resources/views/enterprise/app-install-head.blade.php', 'resources/views/enterprise/app-install.blade.php',
            'resources/js/enterprise/transport.js', 'resources/js/enterprise/workspace.tsx', 'resources/js/enterprise/workspace.css',
            'apps/enterprise/public/build/manifest.json'] as $relative) {
            hash_update($hash, $relative."\0");
            $path = base_path($relative);
            hash_update($hash, is_file($path) ? hash_file('sha256', $path) : 'absent');
        }

        return hash_final($hash);
    }

    private function guard(Request $request): void
    {
        abort_unless(self::links($request) !== [], 404);
        abort_if($request->query->count() !== 0 || (string) $request->server('QUERY_STRING', '') !== '', 404);
    }

    public function manifest(Request $request): JsonResponse
    {
        $this->guard($request);
        $base = MountUrls::origin().MountUrls::prefix().'/';
        $icons = [];
        foreach ([192, 512] as $size) {
            $icons[] = ['src' => MountUrls::url('/enterprise/app-icon-'.$size.'.png'), 'sizes' => $size.'x'.$size, 'type' => 'image/png', 'purpose' => 'any'];
        }

        return new JsonResponse(['id' => $base, 'name' => 'Everbranch Enterprise', 'short_name' => 'Everbranch',
            'description' => 'Your authorized Enterprise workspace. An internet connection and sign-in are required.',
            'lang' => 'en', 'start_url' => $base, 'scope' => $base, 'display' => 'standalone', 'theme_color' => '#244d3d',
            'background_color' => '#f7f8f2', 'icons' => $icons, 'prefer_related_applications' => false], 200,
            ['Content-Type' => 'application/manifest+json; charset=UTF-8', 'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff', 'Cross-Origin-Resource-Policy' => 'same-origin', 'Referrer-Policy' => 'no-referrer']);
    }

    public function icon(Request $request, string $size): BinaryFileResponse
    {
        $this->guard($request);
        abort_unless(in_array($size, ['180', '192', '512'], true), 404);
        $path = base_path('resources/images/enterprise-app/icon-'.$size.'.png');
        abort_unless(is_file($path), 404);

        return new BinaryFileResponse($path, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff', 'Cross-Origin-Resource-Policy' => 'same-origin'], true);
    }

    public function currentVersion(Request $request): JsonResponse
    {
        $this->guard($request);

        return new JsonResponse(['version' => self::version(), 'update_strategy' => 'manual_reload', 'offline_support' => false], 200,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Cross-Origin-Resource-Policy' => 'same-origin']);
    }
}
