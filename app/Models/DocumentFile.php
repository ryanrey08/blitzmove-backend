<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One uploaded file of a vehicle or driver document (a document can have several). */
#[Fillable(['file_path', 'original_name', 'mime_type', 'file_size', 'uploaded_by'])]
class DocumentFile extends Model
{
    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function document(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
