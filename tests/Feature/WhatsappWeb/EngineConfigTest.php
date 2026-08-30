<?php

namespace Tests\Feature\WhatsappWeb;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\WhatsappWeb\Services\EngineManager;
use App\Modules\WhatsappWeb\Services\Waha\WahaAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EngineConfigTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function credentials_are_null_when_not_configured(): void
    {
        $this->assertNull(CredentialResolver::system()->whatsappWeb());
        $this->assertFalse(app(EngineManager::class)->enabled());
    }

    #[Test]
    public function credentials_resolve_when_config_row_enabled(): void
    {
        IntegrationConfig::create([
            'provider' => 'whatsapp_web',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha:3000', 'api_key' => 'k'],
            'webhook_secret' => 'shhh',
        ]);

        $vo = CredentialResolver::system()->whatsappWeb();
        $this->assertNotNull($vo);
        $this->assertSame('http://waha:3000', $vo->baseUrl());
        $this->assertSame('k', $vo->apiKey());
        $this->assertSame('shhh', $vo->webhookSecret());

        $manager = app(EngineManager::class);
        $this->assertTrue($manager->enabled());
        $this->assertInstanceOf(WahaAdapter::class, $manager->adapter());
    }

    #[Test]
    public function disabled_config_row_is_ignored(): void
    {
        IntegrationConfig::create([
            'provider' => 'whatsapp_web',
            'mode' => 'live',
            'enabled' => false,
            'credentials' => ['engine' => 'waha', 'base_url' => 'http://waha:3000'],
        ]);

        $this->assertNull(CredentialResolver::system()->whatsappWeb());
        $this->assertFalse(app(EngineManager::class)->enabled());
    }
}
