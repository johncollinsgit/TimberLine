<?php

namespace Tests\Support;

use App\Proposals\CampusReviewManifest;
use App\Proposals\ReviewerLegacyCapture;
use Illuminate\Filesystem\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Test-only nonsecret source/build snapshot. Never copies runtime, credentials, vendor or private fixture state. */
final class ReviewerWorkspaceSourceSnapshot
{
    public static function run(callable $test): mixed
    {
        $app = app();
        $base = $app->basePath();
        $resource = $app->resourcePath();
        $public = $app->publicPath();
        $bootstrap = $app->bootstrapPath();
        $language = $app->langPath();
        $environment = $app->environmentPath();
        $storage = $app->storagePath();
        if ($resource !== $base.'/resources' || $public !== $base.'/apps/enterprise/public') {
            throw new RuntimeException('Unsupported source snapshot layout.');
        }
        $manifest = (new CampusReviewManifest)->current();
        $module = hash('sha256', ReviewerLegacyCapture::script());
        $temporary = sys_get_temp_dir().'/reviewer-source-snapshot-'.bin2hex(random_bytes(16));
        mkdir($temporary, 0700);
        $count = $bytes = 0;
        try {
            // Complete declared source groups plus public build; no ENV/cache/storage/session roots.
            foreach (['app', 'config', 'routes', 'resources', 'scripts/proposals', 'apps/enterprise/config', 'apps/enterprise/public'] as $directory) {
                $source = $base.'/'.$directory;
                if (! is_dir($source) || is_link($source)) {
                    throw new RuntimeException('Source snapshot group unavailable.');
                }
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    $relative = substr($file->getPathname(), strlen($base) + 1);
                    self::copy($base, $temporary, $relative, $count, $bytes);
                }
            }
            foreach (['apps/enterprise/bootstrap/app.php', 'composer.lock'] as $file) {
                self::copy($base, $temporary, $file, $count, $bytes);
            }
            $app->setBasePath($temporary);
            // resourcePath and path.resources follow setBasePath in this actual Laravel version.
            $app->useBootstrapPath($bootstrap);
            $app->useLangPath($language);
            $app->usePublicPath($temporary.'/apps/enterprise/public');
            if ((new CampusReviewManifest)->current() !== $manifest || hash('sha256', ReviewerLegacyCapture::script()) !== $module
                || $app->environmentPath() !== $environment || $app->storagePath() !== $storage
                || $app->resourcePath() !== $temporary.'/resources' || $app['path.resources'] !== $temporary.'/resources') {
                throw new RuntimeException('Private source snapshot parity failed.');
            }

            return $test($temporary);
        } finally {
            $app->setBasePath($base);
            $app->useBootstrapPath($bootstrap);
            $app->useLangPath($language);
            $app->usePublicPath($public);
            (new Filesystem)->deleteDirectory($temporary);
            if (is_dir($temporary) || $app->environmentPath() !== $environment || $app->storagePath() !== $storage
                || $app->resourcePath() !== $resource || $app['path.resources'] !== $resource || $app->publicPath() !== $public
                || $app->bootstrapPath() !== $bootstrap || $app->langPath() !== $language) {
                throw new RuntimeException('Private source snapshot cleanup failed.');
            }
        }
    }

    private static function copy(string $base, string $temporary, string $relative, int &$count, int &$bytes): void
    {
        $source = $base.'/'.$relative;
        if (! is_file($source) || is_link($source) || filesize($source) > 20000000 || ++$count > 20000 || ($bytes += filesize($source)) > 134217728) {
            throw new RuntimeException('Unsafe or unbounded source snapshot member.');
        }
        $destination = $temporary.'/'.$relative;
        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0700, true);
        }
        if (! copy($source, $destination) || ! chmod($destination, 0644) || hash_file('sha256', $source) !== hash_file('sha256', $destination)) {
            throw new RuntimeException('Source snapshot member copy failed.');
        }
    }
}
