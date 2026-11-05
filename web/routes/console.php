<?php

use App\Models\User;
use App\Services\AppointmentNotifications;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

Artisan::command('medicore:create-admin', function () {
    $name = $this->ask('Nom');
    $email = $this->ask('Adresse e-mail');
    $password = $this->secret('Mot de passe (12 caractères minimum)');
    $validator = Validator::make(compact('name', 'email', 'password'), [
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255|unique:users,email',
        'password' => ['required', 'string', Password::defaults(), 'max:72'],
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

Artisan::command('medicore:appointment-reminders', function () {
    $count = app(AppointmentNotifications::class)->remind();
    $this->info($count.' rappel(s) créé(s).');
})->purpose('Créer les rappels de rendez-vous des membres ayant donné leur accord');

Artisan::command('medicore:dispatch-push', function () {
    $count = app(AppointmentNotifications::class)->dispatchPushes();
    $this->info($count.' notification(s) mise(s) en file.');
})->purpose('Transmettre les notifications persistantes au worker push');

Schedule::command('medicore:appointment-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('medicore:dispatch-push')->everyMinute()->withoutOverlapping();
