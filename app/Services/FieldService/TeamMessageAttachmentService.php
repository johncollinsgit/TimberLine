<?php

namespace App\Services\FieldService;

use App\Models\TeamChannel;
use App\Models\TeamMessage;
use App\Models\TeamMessageAttachment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TeamMessageAttachmentService
{
    public const MAX_BYTES = 50 * 1024 * 1024;

    public const CHUNK_BYTES = 512 * 1024;

    public function initialize(Tenant $tenant, User $user, TeamChannel $channel, array $data): TeamMessageAttachment
    {
        $expired = TeamMessageAttachment::query()->forTenantId((int) $tenant->id)->where('uploaded_by_user_id', $user->id)
            ->whereNull('team_message_id')->where('expires_at', '<', now())->get();
        foreach ($expired as $upload) {
            if ($upload->storage_path) {
                Storage::disk('local')->delete($upload->storage_path);
            }
            $upload->delete();
        }

        return DB::transaction(function () use ($tenant, $user, $channel, $data): TeamMessageAttachment {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = TeamMessageAttachment::query()->forTenantId((int) $tenant->id)->where('uploaded_by_user_id', $user->id)
                ->where('client_uuid', $data['client_uuid'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->team_channel_id === (int) $channel->id && (int) $existing->file_size === (int) $data['file_size']
                    && $existing->mime_type === $data['mime_type'] && $existing->file_name === basename($data['file_name']), 409, 'This upload key belongs to a different file.');

                return $existing;
            }
            $pendingBytes = TeamMessageAttachment::query()->forTenantId((int) $tenant->id)->where('uploaded_by_user_id', $user->id)
                ->whereNull('team_message_id')->sum('file_size');
            abort_if($pendingBytes + (int) $data['file_size'] > 250 * 1024 * 1024, 422, 'Finish or cancel your pending file uploads first.');
            $upload = TeamMessageAttachment::query()->create([
                'tenant_id' => $tenant->id, 'team_channel_id' => $channel->id, 'uploaded_by_user_id' => $user->id,
                'client_uuid' => $data['client_uuid'], 'file_name' => basename($data['file_name']),
                'mime_type' => $data['mime_type'], 'file_size' => $data['file_size'], 'expires_at' => now()->addHours(2),
            ]);
            $upload->forceFill(['storage_path' => 'team-messages/'.$tenant->id.'/'.$channel->id.'/'.$upload->id.'-'.$upload->client_uuid.'.upload'])->save();

            return $upload;
        });
    }

    public function token(TeamMessageAttachment $upload): string
    {
        return hash_hmac('sha256', 'team-file:'.$upload->tenant_id.':'.$upload->uploaded_by_user_id.':'.$upload->client_uuid, (string) config('app.key'));
    }

    public function chunk(Tenant $tenant, User $user, TeamChannel $channel, int $id, array $data): int
    {
        $bytes = base64_decode($data['contents_base64'], true);
        abort_unless(is_string($bytes) && strlen($bytes) > 0 && strlen($bytes) <= self::CHUNK_BYTES, 422, 'The file chunk is invalid.');
        abort_unless(hash_equals(strtolower($data['checksum_sha256']), hash('sha256', $bytes)), 422, 'The file chunk checksum does not match.');

        return DB::transaction(function () use ($tenant, $user, $channel, $id, $data, $bytes): int {
            $upload = $this->locked($tenant, $user, $channel, $id, $data['token']);
            abort_unless($upload->status === 'uploading', 409, 'This upload is already complete.');
            $offset = (int) $data['offset'];
            abort_if($offset + strlen($bytes) > (int) $upload->file_size, 422, 'The file chunk exceeds the declared size.');
            Storage::disk('local')->makeDirectory(dirname($upload->storage_path));
            $handle = fopen(Storage::disk('local')->path($upload->storage_path), 'c+b');
            abort_unless($handle !== false, 503, 'The file could not be stored.');
            try {
                abort_unless(flock($handle, LOCK_EX), 503, 'The file is busy. Try again.');
                $size = (int) fstat($handle)['size'];
                abort_if($offset > $size, 409, 'Upload the preceding file chunk first.');
                fseek($handle, $offset);
                $storedLength = 0;
                if ($offset < $size) {
                    // A process may stop after appending only part of a chunk, before updating its row.
                    // Verify the durable prefix before finishing that same chunk on retry.
                    $storedLength = min(strlen($bytes), $size - $offset);
                    abort_unless(hash_equals(hash('sha256', substr($bytes, 0, $storedLength)), hash('sha256', (string) fread($handle, $storedLength))), 409, 'This chunk conflicts with bytes already stored.');
                }
                if ($storedLength < strlen($bytes)) {
                    $remaining = substr($bytes, $storedLength);
                    fseek($handle, $size);
                    if (fwrite($handle, $remaining) !== strlen($remaining)) {
                        // Preserve the previously durable prefix when storage rejects a short write.
                        ftruncate($handle, $size);
                        fflush($handle);
                        if (function_exists('fsync')) {
                            fsync($handle);
                        }
                        abort(503, 'The file chunk could not be saved completely.');
                    }
                    abort_unless(fflush($handle) && (! function_exists('fsync') || fsync($handle)), 503, 'The file chunk could not be saved durably.');
                    $size += strlen($remaining);
                }
                $upload->forceFill(['received_bytes' => $size])->save();

                return $size;
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        });
    }

    public function complete(Tenant $tenant, User $user, TeamChannel $channel, int $id, string $token, string $checksum): TeamMessageAttachment
    {
        return DB::transaction(function () use ($tenant, $user, $channel, $id, $token, $checksum): TeamMessageAttachment {
            $upload = $this->locked($tenant, $user, $channel, $id, $token);
            if (in_array($upload->status, ['ready', 'attached'], true)) {
                abort_unless(hash_equals((string) $upload->checksum_sha256, strtolower($checksum)), 409, 'The completed file checksum differs.');

                return $upload;
            }
            $path = Storage::disk('local')->path($upload->storage_path);
            abort_unless(is_file($path) && filesize($path) === (int) $upload->file_size, 422, 'The file upload is incomplete.');
            abort_unless(hash_equals(strtolower($checksum), hash_file('sha256', $path)), 422, 'The complete file checksum does not match.');
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            abort_unless(in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true) && $mime === $upload->mime_type, 422, 'Choose a PDF, JPEG, PNG, or WebP file.');
            $upload->forceFill(['status' => 'ready', 'received_bytes' => $upload->file_size, 'checksum_sha256' => strtolower($checksum)])->save();

            return $upload;
        });
    }

    public function attach(Tenant $tenant, User $user, TeamChannel $channel, TeamMessage $message, array $ids): void
    {
        foreach ($ids as $id) {
            $upload = TeamMessageAttachment::query()->forTenantId((int) $tenant->id)->where('team_channel_id', $channel->id)
                ->where('uploaded_by_user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($upload->status === 'ready' && $upload->team_message_id === null && $upload->expires_at?->isFuture(), 422, 'A selected file is unavailable. Upload it again.');
            $upload->forceFill(['team_message_id' => $message->id, 'status' => 'attached', 'expires_at' => null])->save();
        }
    }

    public function cancel(Tenant $tenant, User $user, TeamChannel $channel, int $id, string $token): void
    {
        DB::transaction(function () use ($tenant, $user, $channel, $id, $token): void {
            $upload = $this->locked($tenant, $user, $channel, $id, $token);
            abort_if($upload->team_message_id !== null, 409, 'This file is already part of a message.');
            Storage::disk('local')->delete($upload->storage_path);
            $upload->delete();
        });
    }

    private function locked(Tenant $tenant, User $user, TeamChannel $channel, int $id, string $token): TeamMessageAttachment
    {
        $upload = TeamMessageAttachment::query()->forTenantId((int) $tenant->id)->where('team_channel_id', $channel->id)
            ->where('uploaded_by_user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();
        abort_unless(hash_equals($this->token($upload), $token), 404);
        abort_if($upload->expires_at?->isPast(), 410, 'This file upload expired. Choose the file again.');

        return $upload;
    }
}
