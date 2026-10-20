<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Team\TeamFileService;
use Illuminate\Http\Request;

class TeamFileController extends Controller
{
    public function __construct(private TeamFileService $files) {}

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    public function index(Request $request, string $task)
    {
        return response()->json(['files' => $this->files->listing($this->tenant($request), $request->user(), $task)])->header('Cache-Control', 'private, no-store');
    }

    public function begin(Request $request, string $task)
    {
        $input = $request->validate(['client_key' => ['required', 'uuid'], 'filename' => ['required', 'string', 'max:240'],
            'mime_type' => ['required', 'string', 'max:80'], 'size' => ['required', 'integer', 'min:1'], 'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D']]);
        $input['size'] = (int) $input['size'];

        return response()->json($this->files->begin($this->tenant($request), $request->user(), $task, $input))->header('Cache-Control', 'private, no-store');
    }

    public function chunk(Request $request, string $upload, string $index)
    {
        abort_if((int) $request->header('Content-Length', 0) > TeamFileService::CHUNK_BYTES, 413);
        $bytes = $request->getContent();
        abort_if(strlen($bytes) > TeamFileService::CHUNK_BYTES, 413);

        return response()->json($this->files->chunk($this->tenant($request), $request->user(), $upload, (int) $index, $bytes))->header('Cache-Control', 'private, no-store');
    }

    public function complete(Request $request, string $upload)
    {
        return response()->json($this->files->complete($this->tenant($request), $request->user(), $upload))->header('Cache-Control', 'private, no-store');
    }

    public function cancel(Request $request, string $upload)
    {
        return response()->json($this->files->cancel($this->tenant($request), $request->user(), $upload))->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request, string $upload)
    {
        return $this->files->download($this->tenant($request), $request->user(), $upload);
    }
}
