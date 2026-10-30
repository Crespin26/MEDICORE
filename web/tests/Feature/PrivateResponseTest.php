<?php

namespace Tests\Feature;

use Tests\TestCase;

class PrivateResponseTest extends TestCase
{
    public function test_exceptions_remain_private_even_without_route_middleware(): void
    {
        foreach (['/missing-private-page', '/api/v1/missing-private-resource'] as $path) {
            $this->get($path)->assertNotFound()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'same-origin');
        }
    }
}
