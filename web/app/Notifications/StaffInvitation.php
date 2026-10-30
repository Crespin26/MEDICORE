<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitation extends Notification
{
    public function __construct(public string $url) {}

    /** Utilise le transport mail pour transmettre l’invitation. */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** Construit le message d’activation à partir du lien à usage unique. */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Votre invitation MediCore')
            ->greeting('Bonjour '.$notifiable->name.',')
            ->line('Vous avez été invité à rejoindre le personnel de MediCore.')
            ->action('Activer mon compte', $this->url)
            ->line('Choisissez votre mot de passe pour activer votre compte. Ce lien est valable 48 heures et ne peut être utilisé qu’une fois.')
            ->line('Si vous n’attendiez pas cette invitation, ignorez ce message.');
    }
}
