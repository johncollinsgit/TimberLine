<?php

namespace App\Proposals;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReviewerLegacyCaptureController
{
    public function __construct(private ReviewerLegacyCapture $capture) {}

    public function show(Request $request, string $workspace, string $versionId): Response
    {
        abort_unless($request->query->count() === 0, 400);
        $context = $this->capture->page($request, $versionId);
        $boot = array_intersect_key($context, array_flip(['version_id', 'pins', 'authorize_url']));
        $boot['csrf'] = $request->session()->token();
        $script = $context['script'];
        $style = 'body{font:16px system-ui;margin:3rem auto;padding:1rem;max-width:42rem;color:#173b30;background:#f8faf7}button{font:inherit;background:#075c46;color:white;border:0;border-radius:.4rem;padding:1rem;cursor:pointer}button:disabled{opacity:.5}p{line-height:1.5}';
        $csp = "default-src 'none'; script-src 'sha256-".base64_encode(hash('sha256', $script, true))."'; script-src-attr 'none'; style-src 'sha256-".base64_encode(hash('sha256', $style, true))."'; connect-src 'self'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'";
        $request->attributes->set('service_proposal_legacy_capture_response', true);

        return response()->view('service-proposals.legacy-capture', compact('boot', 'script', 'style'))
            ->header('Content-Security-Policy', $csp);
    }

    public function authorize(Request $request, string $workspace, string $versionId): Response
    {
        abort_unless($request->query->count() === 0 && strlen($request->getContent()) <= 2048
            && strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) === 'application/json', 400);
        abort_unless($request->header('Origin') === $request->getSchemeAndHttpHost()
            && $request->header('Sec-Fetch-Site') === 'same-origin', 403);
        abort_unless($request->hasSession() && is_string($request->header('X-CSRF-TOKEN')) && $request->session()->token() !== ''
            && hash_equals($request->session()->token(), $request->header('X-CSRF-TOKEN')), 419);
        try {
            $object = json_decode($request->getContent(), false, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(422);
        }
        abort_unless($object instanceof \stdClass && ReviewerLegacyCapture::keys((array) $object, ReviewerLegacyCapture::PIN_KEYS), 422);
        $response = response()->json($this->capture->authorize($request, $versionId, (array) $object));
        abort_unless(strlen($response->getContent()) <= 4096, 503);
        $request->attributes->set('service_proposal_legacy_capture_response', true);

        return $response;
    }
}
