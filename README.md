Laravel Models Organizer
========================

Package Laravel permettant d’organiser automatiquement le code des modèles Eloquent en déplaçant les relations, scopes et attributes dans des traits dédiés (`app/Models/Concerns/{Modèle}/Relations.php`, `Scopes.php` et `Attributes.php`), puis en supprimant les traits devenus vides ou inutilisés.

Installation
------------

```bash
composer require axn/laravel-models-organizer --dev
```

La commande n’est disponible qu’en environnement local.

Utilisation
-----------

Simuler les modifications sans rien écrire :

```bash
php artisan models:organize --dry-run
```

Analyser tous les modèles, ou un modèle précis :

```bash
php artisan models:organize
php artisan models:organize "App\Models\Article"
```

Relire ensuite les messages de la commande, puis exécuter Pint pour supprimer les blancs et les imports inutilisés laissés par les déplacements.

Documentation
-------------

La documentation complète est dans [docs/](docs/) :

- [Installation](docs/installation.md)
- [Utilisation](docs/utilisation.md) : règles de détection, commentaires et attributs, traits partagés, suppression des traits, vérifications après passage
- [Configuration](docs/configuration.md) : `ignored_traits`
- [Architecture](docs/architecture.md)
