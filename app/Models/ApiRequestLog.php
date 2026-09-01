<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One row per /api/v1 request. Written only by LogApiRequest middleware via the
 * query builder; rows are immutable (no updated_at). Read by ApiUsageController.
 */
class ApiRequestLog extends Model
{
    public $timestamps = false;

    protected $table = 'api_request_logs';

    protected $guarded = ['id'];

    protected $casts = [
        'query' => 'array',
        'request_headers' => 'array',
        'status' => 'integer',
        'duration_ms' => 'integer',
        'response_size_bytes' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Client admins see every token in their client; everyone else sees only
     * their own token activity.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $user->isClientAdministrator()
            ? $query->where('client_id', $user->client_id)
            : $query->where('user_id', $user->id);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'token_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
