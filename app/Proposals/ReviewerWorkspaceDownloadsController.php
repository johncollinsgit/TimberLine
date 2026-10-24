<?php

namespace App\Proposals;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Read-only fixed private PDF attachment endpoints. No owner/scanner/provider route. */
final class ReviewerWorkspaceDownloadsController
{
    public function __construct(private ReviewerWorkspaceDownloads $downloads, private ReviewerAiHttpPolicy $http) {}

    private function respond(Request $request, string $token, bool $binary): Response
    {
        try {
            abort_unless(config('service_proposals.reviewer_workspace.downloads.enabled') === true, 404);
            abort_unless($request->header('Origin') === $request->getSchemeAndHttpHost()
                && $request->header('Sec-Fetch-Site', 'same-origin') === 'same-origin', 403);
            foreach (['Range', 'If-Range', 'If-None-Match', 'If-Modified-Since'] as $header) {
                abort_if($request->headers->has($header), 400);
            }
            $fields = ['binding', 'selection', 'target'];
            if ($binary) {
                $fields = array_merge($fields, ['upload_id', 'attestation_id', 'attestation_revision']);
            }
            $input = $this->http->body($request, $fields);
            $kind = $binary ? 'download' : 'metadata';
            $key = 'proposal-pdf-'.$kind.':'.hash('sha256', $request->getHost().'|'.$request->ip().'|'.$request->session()->getId());
            $ip = 'proposal-pdf-ip-'.$kind.':'.hash('sha256', $request->getHost().'|'.$request->ip());
            abort_if(RateLimiter::tooManyAttempts($key, $binary ? 20 : 100) || RateLimiter::tooManyAttempts($ip, $binary ? 40 : 200), 429);
            RateLimiter::hit($key, 60);
            RateLimiter::hit($ip, 60);
            if (! $binary) {
                $response = response()->json($this->downloads->metadata($request, $token, $input));
            } else {
                $v = $this->downloads->download($request, $token, $input);
                $response = response($v['bytes'], 200, ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) $v['size'],
                    'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $v['filename'], 'attachment.pdf'), 'Accept-Ranges' => 'none',
                    'X-Everbranch-Attachment-Workspace' => $v['workspace_id'], 'X-Everbranch-Attachment-Version' => $v['version_hash'],
                    'X-Everbranch-Attachment-Upload' => $v['upload_id'], 'X-Everbranch-Attachment-SHA256' => $v['original_sha256'],
                    'X-Everbranch-Attachment-Mime' => $v['mime'], 'X-Everbranch-Attachment-Attestation' => $v['attestation_id'],
                    'X-Everbranch-Attachment-Revision' => (string) $v['attestation_revision'], 'X-Everbranch-Attachment-Expires' => $v['expires_at']]);
            }
        } catch (Throwable $error) {
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof ModelNotFoundException ? 404 : ($error instanceof \JsonException ? 422 : 503));
            $response = response()->json(['error' => 'workspace_download_unavailable', 'automatic_retry' => false],
                in_array($status, [400, 403, 404, 409, 410, 419, 422, 429, 503], true) ? $status : 503);
        }
        $request->attributes->set('service_proposal_workspace_download_response', true);
        if ($response instanceof \Illuminate\Http\JsonResponse) {
            $request->attributes->set('service_proposal_workspace_response', true);
        }

        return $response->header('Cache-Control', 'private, no-store, no-transform')->header('Content-Encoding', 'identity')
            ->header('Content-Length', (string) strlen($response->getContent()))->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Content-Security-Policy', "sandbox; default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    }

    public function metadata(Request $request, string $token): Response
    {
        return $this->respond($request, $token, false);
    }

    public function download(Request $request, string $token): Response
    {
        return $this->respond($request, $token, true);
    }
}
