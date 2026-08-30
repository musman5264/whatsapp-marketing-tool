<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Models\SocialAccount;
use Illuminate\Support\Facades\Http;

class FacebookDriver implements SocialNetworkInterface
{
    public function network(): string
    {
        return 'facebook';
    }

    public function fetchAccountInfo(string $accessToken): array
    {
        $res = Http::timeout(30)->get('https://graph.facebook.com/v19.0/me', [
            'fields' => 'id,name,picture',
            'access_token' => $accessToken,
        ])->json();

        return [
            'account_id' => $res['id'] ?? '',
            'name' => $res['name'] ?? '',
            'picture_url' => $res['picture']['data']['url'] ?? null,
        ];
    }

    public function publish(SocialAccount $account, array $postData): string
    {
        $pageId = $account->meta['page_id'] ?? $account->account_id;
        $token  = $account->access_token;
        $message = $postData['body'] ?? '';
        $mediaUrls = array_values(array_filter($postData['media_urls'] ?? [], fn ($u) => $u !== null && $u !== ''));

        // Single image → POST /{page}/photos
        if (count($mediaUrls) === 1) {
            $res = Http::timeout(30)->post("https://graph.facebook.com/v19.0/{$pageId}/photos", [
                'url'          => $mediaUrls[0],
                'caption'      => $message,
                'access_token' => $token,
            ])->json();

            if (! isset($res['id'])) {
                throw new \RuntimeException('Facebook photo publish failed: '.json_encode($res));
            }

            return $res['post_id'] ?? $res['id'];
        }

        // Multiple images → upload each as unpublished, then publish together
        if (count($mediaUrls) > 1) {
            $attachedMedia = [];

            foreach ($mediaUrls as $url) {
                $upload = Http::timeout(30)->post("https://graph.facebook.com/v19.0/{$pageId}/photos", [
                    'url'          => $url,
                    'published'    => false,
                    'access_token' => $token,
                ])->json();

                if (! isset($upload['id'])) {
                    throw new \RuntimeException('Facebook photo upload failed: '.json_encode($upload));
                }

                $attachedMedia[] = ['media_fbid' => $upload['id']];
            }

            $res = Http::timeout(30)->post("https://graph.facebook.com/v19.0/{$pageId}/feed", [
                'message'        => $message,
                'attached_media' => $attachedMedia,
                'access_token'   => $token,
            ])->json();

            if (! isset($res['id'])) {
                throw new \RuntimeException('Facebook multi-photo publish failed: '.json_encode($res));
            }

            return $res['id'];
        }

        // Text-only post
        $res = Http::timeout(30)->post("https://graph.facebook.com/v19.0/{$pageId}/feed", [
            'message'      => $message,
            'link'         => $postData['link'] ?? null,
            'access_token' => $token,
        ])->json();

        if (! isset($res['id'])) {
            throw new \RuntimeException('Facebook publish failed: '.json_encode($res));
        }

        return $res['id'];
    }
}
