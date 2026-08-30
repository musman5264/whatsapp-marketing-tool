<?php

namespace App\Modules\Social\Models;

use Illuminate\Database\Eloquent\Model;

class SocialPostAccount extends Model
{
    protected $table = 'social_media_post_accounts';

    protected $fillable = ['post_id', 'social_account_id', 'status', 'platform_post_id', 'error', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function post()
    {
        return $this->belongsTo(SocialPost::class, 'post_id');
    }

    public function account()
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }
}
