<?php

namespace App\Services;

use App\Models\AppointmentNotification;
use App\Models\PushDevice;
use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class FirebasePush
{
    /** Envoie exclusivement un texte générique ; les erreurs transport sont expurgées des jetons et clés. */
    public function send(PushDevice $device, AppointmentNotification $notification): void
    {
        try {
            $file = config('mobile_notifications.firebase_credentials');
            if (! is_string($file) || ! is_file($file)) {
                throw new RuntimeException;
            }
            $credential = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            if (($credential['type'] ?? '') !== 'service_account' || empty($credential['client_email']) || empty($credential['private_key']) || empty($credential['project_id'])) {
                throw new RuntimeException;
            }
            $cacheKey = 'medicore-fcm:'.hash('sha256', $credential['client_email'].'|'.$credential['project_id'].'|'.($credential['private_key_id'] ?? ''));
            $encryptedAccess = Cache::remember($cacheKey, 3000, function () use ($credential) {
                $auth = new ServiceAccountCredentials('https://www.googleapis.com/auth/firebase.messaging', $credential);
                $client = new Client(['timeout' => 8, 'connect_timeout' => 3]);
                $token = $auth->fetchAuthToken(fn ($request, $options = []) => $client->send($request, $options));
                if (empty($token['access_token'])) {
                    throw new RuntimeException;
                }

                return Crypt::encryptString($token['access_token']);
            });
            $response = Http::withToken(Crypt::decryptString($encryptedAccess))->connectTimeout(3)->timeout(8)
                ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($credential['project_id']).'/messages:send', [
                    'message' => [
                        'fid' => $device->installation_id, 'notification' => AppointmentNotification::message(),
                        'data' => ['notification_id' => $notification->id],
                        'android' => ['ttl' => '3600s', 'collapse_key' => 'medicore', 'notification' => ['tag' => 'medicore']],
                        'apns' => ['headers' => ['apns-collapse-id' => 'medicore', 'apns-expiration' => (string) now()->addHour()->timestamp]],
                    ],
                ]);
            if (collect($response->json('error.details', []))->contains(fn ($detail) => ($detail['errorCode'] ?? '') === 'UNREGISTERED')) {
                $device->delete();

                return;
            }
            if (! $response->successful()) {
                if ($response->status() === 401) {
                    Cache::forget($cacheKey);
                }
                throw new RuntimeException;
            }
        } catch (Throwable) {
            // Never chain provider exceptions: their request may contain bearer/device credentials.
            throw new RuntimeException('Échec de livraison push ; vérifier la configuration et réessayer via le worker.');
        }
    }
}
