<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_policy_requires_twelve_characters(): void
    {
        $this->assertTrue(Validator::make(['password' => '12345678901'], ['password' => PasswordRule::defaults()])->fails());
        $this->assertTrue(Validator::make(['password' => '123456789012'], ['password' => PasswordRule::defaults()])->passes());
    }

    public function test_authenticated_change_rejects_short_and_overlong_passwords(): void
    {
        $user = User::factory()->create();
        foreach (['short-pass', str_repeat('x', 73)] as $password) {
            $this->actingAs($user)->put('/settings/password', ['current_password' => 'password', 'password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
        }
    }

    public function test_reset_rejects_short_password_without_consuming_the_token(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'short-pass', 'password_confirmation' => 'short-pass'])->assertSessionHasErrors('password');
        $this->assertTrue(Password::tokenExists($user, $token));
    }
}
