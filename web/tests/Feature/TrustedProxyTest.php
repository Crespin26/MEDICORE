<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_only_configured_proxy_can_supply_https_and_client_ip(): void
    {
        config(['app.trusted_proxies' => ['10.0.0.10']]);
        foreach (['10.0.0.10' => true, '10.0.0.99' => false] as $peer => $trusted) {
            $request = Request::create('http://clinic.test/up', server: ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '203.0.113.10']);
            (new TrustProxies)->handle($request, function ($request) use ($trusted, $peer) {
                $this->assertSame($trusted, $request->isSecure());
                $this->assertSame($trusted ? '203.0.113.10' : $peer, $request->ip());

                return response('ok');
            });
        }
    }

    public function test_empty_proxy_configuration_does_not_trust_platform_looking_host(): void
    {
        config(['app.trusted_proxies' => []]);
        $request = Request::create('http://untrusted.on-forge.com/up', server: ['REMOTE_ADDR' => '10.0.0.10', 'HTTP_X_FORWARDED_PROTO' => 'https']);
        (new TrustProxies)->handle($request, function ($request) {
            $this->assertFalse($request->isSecure());

            return response('ok');
        });
    }
}
