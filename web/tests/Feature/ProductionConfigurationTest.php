<?php

namespace Tests\Feature;

use App\Support\ProductionConfiguration;
use Tests\TestCase;

class ProductionConfigurationTest extends TestCase
{
    private function configureProduction(): void
    {
        config([
            'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://clinic.example.org',
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'app.previous_keys' => [],
            'backup.key' => 'base64:'.base64_encode(str_repeat('b', 32)),
            'session.secure' => true, 'session.http_only' => true, 'session.encrypt' => true,
            'session.same_site' => 'lax', 'session.driver' => 'database', 'cache.default' => 'database',
            'logging.default' => 'daily', 'logging.channels.daily.level' => 'warning', 'mail.default' => 'smtp',
            'app.trusted_proxies' => ['10.0.0.10'],
        ]);
    }

    public function test_safe_configuration_has_no_configuration_failures(): void
    {
        $this->configureProduction();
        $this->assertSame([], ProductionConfiguration::issues());
    }

    public function test_unsafe_configuration_is_reported_without_secrets(): void
    {
        $this->configureProduction();
        config(['app.debug' => true, 'app.url' => 'http://localhost', 'session.secure' => false, 'mail.default' => 'log', 'app.trusted_proxies' => ['*'], 'backup.key' => config('app.key')]);
        $issues = ProductionConfiguration::issues();
        $this->assertCount(6, $issues);
        $this->assertStringNotContainsString(config('app.key'), implode(' ', $issues));
    }

    public function test_application_url_rejects_credentials_and_invalid_keys_fail_closed(): void
    {
        $this->configureProduction();
        config(['app.url' => 'https://operator@clinic.example.org', 'app.key' => 'base64:invalid', 'backup.key' => null]);
        $this->assertCount(3, ProductionConfiguration::issues());
    }

    public function test_command_rejects_development_configuration(): void
    {
        $this->artisan('medicore:production-check')->expectsOutputToContain('APP_ENV doit être production.')->assertFailed();
    }
}
