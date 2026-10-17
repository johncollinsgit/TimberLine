<?php

namespace App\Http\Controllers\Retail;

use App\Http\Controllers\Controller;
use App\Services\Retail\RetailShowroomService;
use App\Services\Retail\RetailVoiceDemoContext;
use App\Services\Retail\RetailVoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RetailVoiceController extends Controller
{
    public function __construct(private RetailShowroomService $showroom, private RetailVoiceService $voice) {}

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function status(Request $request): JsonResponse
    {
        if (! $this->showroom->owner($request->user())) {
            return $this->response(['message' => 'Owner access is required.'], 403);
        }
        $status = $this->voice->status();

        return $this->response($status, $status['available'] ? 200 : 503);
    }

    public function session(Request $request): JsonResponse
    {
        if (! $this->showroom->owner($request->user())) {
            return $this->response(['message' => 'Owner access is required.'], 403);
        }
        if (! $request->isJson()) {
            return $this->response(['message' => 'Send the voice offer as JSON.'], 415);
        }
        if (array_diff(array_keys($request->all()), ['sdp', 'merchant', 'page', 'focus_customer', 'sample_activity'])) {
            return $this->response(['message' => 'Only the voice offer, demo business, page and sample activity are accepted.'], 422);
        }
        $validator = Validator::make($request->all(), [
            'sdp' => ['required', 'string', 'min:20', 'max:120000', function ($attribute, $value, $fail) {
                if (is_string($value) && ! RetailVoiceService::validSdp($value)) {
                    $fail('A valid audio session offer is required.');
                }
            }],
            'merchant' => ['nullable', 'string', 'max:100', Rule::in($this->voice->merchantSlugs())],
            'page' => ['required', 'string', Rule::in(RetailVoiceService::PAGES)],
        ] + RetailVoiceDemoContext::rules($request->input('merchant')));
        if ($validator->fails()) {
            return $this->response(['message' => 'Check the voice session details.', 'errors' => $validator->errors()->toArray()], 422);
        }
        try {
            return $this->response($this->voice->create($request->user(), $validator->validated()));
        } catch (ValidationException $exception) {
            return $this->response(['message' => 'Check this business’s sample activity.', 'errors' => $exception->errors()], 422);
        } catch (HttpException $exception) {
            return $this->response(['message' => $exception->getMessage()], $exception->getStatusCode());
        }
    }
}
