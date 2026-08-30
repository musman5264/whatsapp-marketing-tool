<?php

namespace App\Modules\Social\Models;

use Illuminate\Database\Eloquent\Model;

class SocialAccount extends Model
{
    protected $table = 'social_media_accounts';

    protected $fillable = ['workspace_id', 'network', 'account_id', 'name', 'picture_url', 'access_token', 'refresh_token', 'token_expires_at', 'scopes', 'meta', 'active'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'meta' => 'array',
            'active' => 'boolean',
            'token_expires_at' => 'datetime',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
        ];
    }

    public function posts()
    {
        return $this->belongsToMany(SocialPost::class, 'social_media_post_accounts', 'social_account_id', 'post_id');
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    /**
     * True when the user must re-authorize: the account was deactivated (dead
     * refresh token) or its token expired with no refresh path. An expired
     * token WITH a refresh token is fine — it renews automatically, both on the
     * 15-minute refresh schedule and inline at publish time.
     */
    public function getNeedsReconnectAttribute(): bool
    {
        if (! $this->active) {
            return true;
        }

        return $this->isTokenExpired() && empty($this->refresh_token);
    }
}
