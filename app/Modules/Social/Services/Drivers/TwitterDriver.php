<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Models\SocialAccount;
use Illuminate\Support\Facades\Http;

class TwitterDriver implements SocialNetworkInterface
{
    public function network(): string
    {
        return 'twitter';
    }

    public function fetchAccountInfo(string $accessToken): array
    {
        $res = Http::withToken($accessToken)->get('https://api.twitter.com/2/users/me?user.fields=profile_image_url,name')->json();
        $user = $res['data'] ?? [];

        return [
            'account_id' => $user['id'] ?? '',
            'name' => $user['name'] ?? '',
            'picture_url' => $user['profile_image_url'] ?? null,
        ];
    }

    public function publish(SocialAccount $account, array $postData): string
    {
        $res = Http::timeout(30)->withToken($account->access_token)->post('https://api.twitter.com/2/tweets', [
            'text' => mb_substr($postData['body'] ?? '', 0, 280),
        ])->json();

        return $res['data']['id'] ?? throw new \RuntimeException('Twitter publish failed: '.json_encode($res));
    }
}
