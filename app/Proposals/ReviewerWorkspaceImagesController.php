<?php

namespace App\Proposals;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Fixed private image reads; recipients have NO scan endpoint or native identity header. */
final class ReviewerWorkspaceImagesController
{
    public function __construct(private ReviewerWorkspaceImages $images, private ReviewerAiHttpPolicy $http) {}

    private function respond(Request $request, string $token, string $kind): Response
    {
        try {
            abort_unless(config('service_proposals.reviewer_workspace.image_access.enabled') === true, 404);
            abort_unless($request->header('Origin') === $request->getSchemeAndHttpHost()
                && $request->header('Sec-Fetch-Site', 'same-origin') === 'same-origin', 403);
            $fields = ['binding', 'selection', 'target'];
            if ($kind !== 'metadata') {
                $fields = array_merge($fields, ['upload_id', 'attestation_id', 'attestation_revision']);
            }
            $input = $this->http->body($request, $fields);
            $key = 'proposal-images:'.hash('sha256', $request->getHost().'|'.$request->ip().'|'.$request->session()->getId());
            $ip = 'proposal-images-ip:'.hash('sha256', $request->getHost().'|'.$request->ip());
            abort_if(RateLimiter::tooManyAttempts($key, 100) || RateLimiter::tooManyAttempts($ip, 200), 429);
            RateLimiter::hit($key, 60);
            RateLimiter::hit($ip, 60);
            if ($kind === 'metadata') {
                $response = response()->json($this->images->metadata($request, $token, $input));
            } else {
                $v = $this->images->binary($request, $token, $input, $kind);
                $response = response($v['bytes'], 200, ['Content-Type' => $v['mime'], 'Content-Length' => (string) strlen($v['bytes']),
                    'Content-Disposition' => 'inline; filename="private-image"', 'X-Everbranch-Image-Workspace' => $v['workspace_id'],
                    'X-Everbranch-Image-Version' => $v['version_hash'], 'X-Everbranch-Image-Upload' => $v['upload_id'],
                    'X-Everbranch-Image-Original-SHA256' => $v['original_sha256'], 'X-Everbranch-Image-SHA256' => $v['sha256'],
                    'X-Everbranch-Image-Attestation' => $v['attestation_id'], 'X-Everbranch-Image-Revision' => (string) $v['attestation_revision'],
                    'X-Everbranch-Image-Kind' => $v['kind'], 'X-Everbranch-Image-Expires' => $v['expires_at']]);
            }
        } catch (Throwable $error) {
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof ModelNotFoundException ? 404 : ($error instanceof \JsonException ? 422 : 503));
            $response = response()->json(['error' => 'workspace_image_unavailable', 'automatic_retry' => false],
                in_array($status, [400, 403, 404, 409, 410, 419, 422, 429, 503], true) ? $status : 503);
        }
        $request->attributes->set('service_proposal_workspace_image_response', true);
        if ($response instanceof \Illuminate\Http\JsonResponse) {
            $request->attributes->set('service_proposal_workspace_response', true);
        }

        return $response->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    }

    public function metadata(Request $request, string $token): Response
    {
        return $this->respond($request, $token, 'metadata');
    }

    public function thumbnail(Request $request, string $token): Response
    {
        return $this->respond($request, $token, 'thumbnail');
    }

    public function preview(Request $request, string $token): Response
    {
        return $this->respond($request, $token, 'preview');
    }
}
