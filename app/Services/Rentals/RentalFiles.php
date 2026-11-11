<?php

namespace App\Services\Rentals;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamFileScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RentalFiles
{
    public function __construct(private RentalRepository $repository, private RentalAccess $access, private TeamFileScanner $scanner) {}

    public function upload(Tenant $tenant, User $user, string $property, UploadedFile $file, array $input): array
    {
        Validator::make(['file' => $file] + $input, ['file' => 'required|file|max:5120|mimetypes:image/jpeg,image/png,image/webp,application/pdf,text/plain',
            'requestKey' => 'required|uuid', 'version' => 'required|integer|min:1', 'teamId' => 'nullable|string|max:100'])->validate();
        [$state, $actor] = $this->repository->raw($tenant, $user, $property);
        abort_unless($actor['write'], 403);
        $teamId = (string) ($input['teamId'] ?? '');
        if ($teamId !== '') {
            $this->access->team($state, $actor, $teamId);
        }
        abort_if($file->getSize() < 1 || $file->getSize() > config('rentals.max_file_bytes'), 413);
        $name = Str::limit(preg_replace('/[\x00-\x1f\x7f]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 200, '');
        $hash = hash_file('sha256', $file->getRealPath());
        $mime = $file->getMimeType();
        // Only the existing trusted scanner can admit uploaded bytes. No browser-provided clean flag.
        abort_unless($this->scanner->scan($file->getRealPath(), $mime, $hash) === 'clean', 422, 'The file could not be cleared for sharing. Try again when scanning is available.');
        $path = 'rentals/'.$tenant->id.'/'.$property.'/'.Str::uuid();
        abort_unless(Storage::disk('rentals_private')->put($path, file_get_contents($file->getRealPath())), 503, 'Private file storage is unavailable.');
        try {
            $result = $this->repository->execute($tenant, $user, $property, ['action' => 'file.upload', 'requestKey' => $input['requestKey'],
                'version' => (int) $input['version'], 'name' => $name, 'mime' => $mime, 'sha256' => $hash, 'size' => $file->getSize(), 'teamId' => $teamId],
                function (array $before, array $current, string $now) use ($input, $teamId, $file, $name, $mime, $path, $hash): array {
                    if ($teamId !== '') {
                        $this->access->team($before, $current, $teamId);
                    }
                    abort_if(array_sum(array_column($before['documents'], 'size')) + $file->getSize() > config('rentals.property_file_quota_bytes') || count($before['documents']) >= 1000, 413, 'This property has reached its file allowance.');
                    abort_if(collect($before['documents'])->contains('id', $input['requestKey']), 409);
                    $before['documents'][] = ['id' => $input['requestKey'], 'name' => $name, 'title' => $name, 'type' => $mime, 'size' => $file->getSize(),
                        'teamId' => $teamId, 'storagePath' => $path, 'sha256' => $hash, 'updated' => $now, 'uploadedBy' => $current['name'], 'category' => $teamId !== '' ? 'Private team file' : 'Shared file', 'body' => ''];

                    return $before;
                });
            if ($result['replayed']) {
                Storage::disk('rentals_private')->delete($path);
            }

            return ['state' => $result, 'file' => collect($result['documents'])->firstWhere('id', $input['requestKey'])];
        } catch (\Throwable $error) {
            // A response can fail after the transaction commits. Never remove admitted bytes
            // unless a fresh authorized read proves that this path was not published.
            try {
                [$current] = $this->repository->raw($tenant, $user, $property);
                if (! collect($current['documents'])->contains('storagePath', $path)) {
                    Storage::disk('rentals_private')->delete($path);
                }
            } catch (\Throwable) {
                // Retain private bytes for operator reconciliation while outcome is unknown.
            }
            throw $error;
        }
    }

    public function download(Tenant $tenant, User $user, string $property, string $file)
    {
        [$state, $actor] = $this->repository->raw($tenant, $user, $property);
        $doc = collect($state['documents'])->firstWhere('id', $file);
        abort_unless($doc && isset($doc['storagePath']), 404);
        if (($doc['teamId'] ?? '') !== '') {
            $this->access->team($state, $actor, $doc['teamId']);
        }
        abort_unless(preg_match('#^rentals/'.(int) $tenant->id.'/'.preg_quote($property, '#').'/[a-f0-9-]{36}$#D', $doc['storagePath']), 404);
        $disk = Storage::disk('rentals_private');
        abort_unless($disk->exists($doc['storagePath']) && hash_equals($doc['sha256'], hash_file('sha256', $disk->path($doc['storagePath']))), 404);

        return $disk->response($doc['storagePath'], $doc['name'], ['Content-Type' => $doc['type'], 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'", 'Referrer-Policy' => 'no-referrer']);
    }
}
