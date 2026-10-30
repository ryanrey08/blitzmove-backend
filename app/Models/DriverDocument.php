<?php

namespace App\Models;

use App\Enums\DriverDocumentType;
use App\Models\Concerns\HasExpiryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'driver_id', 'document_type', 'title', 'document_number', 'issued_at', 'expires_at',
    'issuing_authority', 'notes', 'uploaded_by',
])]
class DriverDocument extends Model
{
    use HasExpiryStatus, SoftDeletes;

    protected function casts(): array
    {
        return [
            'document_type' => DriverDocumentType::class,
            'issued_at' => 'date',
            'expires_at' => 'date',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class)->withTrashed();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return MorphMany<DocumentFile, $this> */
    public function files(): MorphMany
    {
        return $this->morphMany(DocumentFile::class, 'document')->orderBy('id');
    }
}
