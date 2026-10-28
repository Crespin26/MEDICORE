# WEB-04 — Consultations

## Périmètre et accès

Les consultations contiennent un motif, des observations, des constantes facultatives et un compte rendu. Chaque consultation est associée à un patient et au médecin connecté lors de sa création. Ces associations ne sont pas modifiables ensuite.

| Action | Médecin auteur | Autre médecin | Administrateur, secrétaire, caissier |
| --- | --- | --- | --- |
| Créer une consultation pour un patient | Oui | Peut créer sa propre consultation | Non |
| Lire le compte rendu et ses versions | Oui | Non | Non |
| Modifier un brouillon et le valider | Oui | Non | Non |
| Corriger un compte rendu validé | Oui, avec motif | Non | Non |

Ce choix limite l’accès clinique au médecin auteur. La collaboration entre praticiens et un éventuel accès clinique délégué restent à définir. Le rôle administrateur n’accorde pas automatiquement de droit de lecture sur les notes médicales.

Les contrôles portent sur les routes, les règles de formulaire et la consultation visée. Les données cliniques ne sont pas ajoutées à la fiche patient transmise aux rôles administratifs. L’historique visible depuis la fiche patient est limité aux consultations du médecin connecté.

## Parcours

1. Se connecter avec un compte médecin actif et ouvrir une fiche patient.
2. Choisir **Nouvelle consultation**, ou ouvrir la consultation depuis un rendez-vous arrivé ou terminé de son agenda.
3. Saisir la date locale, le motif et les informations disponibles.
4. Enregistrer un brouillon ou valider le compte rendu. Le compte rendu est obligatoire pour la validation.
5. Depuis **Mes consultations** (`/consultations`), filtrer par statut et ouvrir la consultation.
6. Sur une consultation validée, choisir **Apporter une correction**, saisir un motif, puis enregistrer.

La création depuis un rendez-vous est facultative. Le rendez-vous doit appartenir au même patient et au médecin connecté, avoir commencé et être arrivé ou terminé. Un rendez-vous ne peut être lié qu’à une seule consultation. Le lien ne peut pas être remplacé ensuite ; ouvrir à nouveau la consultation depuis l’agenda mène à celle qui existe déjà.

La consultation ne modifie pas automatiquement le statut du rendez-vous. Une annulation administrative ultérieure du rendez-vous n’efface pas la consultation.

## Données saisies

- Motif : obligatoire, 500 caractères maximum.
- Observations : facultatives, 20 000 caractères maximum.
- Compte rendu : facultatif en brouillon, obligatoire à la validation, 30 000 caractères maximum.
- Constantes facultatives : température en °C, pouls en battements/min, pression systolique et diastolique en mmHg, saturation en oxygène en %, poids en kg et taille en cm.
- Motif de correction après validation : obligatoire, 1 000 caractères maximum.

Les constantes font l’objet d’une validation de format et de limites techniques ; aucune interprétation, alerte clinique ou recommandation médicale n’est générée. Les unités apparaissent dans le formulaire et dans les versions historiques.

La date de consultation doit être comprise entre la naissance du patient et l’heure actuelle. La saisie utilise `CLINIC_TIMEZONE`, comme les rendez-vous, puis est convertie en UTC pour le stockage. Les heures ambiguës ou inexistantes sont refusées.

## États et historique

Une consultation commence en brouillon ou peut être validée dès sa création. La validation initiale est horodatée. Il n’est pas possible de revenir de validé à brouillon.

Chaque enregistrement ajoute une version avec :

- Le numéro de version, l’identifiant et le nom de l’auteur à la date de l’action.
- La date UTC de l’enregistrement et la nature de l’action.
- Une copie complète du contenu, du statut et des associations.
- Le motif lorsqu’il s’agit d’une correction après validation.

La correction conserve la date de validation initiale et les anciennes versions. Le nom historique de l’auteur reste conservé même s’il modifie son profil ensuite. Les versions sont consultables par pages de dix. Aucun endpoint ne permet de modifier ou supprimer une version ni de supprimer une consultation.

L’enregistrement du contenu et de sa version historique se fait dans la même transaction. Une version attendue est transmise par le formulaire et vérifiée sous verrou : si un autre onglet a déjà enregistré, la modification est refusée. Le formulaire ouvert conserve alors les saisies locales ; copier les éléments à conserver avant de recharger ou d’annuler les modifications pour consulter la version récente.

Cette traçabilité est assurée par l’application ; elle ne constitue pas une signature électronique ni une protection contre les modifications directes par un opérateur de base de données. Les lectures et modifications sont désormais journalisées dans WEB-05 ; consulter [son guide](WEB-05.md) pour le périmètre et les protections du dossier médical.

## Confidentialité et comptes

Les champs motif, observations, compte rendu, constantes et justification de correction ne sont pas recopiés dans les anciennes saisies de session lors d’une erreur de validation. Le formulaire conserve les saisies en mémoire pendant la correction. Les réponses web conservent les en-têtes interdisant leur mise en cache.

Un compte possédant des consultations ne peut pas être supprimé depuis les paramètres ; la suspension administrative reste possible. Les versions conservent ainsi leur auteur.

## Installation et vérifications

```bash
cd web
php artisan migrate
npm run build
php artisan test
npm run typecheck
npm run lint:check
```

La migration ajoute les consultations et leurs versions, sans modifier les dossiers patients existants. Les pièces jointes, antécédents, prescriptions et exports ne sont pas ajoutés dans cet incrément.
