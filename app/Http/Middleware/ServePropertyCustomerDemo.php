<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ServePropertyCustomerDemo
{
    private const EXTENSIONS = ['html', 'js', 'css', 'json', 'webmanifest', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'ico', 'txt'];

    private const MIME_TYPES = [
        'text/html', 'text/html; charset=utf-8', 'text/css', 'text/css; charset=utf-8',
        'text/javascript', 'text/javascript; charset=utf-8', 'application/javascript', 'application/javascript; charset=utf-8',
        'application/json', 'application/json; charset=utf-8', 'application/manifest+json', 'application/manifest+json; charset=utf-8',
        'image/svg+xml', 'image/png', 'image/jpeg', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon',
        'text/plain', 'text/plain; charset=utf-8',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Check the actual authority before proxy or tenant middleware can reinterpret it.
        $host = strtolower(trim((string) $request->headers->get('host', '')));
        $host = rtrim((string) preg_replace('/:[0-9]+$/D', '', $host), '.');
        $configured = config('property_customer_demo.hosts', [config('property_customer_demo.host', 'property.theeverbranch.com')]);
        $hosts = is_array($configured) ? array_values(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? strtolower(rtrim(trim($value), '.')) : '',
            $configured,
        ))) : [];

        if (! in_array($host, $hosts, true)) {
            return $next($request);
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $this->closed(405, ['Allow' => 'GET, HEAD']);
        }

        $path = $request->getPathInfo();
        $query = (string) $request->server->get('QUERY_STRING', '');
        if ($query !== '' && ($path !== '/' || $query !== 'portal=1')) {
            return $this->closed(404);
        }

        $assets = config('property_customer_demo.assets', []);
        $asset = is_array($assets) ? ($assets[$path] ?? null) : null;
        if (! is_array($asset) || ! is_string($asset['file'] ?? null) || ! is_string($asset['mime'] ?? null)) {
            return $this->closed(404);
        }

        $file = $asset['file'];
        $mime = strtolower(trim($asset['mime']));
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/-]*\z/D', $file)
            || in_array('.', explode('/', $file), true)
            || in_array('..', explode('/', $file), true)
            || ! in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::EXTENSIONS, true)
            || ! in_array($mime, self::MIME_TYPES, true)) {
            return $this->closed(404);
        }

        $base = realpath(public_path('property-demo'));
        $resolved = $base === false ? false : realpath($base.DIRECTORY_SEPARATOR.$file);
        if ($base === false || $resolved === false || ! is_file($resolved)
            || ! str_starts_with($resolved, $base.DIRECTORY_SEPARATOR)) {
            return $this->closed(404);
        }

        $contents = @file_get_contents($resolved);
        if (! is_string($contents)) {
            return $this->closed(404);
        }

        return new Response($request->isMethod('HEAD') ? '' : $contents, 200, [
            ...$this->headers(),
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($contents),
        ]);
    }

    private function closed(int $status, array $headers = []): Response
    {
        return new Response('', $status, [...$this->headers(), ...$headers]);
    }

    private function headers(): array
    {
        return [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; style-src-attr 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; frame-src 'self' data: blob:; connect-src 'self' data:; manifest-src 'self'; object-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'",
        ];
    }
}
