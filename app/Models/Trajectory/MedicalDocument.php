<?php

namespace App\Models\Trajectory;

use Illuminate\Database\Eloquent\Model;

class MedicalDocument extends Model
{
    protected $table = 'trajectory_medical_documents';

    protected $guarded = ['id'];

    protected $hidden = ['contents', 'sha256'];

    public const LIST_COLUMNS = ['id', 'need_id', 'metadata', 'byte_size', 'created_at'];

    protected function casts(): array
    {
        return ['metadata' => 'encrypted:array', 'contents' => 'encrypted', 'byte_size' => 'integer'];
    }

    public function summary(): array
    {
        return [
            'id' => $this->id, 'need_id' => $this->need_id,
            'filename' => $this->metadata['filename'], 'note' => $this->metadata['note'] ?? null,
            'byte_size' => $this->byte_size, 'saved_on' => $this->created_at->toDateString(),
        ];
    }
}
