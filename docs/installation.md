---
title: Installation
order: 1
---

Installation
============

Prérequis
---------

- PHP 8.4+
- Laravel 12+ ou 13+
- nikic/php-parser 5.7+ (installé avec le package)

Installation via Composer
-------------------------

Le package ne sert qu’en développement :

```bash
composer require axn/laravel-models-organizer --dev
```

Le `ServiceProvider` est auto-découvert grâce à la configuration `extra.laravel.providers` du `composer.json`. La commande `models:organize` n’est enregistrée qu’en environnement local, en console.

Publication de la configuration (optionnel)
-------------------------------------------

La publication n’est disponible qu’en environnement local :

```bash
php artisan vendor:publish --tag=models-organizer-config
```

Cela crée le fichier `config/models-organizer.php`, décrit dans [Configuration](./configuration.md).
