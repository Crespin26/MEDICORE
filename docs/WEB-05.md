# WEB-05 — Dossier médical électronique

> Mise à jour du 29 octobre : un partage explicite et révocable peut ouvrir la lecture des éléments d’un auteur à un autre médecin pour un patient donné. Les corrections restent réservées à l’auteur. Les mentions d’accès « par auteur » ci-dessous décrivent le périmètre sans partage. Voir les [règles métier et la recette](WEB-RECETTE.md).

## Accès et parcours

Depuis une fiche patient, le médecin ouvre **Dossier médical** (`/patients/{patient}/medical-record`). Le dossier présente les informations, documents et consultations dont ce médecin est l’auteur. Les autres médecins, administrateurs, secrétaires et caissiers n’obtiennent pas d’accès aux contenus cliniques de cet auteur.

Le médecin peut :

- Ajouter un antécédent, une allergie ou un traitement déclaré.
- Consulter et corriger ses informations avec un motif obligatoire.
- Déposer des documents et les télécharger via une route authentifiée.
- Parcourir une chronologie et le journal de ses propres accès.

Les mêmes contrôles s’appliquent aux identifiants saisis directement dans les URL. Un document ou une information ne peut pas être consulté en remplaçant la référence du patient dans son URL.

Un dossier vide signifie qu’aucune information de cet auteur n’est renseignée ; cela ne permet pas de conclure à une absence d’allergies ou d’antécédents. Les traitements sont des informations déclarées, sans génération de prescription ni recommandation thérapeutique.

## Structure et corrections

Chaque information contient une catégorie, un libellé, des détails facultatifs, une date de survenue ou de début facultative et un état actif ou résolu/terminé. Le libellé est limité à 500 caractères, les détails à 10 000 et le motif de correction à 1 000. La date ne peut pas être postérieure au jour courant dans le fuseau de la clinique.

Chaque enregistrement conserve une version complète avec l’auteur, son nom au moment de l’action et la date. Une correction exige le numéro de version attendu et un motif. Si une autre requête a déjà modifié l’information, la correction est refusée et le formulaire garde les saisies locales. Copier les éléments à conserver avant de recharger ou d’annuler les modifications.

Les catégories et états peuvent être corrigés, tout en conservant leurs anciennes valeurs dans les versions. Aucune suppression d’information ou de version n’est exposée.

## Chronologie

La chronologie réunit uniquement les données accessibles au médecin connecté :

- Consultations classées par leur date clinique.
- Informations médicales classées par leur dernière modification.
- Documents classés par date de dépôt.

Il s’agit d’une vue du contenu actuel. Les anciennes versions se consultent dans chaque information ou consultation. La chronologie, les informations et les documents ont des paginations indépendantes ; un filtre permet de sélectionner une catégorie d’information.

## Documents privés

Formats : PDF, JPEG et PNG. Taille maximale : 10 Mo par fichier. Le serveur vérifie le fichier, sa taille, son extension et son type réel avec Fileinfo. Un contenu PDF portant une extension JPEG est refusé. HTML, SVG et exécutables ne sont pas acceptés.

Les documents sont chiffrés avant écriture, avec un nom aléatoire, dans `web/storage/app/medical`. Le disque dédié `medical` ne dispose d’aucune URL publique ni de route de fichiers temporaires. Il n’est pas visé par le lien symbolique du disque public. Les chemins physiques et empreintes ne sont pas envoyés au navigateur.

Chaque téléchargement passe par l’authentification, la vérification du rôle, de l’auteur et du patient, le déchiffrement et une vérification d’intégrité SHA-256. Il est renvoyé en pièce jointe, sans aperçu intégré, avec un nom généré, sans mise en cache et avec `nosniff`. Un fichier absent produit un refus et un fichier altéré un conflit d’intégrité.

En cas d’échec d’enregistrement des métadonnées ou de l’audit, le dépôt est annulé et le fichier est retiré. Aucune route de remplacement ou de suppression de document n’est ajoutée dans cet incrément.

La vérification de format ne constitue pas une analyse antivirus. Une analyse des documents et sa politique d’exploitation restent à préparer avant une utilisation avec des fichiers non maîtrisés.

## Chiffrement et sauvegardes

Les libellés, détails, motifs de correction et versions des nouvelles informations sont chiffrés en base. Les titres et noms originaux des documents sont aussi chiffrés. Les identifiants, catégories, dates, états et métadonnées d’audit restent interrogeables en clair.

Ce chiffrement utilise la clé de l’application. Sauvegarder et protéger cette clé avec les procédures d’exploitation ; ne pas la régénérer sur une installation contenant ces données. La restauration doit inclure la base, le stockage privé et la clé correspondante.

Les comptes rendus de consultation créés dans WEB-04 ne sont pas convertis au chiffrement au repos par cette migration. Le chiffrement de ces contenus existants, des autres données cliniques et des sauvegardes reste à traiter dans la préparation à la production. Aucune conformité réglementaire globale n’est revendiquée par cet incrément.

## Journal des accès

Le journal enregistre l’identifiant et le nom de l’acteur, la référence du patient, la date UTC, l’action et la référence de la ressource, avec son numéro de version lorsqu’il existe. Il ne contient pas le texte des notes, les titres des documents, leurs noms originaux ni leurs chemins.

Sont journalisés à partir de l’activation de WEB-05 :

- Lecture du dossier, des informations et de leurs versions.
- Ajout et correction des informations médicales.
- Dépôt et téléchargement réussi des documents.
- Création, modification et lecture des consultations WEB-04.
- Lecture de listes de consultations pour les patients effectivement présents dans la page, ainsi que du filtre patient lorsqu’il est utilisé.
- Lecture des résumés de consultations depuis une fiche patient.
- Lecture du journal lui-même.

L’impossibilité d’écrire dans le journal empêche la restitution du dossier ou du document. Les modifications et leur événement d’audit partagent une transaction. Les accès refusés ne sont pas inscrits dans ce journal clinique ; un dispositif distinct de suivi des tentatives refusées reste à prévoir.

Le médecin ne voit que ses propres événements. Aucun endpoint ne permet d’altérer ou de supprimer le journal. Il ne s’agit pas d’un journal inviolable face à un opérateur de base de données ; une conservation externe protégée et les durées de conservation restent à définir.

Un compte référencé dans ces données ou dans le journal ne peut pas être supprimé depuis ses paramètres. La suspension administrative reste disponible.

## Installation et vérifications

```bash
cd web
php artisan migrate
npm run build
php artisan test
npm run typecheck
npm run lint:check
```

L’extension PHP Fileinfo est nécessaire. Pour autoriser les dépôts de 10 Mo, configurer le serveur PHP avec `upload_max_filesize` au moins à `10M` et `post_max_size` au moins à `12M` ; une limite PHP inférieure reste prioritaire. Les fichiers privés sont exclus de Git. Les tests utilisent un stockage isolé et des données entièrement fictives ; ils vérifient l’isolation entre médecins, les accès directs, les versions, les fichiers refusés, le chiffrement, l’intégrité, l’audit et le nettoyage d’un dépôt échoué.
