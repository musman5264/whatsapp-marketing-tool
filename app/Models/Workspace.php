<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    use HasFactory;

    protected $table = 'workspaces';

    protected $fillable = [
        'owner_id',
        'client_id',
        'name',
        'default_locale',
        'currency_code',
    ];

    protected $attributes = [
        'default_locale' => 'en',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Users who are members of this workspace (via pivot). */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** Users whose primary workspace is this one. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'workspace_id');
    }

    /** Whether the given user can access this workspace (owner or member). */
    public function isAccessibleBy(User $user): bool
    {
        if ($this->owner_id === $user->id) {
            return true;
        }

        return $this->members()->where('user_id', $user->id)->exists();
    }
}
