<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\MedicalDocument;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MedicalDocumentStore
{
    public const MIME_EXTENSIONS = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/heic' => 'heic', 'image/heif' => 'heif'];

    public function authorizeFolder(User $user, Space $space, ?int $need, bool $lock = false): ?int
    {
        app(FinanceAccess::class)->authorize($user, $space);
        abort_unless($space->kind === 'household', 404);
        if ($lock) {
            $space = Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
            app(FinanceAccess::class)->authorize($user, $space);
        }
        if ($need === null) {
            return null;
        }
        $case = Record::where('space_id', $space->id)->where('kind', 'medical_need')->where('active', true);
        if ($lock) {
            $case->lockForUpdate();
        }

        return $case->findOrFail($need)->id;
    }

    public function save(User $user, Space $space, ?int $need, array $input): MedicalDocument
    {
        $this->authorizeFolder($user, $space, $need);
        $data = Validator::make($input, [
            'document' => ['required', 'file', 'max:8192', 'mimetypes:'.implode(',', array_keys(self::MIME_EXTENSIONS))],
            'note' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $file = $data['document'];
        $bytes = $file->get();
        abort_if($bytes === false || $bytes === '', 422, 'The selected file could not be read.');
        $hash = hash('sha256', $bytes);
        $type = $file->getMimeType();
        $extension = self::MIME_EXTENSIONS[$type];
        $filename = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $stem = preg_replace('/[\p{C}\/\\\\]+/u', '', pathinfo($filename, PATHINFO_FILENAME));
        $stem = trim(Str::limit($stem ?? '', 170, ''), ' .');
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $matchesType = strtolower($ext) === $extension || ($extension === 'jpg' && strtolower($ext) === 'jpeg');
        $ext = $matchesType ? $ext : $extension;
        $filename = ($stem !== '' ? $stem : 'Medical document').'.'.$ext;

        return DB::transaction(function () use ($user, $space, $need, $bytes, $hash, $type, $filename, $data) {
            $this->authorizeFolder($user, $space, $need, true);
            $existing = MedicalDocument::where('space_id', $space->id)->where('need_id', $need)->where('sha256', $hash)->first(MedicalDocument::LIST_COLUMNS);

            return $existing ?? MedicalDocument::create([
                'space_id' => $space->id, 'need_id' => $need, 'uploaded_by' => $user->id, 'sha256' => $hash,
                'byte_size' => strlen($bytes), 'contents' => $bytes,
                'metadata' => ['filename' => $filename, 'mime' => $type, 'note' => $data['note'] ?? null],
            ]);
        }, 3);
    }
}
