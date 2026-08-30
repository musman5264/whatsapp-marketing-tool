<?php

namespace App\Modules\AI\Services\Llm;

interface LlmProviderInterface
{
    /** @param array $messages [['role' => 'user', 'content' => '...'], ...] */
    public function chat(array $messages, array $opts = []): LlmResponse;

    /** @param string[] $texts */
    public function embed(array $texts): array; // returns array of float[]
}
