# WEB-06 — Facturation et paiements

## Parcours

Le menu **Facturation** ouvre `/billing`. Les administrateurs et caissiers peuvent consulter les factures, créer un brouillon, l’émettre, enregistrer les paiements et imprimer les documents. Le catalogue et les annulations sont réservés à l’administrateur.

1. En administrateur, ouvrir **Catalogue des actes** et créer des actes avec code, libellé administratif et prix positif.
2. Ouvrir **Nouvelle facture**, rechercher et sélectionner un patient, puis choisir les actes et quantités.
3. Enregistrer le brouillon et vérifier les lignes et totaux calculés par le serveur.
4. Émettre la facture pour lui attribuer son numéro définitif et figer ses lignes.
5. Enregistrer un ou plusieurs paiements déjà reçus ; le solde et le statut sont recalculés.
6. Utiliser **Version imprimable** ou **Imprimer le reçu** pour imprimer ou enregistrer en PDF depuis le navigateur.

Aucun compte rendu, antécédent, allergie, document médical ou diagnostic n’est transmis aux écrans de facturation. Le caissier accède à un sélecteur limité à l’identité des patients ; il n’obtient pas d’accès à leurs fiches cliniques. Le médecin et la secrétaire n’ont pas accès au module financier dans cette version.

Les administrateurs retrouvent aussi les factures depuis la fiche patient. La liste permet de filtrer par statut et par patient via le lien de sa fiche.

## Permissions

| Action | Administrateur | Caissier | Médecin / secrétaire / ancien rôle staff |
| --- | --- | --- | --- |
| Lire les factures, reçus et versions imprimables | Oui | Oui | Non |
| Créer, corriger et émettre un brouillon | Oui | Oui | Non |
| Enregistrer un paiement | Oui | Oui | Non |
| Gérer les actes et consulter leur historique | Oui | Non | Non |
| Annuler une facture ou une écriture de paiement | Oui | Non | Non |

Les droits sont vérifiés sur chaque route et revérifiés dans la transaction avant une mutation financière. Les comptes ayant participé aux opérations sont conservés ; leur suppression personnelle est refusée et leur suspension reste disponible.

## Devise et montants

`CLINIC_CURRENCY=XOF` définit la devise initiale des nouveaux actes et nouvelles factures. Les paramètres enregistrés dans [WEB-08](WEB-08.md) prennent ensuite priorité. Les devises prises en charge sont XOF avec zéro décimale, EUR et USD avec deux décimales. Il n’existe aucune conversion entre devises.

Les entrées monétaires sont des chaînes en unités principales, sans séparateur de milliers : `5000` pour XOF, `12.50` ou `12,50` pour EUR/USD. Les nombres négatifs, zéro, la notation exponentielle et les décimales excédentaires sont refusés.

Les montants sont stockés et calculés côté serveur en unités mineures entières. Les quantités sont des entiers de 1 à 1 000, avec au maximum 50 lignes distinctes par facture. Le total est limité à 1 000 000 000 000 unités mineures. Le client ne fournit ni prix ni total à enregistrer ; ils sont obtenus à partir du catalogue et recalculés par le serveur.

La devise est conservée sur chaque acte et facture. Un changement de configuration ne convertit pas les anciennes opérations. Seuls les actes actifs utilisant la devise de la facture peuvent être sélectionnés pour ses lignes. Un acte devenu inactif reste conservé pour l’historique.

## Factures et statuts

- **Brouillon** : sans numéro définitif, modifiable avec version attendue et motif de correction.
- **Émise** : numérotée, datée et sans paiement actif.
- **Partiellement payée** : paiements actifs strictement compris entre zéro et le total.
- **Payée** : paiements actifs égaux au total.
- **Annulée** : conservée dans l’historique, sans paiement actif et non réactivable.

La numérotation séquentielle globale est `MC-F-00000001`, sans remise à zéro annuelle. Un numéro est attribué une seule fois à l’émission. Une nouvelle soumission de l’émission n’attribue pas un second numéro. Une facture annulée ne peut pas être émise.

Les lignes conservent le code, le libellé et le prix de l’acte à la date de leur enregistrement. Le nom du patient est aussi conservé sur la facture. Les changements ultérieurs du patient ou du catalogue ne modifient pas une facture existante. Une correction du brouillon recalcule toutes ses lignes avec les tarifs actifs actuels ; cette opération exige un motif et crée un événement avec les nouvelles valeurs. Les versions précédentes restent consultables dans l’historique.

Après émission, les lignes ne peuvent plus être modifiées. Pour remplacer une facture incorrecte, l’administrateur doit traiter les paiements éventuels, annuler la facture avec un motif puis en créer une nouvelle. L’association au patient est fixe dès la création ; une erreur de patient se traite par annulation du brouillon et nouvelle création.

## Paiements et reçus

Les paiements sont des enregistrements manuels d’encaissements déjà effectués : espèces, virement ou carte. Le module ne contacte aucune banque ni aucun prestataire et ne collecte pas de numéro de carte ou de coordonnées bancaires.

Chaque paiement doit être positif et inférieur ou égal au solde. Les brouillons, factures annulées et factures déjà soldées ne peuvent pas recevoir de nouvel encaissement. La date enregistrée est celle de la saisie dans MediCore.

Un reçu séquentiel `MC-R-00000001` est créé pour chaque écriture. Il conserve le montant, le mode, la référence facultative, l’agent et le solde juste après l’écriture. Les paiements ultérieurs ne réécrivent pas ce solde historique ; la facture indique le solde actuel.

L’administrateur peut annuler une écriture avec un motif obligatoire. Le paiement et son reçu restent présents, marqués annulés, et le solde est recalculé. Cela ne déclenche aucun remboursement effectif. Les éventuelles restitutions de fonds doivent être traitées séparément. Une facture avec un paiement actif ne peut pas être annulée.

Aucune route de suppression physique des factures, actes ou paiements n’est exposée.

## Doubles soumissions, concurrence et audit

La création d’une facture et l’enregistrement d’un paiement utilisent chacun une clé UUID unique. Une même clé avec la même demande renvoie l’écriture existante, sans créer une nouvelle facture, un nouveau paiement ou un second reçu. Réutiliser une clé avec une demande différente est refusé. Une reprise d’un paiement déjà annulé renvoie cet état ; elle ne crée pas un nouvel encaissement. Une nouvelle opération volontaire utilise une nouvelle clé.

Les mutations du catalogue, de la facture, du solde, des compteurs et de l’historique utilisent une transaction commune. Un verrou partagé sérialise les écritures de ce premier module pour un établissement. Il réserve aussi le verrou d’écriture SQLite avant toute lecture. Cette stratégie privilégie la cohérence et devra être évaluée sous une charge plus importante.

Le test de concurrence lance deux processus PHP indépendants sur une base temporaire : deux demandes distinctes ne peuvent pas dépasser le solde ; deux demandes avec la même clé renvoient le même reçu. Les tests de concurrence ont été exécutés sur SQLite ; les autres moteurs restent à vérifier avant leur adoption.

Chaque création, correction, émission, paiement et annulation conserve son auteur, son nom au moment de l’action, sa date, son motif lorsqu’il est requis et une copie des valeurs financières. Les historiques des factures et du catalogue sont consultables. Un échec d’écriture dans cet historique annule la transaction, y compris le paiement, le solde et l’attribution d’un numéro de reçu. Cet audit applicatif n’est pas une protection contre un opérateur pouvant modifier directement la base.

## Impression et limites

Les versions imprimables sont des pages HTML authentifiées, avec styles d’impression, sans cache. Les brouillons et factures annulées sont identifiés explicitement. Un reçu annulé reste imprimable avec son état et son motif. Les données sont échappées pour l’affichage.

Cette version ne gère pas les taxes, remises, assurances, avoirs, remboursements effectifs, factures récurrentes ou export comptable. Les mentions de l’établissement et les règles de facturation du déploiement restent à définir avant l’usage réel des documents. Les documents imprimables ne revendiquent pas de conformité fiscale particulière.

## Installation et vérifications

```bash
cd web
php artisan migrate
npm run build
php artisan test
npm run typecheck
npm run lint:check
```

Les tests couvrent les permissions, les calculs entiers et décimaux, les prix fournis par le client, les clés d’unicité, les annulations, les états, la conservation des versions, les impressions, le retour arrière transactionnel et les paiements simultanés. Les tests de concurrence nécessitent SQLite dans les processus enfants ; utiliser `MEDICORE_TEST_PHP_OPTIONS` si les extensions sont chargées via des options PHP temporaires.
