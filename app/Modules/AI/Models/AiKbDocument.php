<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiKbDocument extends Model
{
    protected $table = 'ai_kb_documents';

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = ['kb_id', 'source_type', 'source_ref', 'title', 'status', 'tokens', 'last_indexed_at'];

    protected function casts(): array
    {
        return ['last_indexed_at' => 'datetime', 'tokens' => 'integer'];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeBase::class, 'kb_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(AiKbChunk::class, 'document_id');
    }
}
