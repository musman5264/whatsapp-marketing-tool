<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Models\SocialAccount;

interface SocialNetworkInterface
{
    /** Return the network slug, e.g. 'facebook'. */
    public function network(): string;

    /** Publish a post and return the platform post ID. */
    public function publish(SocialAccount $account, array $postData): string;

    /** Fetch basic account info after OAuth. Return array with: account_id, name, picture_url. */
    public function fetchAccountInfo(string $accessToken): array;
}
