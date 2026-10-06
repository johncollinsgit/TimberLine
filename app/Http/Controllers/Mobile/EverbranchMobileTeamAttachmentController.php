<?php

namespace App\Http\Controllers\Mobile;

use App\Models\TeamChannel;
use App\Models\TeamMessageAttachment;
use App\Services\FieldService\TeamCommunicationService;
use App\Services\FieldService\TeamMessageAttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EverbranchMobileTeamAttachmentController extends EverbranchMobileTeamController
{
    public function initialize(Request $request, string $tenant, TeamChannel $channel, TeamCommunicationService $team, TeamMessageAttachmentService $files)
    {
        $data = $request->validate([
            'file_name' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'in:application/pdf,image/jpeg,image/png,image/webp'],
            'file_size' => ['required', 'integer', 'min:1', 'max:'.TeamMessageAttachmentService::MAX_BYTES],
            'client_uuid' => ['required', 'uuid'],
        ]);
        $team->assertAccess($this->tenant($request), $this->user($request), $channel);
        $upload = $files->initialize($this->tenant($request), $this->user($request), $channel, $data);

        return response()->json(['upload_id' => $upload->id, 'token' => $files->token($upload), 'chunk_size' => TeamMessageAttachmentService::CHUNK_BYTES, 'received_bytes' => $upload->received_bytes], 201);
    }

    public function chunk(Request $request, string $tenant, TeamChannel $channel, int $attachment, TeamCommunicationService $team, TeamMessageAttachmentService $files)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'], 'offset' => ['required', 'integer', 'min:0'],
            'contents_base64' => ['required', 'string', 'max:700000'], 'checksum_sha256' => ['required', 'regex:/^[a-fA-F0-9]{64}$/'],
        ]);
        $team->assertAccess($this->tenant($request), $this->user($request), $channel);

        return response()->json(['received_bytes' => $files->chunk($this->tenant($request), $this->user($request), $channel, $attachment, $data)]);
    }

    public function complete(Request $request, string $tenant, TeamChannel $channel, int $attachment, TeamCommunicationService $team, TeamMessageAttachmentService $files)
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64'], 'checksum_sha256' => ['required', 'regex:/^[a-fA-F0-9]{64}$/']]);
        $team->assertAccess($this->tenant($request), $this->user($request), $channel);
        $upload = $files->complete($this->tenant($request), $this->user($request), $channel, $attachment, $data['token'], $data['checksum_sha256']);

        return response()->json(['attachment' => ['id' => $upload->id, 'name' => $upload->file_name, 'mime_type' => $upload->mime_type, 'size' => $upload->file_size]]);
    }

    public function cancel(Request $request, string $tenant, TeamChannel $channel, int $attachment, TeamCommunicationService $team, TeamMessageAttachmentService $files)
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $team->assertAccess($this->tenant($request), $this->user($request), $channel);
        $files->cancel($this->tenant($request), $this->user($request), $channel, $attachment, $data['token']);

        return response()->json(['ok' => true]);
    }

    public function download(Request $request, string $tenant, TeamChannel $channel, int $attachment, TeamCommunicationService $team)
    {
        $team->assertAccess($this->tenant($request), $this->user($request), $channel);
        $file = TeamMessageAttachment::query()->forTenantId((int) $this->tenant($request)->id)->where('team_channel_id', $channel->id)
            ->where('status', 'attached')->whereHas('message', fn ($query) => $query->whereNull('deleted_at')->where('team_channel_id', $channel->id))->findOrFail($attachment);
        abort_unless(Storage::disk('local')->exists($file->storage_path), 404);

        return Storage::disk('local')->download($file->storage_path, $file->file_name, ['Content-Type' => $file->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
