# MediCore mobile

Première version Flutter connectée à l'API Laravel v1 : connexion du personnel, patients et agenda selon les rôles, stockage sécurisé du jeton, expiration et déconnexion. Voir le [guide API/mobile](../docs/API-MOBILE.md) pour les fonctions, limites et réglages de sécurité.

```bash
flutter pub get
flutter analyze
flutter test
flutter run --dart-define=MEDICORE_API_URL=https://votre-serveur/api/v1
```

HTTP est refusé en release. L'émulateur Android peut utiliser un serveur local fictif avec `MEDICORE_API_URL=http://10.0.2.2:8000/api/v1` et `MEDICORE_ALLOW_HTTP=true` en debug uniquement.

Le test HTTP réel est lancé depuis `web/` avec `npm run test:mobile-live` ; le lanceur prépare et détruit une base temporaire. Les autres tests utilisent un transport et un stockage injectés et n'accèdent à aucun compte réel.

Le SDK Android est installé depuis le 30 octobre 2026. Un APK debug a été compilé avec succès et sa signature vérifiée : `build/app/outputs/flutter-apk/app-debug.apk` (Android 7/API 24 minimum, ARM/ARM64/x86_64). Le téléphone USB n’était pas encore détecté ; l’installation et la recette sur appareil restent à effectuer. La compilation iOS nécessite macOS/Xcode ; les capacités Keychain et les comportements sur appareils restent à vérifier. Les identifiants `com.medicore` sont provisoires. Les modules cliniques/financiers mobiles, notifications, mode hors ligne et publication restent à réaliser.

## Téléphone Android branché en USB

Démarrer d’abord Laravel sur `http://127.0.0.1:8000`. Activer les options développeur
et le débogage USB sur le téléphone, le brancher et accepter l’autorisation de cet ordinateur.
Le SDK Android et ses licences doivent être installés sur l’ordinateur.

Depuis la racine du dépôt :

```bash
./scripts/run-mobile-usb.sh
```

Le script détecte un seul téléphone autorisé, crée le transfert USB du port 8000
avec `adb reverse`, puis lance Flutter en debug avec l’API locale. Le serveur peut
rester lié à `127.0.0.1` ; aucun accès Wi-Fi à la base ou au serveur n’est nécessaire.
Avec plusieurs appareils, préciser l’identifiant : `./scripts/run-mobile-usb.sh IDENTIFIANT`.

Si les exécutables ne sont pas dans le PATH :

```bash
MEDICORE_FLUTTER_BINARY=/home/crespin/snap/flutter/common/flutter/bin/flutter \
ANDROID_HOME="$HOME/Android/Sdk" ./scripts/run-mobile-usb.sh
```

Le transfert disparaît au débranchement ; relancer le script à la reconnexion.
Ce lancement HTTP est réservé au debug et reste refusé dans une version release.
