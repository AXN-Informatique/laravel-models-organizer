---
title: Models Organizer
order: 0
---

Models Organizer
================

Commande Artisan locale qui range le code des modèles Eloquent : elle déplace les relations, les scopes et les accessors dans des traits dédiés, puis supprime les traits devenus vides ou inutilisés.

Fonctionnalités
---------------

- Relations vers `Relations.php`, scopes vers `Scopes.php`, accessors, mutators et attributes vers `Attributes.php`, dans `app/Models/Concerns/{Modèle}/`
- Méthodes lues dans le modèle, dans ses traits et dans les traits imbriqués
- Commentaires et attributs déplacés avec leur méthode
- Traits partagés copiés dans chacun des modèles qui les utilisent
- Suppression des traits vides ou que plus rien n’utilise
- Simulation (`--dry-run`) qui n’écrit rien

Prérequis
---------

- PHP 8.4+
- Laravel 12+ ou 13+

Installation rapide
-------------------

```bash
composer require axn/laravel-models-organizer --dev
php artisan models:organize --dry-run
```

Sommaire
--------

- [Installation](./installation.md)
- [Utilisation](./utilisation.md)
- [Configuration](./configuration.md)
- [Architecture](./architecture.md)
