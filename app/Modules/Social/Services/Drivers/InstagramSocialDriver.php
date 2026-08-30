<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Exceptions\PermanentPublishException;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Support\Facades\Http;

class InstagramSocialDriver implements SocialNetworkInterface
{
    public function network(): string
    {
        return 'instagram';
    }

    public function fetchAccountInfo(string $accessToken): array
    {
        $res = Http::timeout(30)->get('https://graph.instagram.com/me', [
            'fields' => 'id,name,profile_picture_url',
            'access_token' => $accessToken,
        ])->json();

        return [
            'account_id' => $res['id'] ?? '',
            'name' => $res['name'] ?? '',
            'picture_url' => $res['profile_picture_url'] ?? null,
        ];
    }

    public function publish(SocialAccount $account, array $postData): string
    {
        $igUserId = $account->account_id;
        $token = $account->access_token;

        // Step 1: Create media container
        $containerPayload = ['caption' => $postData['body'] ?? '', 'access_token' => $token];
        $mediaUrls = array_values(array_filter($postData['media_urls'] ?? [], fn ($u) => $u !== null && $u !== ''));
        if (! empty($mediaUrls)) {
            $containerPayload['image_url'] = $mediaUrls[0];
        } else {
            throw new PermanentPublishException('Instagram posts require at least one image.');
        }

        $container = Http::timeout(30)->post("https://graph.facebook.com/v19.0/{$igUserId}/media", $containerPayload)->json();
        $creationId = $container['id'] ?? null;
        if (! $creationId) {
            throw new \RuntimeException('Instagram container creation failed: '.json_encode($container));
        }

        // Step 2: Wait until the container finishes processing. Publishing
        // immediately fails intermittently with "Media ID is not available".
        $this->waitForContainer($creationId, $token);

        // Step 3: Publish
        $res = Http::timeout(30)->post("https://graph.facebook.com/v19.0/{$igUserId}/media_publish", [
            'creation_id' => $creationId,
            'access_token' => $token,
        ])->json();

        return $res['id'] ?? throw new \RuntimeException('Instagram publish failed: '.json_encode($res));
    }

    private function waitForContainer(string $creationId, string $token, int $maxAttempts = 10): void
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $res = Http::timeout(15)->get("https://graph.facebook.com/v19.0/{$creationId}", [
                'fields' => 'status_code',
                'access_token' => $token,
            ])->json();

            $status = $res['status_code'] ?? null;

            if ($status === 'FINISHED') {
                return;
            }

            if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                throw new \RuntimeException('Instagram media container failed: '.json_encode($res));
            }

            sleep(2);
        }

        throw new \RuntimeException("Instagram media container not ready after {$maxAttempts} checks.");
    }
}
