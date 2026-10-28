<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class RegistrationTest extends TestCase
{
    public function test_registration_is_not_available(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
