<?php

namespace App\Http\Controllers;

use App\Services\CampusFacilities\CampusReference;
use App\Services\CampusFacilities\DemoStore;
use App\Services\CampusFacilities\Participants;
use App\Services\CampusFacilities\Tickets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Anonymous role-switching fiction. Never resolves native memberships or credentials. */
class CampusFacilitiesDemoController extends Controller
{
    private function boundary(Request $request): void
    {
        $host = strtolower($request->getHost());
        $canonical = strtolower((string) config('tenancy.domains.canonical.landlord_host'));
        $local = app()->environment(['local', 'testing']) && in_array($host, ['localhost', '127.0.0.1'], true);
        abort_unless(config('campus_facilities.enabled') && ! config('enterprise.reviewer_demo_enabled') && (($canonical && $host === $canonical) || $local), 404);
        abort_unless(RateLimiter::attempt('campus-facilities:'.hash('sha256', $request->ip()), 120, fn () => true, 60), 429, 'The demo request limit has been reached. Please wait a minute.');
    }

    public function index(Request $request)
    {
        $this->boundary($request);
        abort_if(array_diff(array_keys($request->query()), ['role', 'ticket']), 404);
        abort_if($request->has('role') && ! in_array($request->query('role'), ['staff', 'head', 'technician', 'admin'], true), 404);
        abort_if($request->has('ticket') && (! is_string($request->query('ticket')) || ! preg_match('/^FM-[A-Z0-9-]{1,30}$/D', $request->query('ticket'))), 404);
        app('livewire')->flushState();
        $html = file_get_contents(public_path('campus-facilities/index.html'));
        $nonce = base64_encode(random_bytes(24));
        $html = preg_replace('/<(script|style)(?=[\s>])/i', '<$1 nonce="'.$nonce.'"', $html);
        $html = preg_replace('/<\/head>/i', LeesMcRaeCampusDemoController::shareMetadata((string) config('tenancy.domains.canonical.landlord_host')).'</head>', $html, 1);

        return response($html)->withHeaders([
            'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'nonce-{$nonce}'; style-src-attr 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; media-src blob:; manifest-src 'self'; worker-src 'self'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'; font-src 'self'",
        ]);
    }

    public function package(Request $request, string $file)
    {
        $this->boundary($request);
        abort_unless(in_array($file, ['sw.js', 'manifest.webmanifest'], true), 404);

        return response(file_get_contents(public_path('campus-facilities/'.$file)))->withHeaders(['Content-Type' => $file === 'sw.js' ? 'text/javascript' : 'application/manifest+json', 'Cache-Control' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function api(Request $request, DemoStore $store, Tickets $tickets, string $endpoint)
    {
        $this->boundary($request);
        try {
            if (! $request->isMethod('GET')) {
                abort_unless($request->header('Origin') === $request->getSchemeAndHttpHost(), 403, 'Invalid request origin.');
                abort_if((int) $request->header('Content-Length', '0') > ($endpoint === 'photos' ? 9_000_000 : 40_000), 413, 'This upload is too large.');
            }
            $cookie = null;
            if ($request->isMethod('GET')) {
                $result = match ($endpoint) {
                    'tickets' => $store->access(function (&$state) {
                        $rows = array_values($state['tickets']);
                        usort($rows, fn ($a, $b) => strcmp($b['updatedAt'], $a['updatedAt']));

                        return $rows;
                    }, false),
                    'campus' => app(CampusReference::class)->read($request->query('refresh') === '1'),
                    'voice' => ['configured' => app(\App\Services\CampusFacilities\LiveVoice::class)->configured(), 'sessionSeconds' => 300, 'dailySessions' => 3],
                    'participants' => $store->access(fn (&$state) => app(Participants::class)->directory($state, $request), false),
                    'messaging/config' => ['channels' => [['channel' => 'email', 'configured' => false, 'credentialsConfigured' => false, 'recipients' => []]], 'subject' => 'Lees-McRae Facilities demo — test notification', 'content' => '[DEMO TEST] A sample work order has an update.'],
                    'messaging/history' => [],
                    default => abort(404),
                };
            } else {
                $input = $request->json()->all();
                $result = match ($endpoint) {
                    'tickets' => ($input['action'] ?? '') === 'comment' ? app(\App\Services\CampusFacilities\Comments::class)->post($store, $request, $input) : $store->access(fn (&$state) => $tickets->save($state, $input)),
                    'participants' => app(Participants::class)->register($store, $request),
                    'reset' => $store->access(function (&$state) use ($input, $tickets, $store) {
                        $actor = $tickets->actor($input);
                        abort_unless($actor['role'] === 'admin' && ($input['confirm'] ?? false) === true, 403, 'Choose the administrator demo role and confirm reset.');
                        // Preserve completed upload metadata and daily quota across reset.
                        $seed = $store->seed();
                        $state['tickets'] = $seed['tickets'];
                        $state['request_keys'] = $seed['request_keys'];

                        return ['reset' => true];
                    }),
                    'photos' => $this->upload($request, $store),
                    'assistant' => app(\App\Services\CampusFacilities\CampusAssistant::class)->answer($store, $input),
                    'voice' => app(\App\Services\CampusFacilities\LiveVoice::class)->start($store, $input),
                    'voice/close' => app(\App\Services\CampusFacilities\LiveVoice::class)->close($store, $input),
                    'voice/draft' => app(\App\Services\CampusFacilities\VoiceDraft::class)->update($input),
                    'messaging/test' => abort(503, 'Email testing needs approved server credentials, sender and recipients.'),
                    default => abort(404),
                };
            }

            if ($endpoint === 'participants' && ! $request->isMethod('GET')) {
                $cookie = $result['cookie'] ?? null;
                unset($result['cookie']);
            }
            $response = response()->json($result)->withHeaders(['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer']);

            return $cookie ? $response->cookie(cookie('lmc_facilities', $cookie, 43200, '/platform/demos/lees-mcrae', null, $request->isSecure(), true, false, 'Lax')) : $response;
        } catch (ValidationException $error) {
            return response()->json(['error' => collect($error->errors())->flatten()->first()], 422)->header('Cache-Control', 'no-store');
        } catch (HttpExceptionInterface $error) {
            return response()->json(['error' => $error->getMessage() ?: 'This demo action is unavailable.'], $error->getStatusCode())->header('Cache-Control', 'no-store');
        } catch (Throwable $error) {
            report($error);

            return response()->json(['error' => 'The shared demo is temporarily unavailable. Your draft is preserved.'], 503)->header('Cache-Control', 'no-store');
        }
    }

    public function verify(Request $request, DemoStore $store, string $id, string $token)
    {
        $this->boundary($request);
        $apply = $request->isMethod('POST');
        if ($apply) {
            abort_unless($request->header('Origin') === $request->getSchemeAndHttpHost(), 403);
        }
        app(Participants::class)->verify($store, $id, $token, $apply);
        $home = '/platform/demos/lees-mcrae/';
        $content = $apply ? '<h1>Email confirmed</h1><p>You can now receive mention emails for this demo.</p><a href="'.$home.'">Open the demo</a>' : '<h1>Confirm your email</h1><p>Enable the ticket mention emails you requested for the Lees-McRae demo.</p><form method="post"><button type="submit">Confirm email</button></form>';

        return response('<!doctype html><html lang="en"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Everbranch · Email confirmation</title><body><main><h2>Everbranch</h2>'.$content.'</main></body></html>')->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex', 'Content-Security-Policy' => "default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'", 'X-Content-Type-Options' => 'nosniff']);
    }

    private function upload(Request $request, DemoStore $store): array
    {
        Validator::make($request->all(), ['file' => 'required|file|mimetypes:image/jpeg,image/png,image/webp|max:7812'])->validate();
        $file = $request->file('file');
        $dimensions = @getimagesize($file->getRealPath());
        abort_unless($dimensions && $dimensions[0] <= 12000 && $dimensions[1] <= 12000 && $dimensions[0] * $dimensions[1] <= 25_000_000, 422, 'Choose a photo under 25 megapixels.');
        abort_unless(function_exists('imagecreatefromstring'), 503, 'Image processing is temporarily unavailable.');
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        abort_unless($image, 422, 'This image could not be decoded.');
        $id = (string) Str::uuid();
        $path = $store->directory().'/'.$id.'.jpg';
        try {
            // Re-encoding removes EXIF/GPS and validates actual decoded image bytes.
            abort_unless(imagejpeg($image, $path, 88), 503);
            chmod($path, 0600);
            $photo = ['id' => $id, 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 100), 'size' => filesize($path), 'url' => '/platform/demos/lees-mcrae/api/photos/'.$id];
            $store->access(function (&$state) use ($photo) {
                $day = now()->format('Y-m-d');
                abort_if(($state['usage'][$day] ?? 0) >= 100 || count($state['attachments']) >= 1000 || array_sum(array_column($state['attachments'], 'size')) + $photo['size'] > 500_000_000, 429, 'The demo photo limit has been reached.');
                $state['usage'] = array_filter($state['usage'], fn ($key) => $key >= now()->subDays(7)->format('Y-m-d'), ARRAY_FILTER_USE_KEY);
                $state['usage'][$day] = ($state['usage'][$day] ?? 0) + 1;
                $state['attachments'][$photo['id']] = $photo;
            });

            return $photo;
        } catch (Throwable $error) {
            if (is_file($path)) {
                unlink($path);
            } throw $error;
        } finally {
            imagedestroy($image);
        }
    }

    public function photo(Request $request, DemoStore $store, string $id)
    {
        $this->boundary($request);
        abort_unless(Str::isUuid($id), 404);
        $exists = $store->access(fn (&$state) => isset($state['attachments'][$id]), false);
        $path = $store->directory().'/'.$id.'.jpg';
        abort_unless($exists && is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store', 'Content-Security-Policy' => "default-src 'none'"]);
    }
}
