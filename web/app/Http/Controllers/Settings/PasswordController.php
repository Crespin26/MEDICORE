<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\PasswordUpdater;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /**
     * Show the user's password settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/password', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's password.
     */
    public function update(Request $request, PasswordUpdater $updater): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:1000'],
            'password' => ['required', 'string', Password::defaults(), 'max:72', 'confirmed'],
        ]);

        $updater->change($request->user(), $validated['current_password'], $validated['password']);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login')->with('status', 'Mot de passe modifié. Reconnectez-vous sur vos appareils.');
    }
}
