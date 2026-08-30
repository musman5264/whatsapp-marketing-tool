<?php

namespace App\Modules\Social\Models;

use Illuminate\Database\Eloquent\Model;

class SocialPost extends Model
{
    protected $table = 'social_media_posts';

    protected $fillable = ['workspace_id', 'title', 'body', 'media_urls', 'target_accounts', 'status', 'scheduled_at', 'timezone', 'published_at', 'provider_post_id', 'post_url', 'publish_results', 'ai_generated', 'ai_prompt'];

    protected function casts(): array
    {
        return [
            'media_urls' => 'array',
            'target_accounts' => 'array',
            'publish_results' => 'array',
            'ai_generated' => 'boolean',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function accountLinks()
    {
        return $this->hasMany(SocialPostAccount::class, 'post_id');
    }

    public function accounts()
    {
        return $this->belongsToMany(SocialAccount::class, 'social_media_post_accounts', 'post_id', 'social_account_id');
    }
}
