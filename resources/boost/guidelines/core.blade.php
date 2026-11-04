@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Laravel Models Organizer

- Commande locale `{{ $assist->artisanCommand('models:organize') }}` (modèle précis en argument possible) : déplace relations, scopes et accessors des modèles vers `app/Models/Concerns/{Modèle}/Relations.php`, `Scopes.php` et `Attributes.php`, puis supprime les traits de `app/Models` devenus vides ou inutilisés.
- Elle réécrit et supprime des fichiers : la lancer d’abord avec `--dry-run`, sur une copie de travail git propre ; ensuite, `{{ $assist->binCommand('pint') }}` (blancs, imports inutilisés) et relecture des messages « Trait conservé » (relations non reconnues, à déplacer à la main).
- Un trait listé dans `models-organizer.ignored_traits`, ou utilisé par une autre classe que les modèles traités, n’est jamais modifié.
- Voir le dossier `docs/` du package pour les règles de détection et la configuration.
