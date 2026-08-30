<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Models\SocialAccount;
use Illuminate\Support\Facades\Http;

class LinkedInDriver implements SocialNetworkInterface
{
    public function network(): string
    {
        return 'linkedin';
    }

    public function fetchAccountInfo(string $accessToken): array
    {
        $res = Http::withToken($accessToken)
            ->get('https://api.linkedin.com/v2/me?projection=(id,localizedFirstName,localizedLastName,profilePicture(displayImage~:playableStreams))')
            ->json();

        // Extract the smallest available profile picture thumbnail.
        $elements = $res['profilePicture']['displayImage~']['elements'] ?? [];
        $pictureUrl = ! empty($elements) ? ($elements[0]['identifiers'][0]['identifier'] ?? null) : null;

        return [
            'account_id' => $res['id'] ?? '',
            'name'        => trim(($res['localizedFirstName'] ?? '').' '.($res['localizedLastName'] ?? '')),
            'picture_url' => $pictureUrl,
        ];
    }

    public function publish(SocialAccount $account, array $postData): string
    {
        $urn = "urn:li:person:{$account->account_id}";
        $res = Http::timeout(30)->withToken($account->access_token)->post('https://api.linkedin.com/v2/ugcPosts', [
            'author' => $urn,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => ['text' => $postData['body'] ?? ''],
                    'shareMediaCategory' => 'NONE',
                ],
            ],
            'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
        ])->json();

        return $res['id'] ?? throw new \RuntimeException('LinkedIn publish failed: '.json_encode($res));
    }
}
