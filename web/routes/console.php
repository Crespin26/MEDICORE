<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;

Artisan::command('medicore:create-admin', function () {
    $name = $this->ask('Nom');
    $email = $this->ask('Adresse e-mail');
    $password = $this->secret('Mot de passe (12 caractères minimum)');
    $validator = Validator::make(compact('name', 'email', 'password'), [
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255|unique:users,email',
        'password' => 'required|string|min:12',
    ]);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }
    $user = new User(compact('name', 'email', 'password'));
    $user->role = 'admin';
    $user->save();
    $this->info('Administrateur créé.');

    return 0;
})->purpose('Créer un administrateur sans inscription publique');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
