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

Le SDK Android manque sur la machine de validation : aucun APK n'a été produit. La compilation iOS nécessite macOS/Xcode ; les capacités Keychain et les comportements sur appareils restent à vérifier. Les identifiants `com.medicore` sont provisoires. Les modules cliniques/financiers mobiles, notifications, mode hors ligne et publication restent à réaliser.
