# WEB-02 — Gestion des patients

## Disponible

- Création et modification de l’identité et des coordonnées : prénom, nom, date de naissance, téléphone, e-mail et adresse. Les trois derniers champs sont facultatifs.
- Recherche par nom, prénom, combinaison de noms dans n’importe quel ordre, téléphone, e-mail ou référence `MC-<id>`.
- Recherche de texte insensible à la casse et aux accents grâce à un texte normalisé conservé côté serveur. `%` et `_` sont traités littéralement.
- Filtres combinables par intervalle de naissance et présence d’un numéro de téléphone.
- Pagination de 20 fiches avec conservation des critères et ordre stable.
- Page détaillée `/patients/{id}`, avec modification selon les permissions.
- Messages de confirmation et erreurs de validation.

Les administrateurs et secrétaires peuvent créer et modifier les fiches. Les médecins peuvent les consulter. Les caissiers et les comptes historiques sans rôle attribué n’y ont pas accès. Les champs de cette version sont administratifs ; aucun antécédent, diagnostic ou document médical n’est exposé à la secrétaire.

## Doublons

L’association prénom, nom et date de naissance est normalisée pour les espaces et la casse. Une empreinte interne avec contrainte unique empêche deux créations simultanées de cette même identité. Le message de validation invite à vérifier la fiche existante. Les téléphones et e-mails partagés ne constituent pas un doublon bloquant.

Ce contrôle est une heuristique : deux personnes peuvent avoir les mêmes nom, prénom et date de naissance. Aucun contournement automatique ni fusion n’est proposé. Une procédure de distinction des homonymes devra être définie avant un usage réel. L’accentuation reste significative pour l’identité, alors qu’elle est ignorée pour la recherche.

La migration conserve les doublons éventuellement présents avant cette version : elle attribue l’empreinte au premier enregistrement du groupe, sans supprimer ni fusionner les autres. Un ancien doublon doit être vérifié et distingué avant qu’une modification puisse lui attribuer la même identité. Aucun dossier existant n’est effacé.

## Conservation et archivage

Aucune route ni action de suppression physique n’est exposée. Aucune purge automatique n’est exécutée.

Le prochain travail d’archivage devra être réversible, réservé à l’administrateur et tracé, avec date, auteur et motif. Une fiche archivée devra conserver sa référence et ses liens métiers. Avant l’implémentation, définir les situations d’archivage, les permissions de consultation et la politique de conservation de l’établissement. Aucune durée réglementaire n’est présumée ici.

## Dépendances restantes

Les rendez-vous sont désormais reliés au patient (voir [WEB-03](WEB-03.md)) et visibles sur la fiche selon les permissions. Les consultations et factures seront rattachées lors de la réalisation de ces modules.

## Mise à jour et vérifications

```bash
cd web
php artisan migrate
npm run build
php artisan test
npm run typecheck
npm run lint:check
```

La nouvelle migration ajoute les coordonnées facultatives, l’empreinte d’identité et le texte de recherche, puis prépare les fiches existantes. Les données techniques ne sont pas envoyées au navigateur.

Les tests couvrent la recherche, les accents, les caractères littéraux, les filtres combinés, la pagination, les détails, les permissions, les coordonnées et la prévention des doublons lors de la création et de la modification.
