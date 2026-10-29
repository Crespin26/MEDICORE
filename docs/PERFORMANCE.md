# Référence de charge locale

Le banc `web/tests/performance/probe.mjs` crée son propre serveur et une base SQLite temporaire avec 1 001 patients et 10 004 rendez-vous fictifs. Il vérifie un identifiant aléatoire de fixture avant tout test : il ne réutilise pas un serveur déjà présent sur le port 8123 et ne vise pas une URL de production.

Après connexion de quatre comptes fictifs, il répartit des lectures authentifiées entre recherche paginée de patients et agenda quotidien. Il mesure les réponses réellement reçues, la latence p50/p95/max et le débit HTTP. Aucun jeton, nom de patient ni contenu médical n'est enregistré dans les résultats.

```bash
cd web
npm run test:load
BENCH_CONCURRENCY=10 BENCH_REQUESTS=100 npm run test:load
```

`BENCH_CONCURRENCY` est borné entre 1 et 20, `BENCH_REQUESTS` entre 1 et 5 000. Les valeurs par défaut sont 5 et 100. Les options PHP de la recette navigateur sont réutilisables avec `MEDICORE_TEST_PHP_OPTIONS`. Le test écrit `test-results/performance.json` et échoue si une réponse n'est pas 200 ; aucun seuil de latence de production n'est inventé.

Les [résultats du 29 octobre](PERFORMANCE-BASELINE.json) donnent deux exécutions courtes :

| Requêtes | Concurrence | HTTP 200 | p50 | p95 | Maximum | Débit |
| --- | --- | --- | --- | --- | --- | --- |
| 100 | 5 | 100 | 104,20 ms | 120,36 ms | 123,29 ms | 47,67 req/s |
| 100 | 10 | 100 | 136,84 ms | 152,94 ms | 165,61 ms | 71,34 req/s |

Ces mesures décrivent uniquement cette machine, PHP en serveur de développement et SQLite. Le cache est celui de la fixture de test ; les limites de débit, le cache partagé de production, HTTPS, un proxy, les workers, un réseau mobile et les ressources de l'hébergement ne sont pas qualifiés par ces chiffres. Les durées excluent la génération des données et les connexions initiales. Il n'y a ni charge soutenue, ni test d'endurance, ni garantie de capacité clinique ou de nombre d'utilisateurs réels.

Le banc teste des lectures. La sûreté des écritures concurrentes reste vérifiée séparément par les tests métier multi-processus de réservation/facturation. Une comparaison entre ces deux courtes exécutions est sensible aux caches et à la charge de la machine ; elle n'établit pas une courbe de capacité.

Pour qualifier un déploiement : définir volumes et objectifs de réponse, choisir le moteur de base et l'hébergement, préparer un jeu fictif représentatif, tester lecture/écriture et endurance sur une instance dédiée, relever ressources/verrous/erreurs, puis valider aussi une restauration. Ne pas lancer des essais sur un établissement réel sans périmètre d'exploitation accepté. Aucun test distant ni test avec données réelles n'a été effectué ici.
