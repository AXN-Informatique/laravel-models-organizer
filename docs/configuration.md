---
title: Configuration
order: 3
---

Configuration
=============

Fichier `config/models-organizer.php`
-------------------------------------

Une seule clé de configuration est exposée :

```php
return [

    'ignored_traits' => [

    ],

];
```

`ignored_traits`
----------------

Liste des traits (noms de classe complets, FQCN) à exclure totalement de l’analyse, par exemple :

```php
'ignored_traits' => [
    \App\Models\Concerns\HasUuid::class,
],
```

Un trait listé ici, ainsi que tous les traits qu’il utilise lui-même :

- n’est pas parcouru : ses méthodes ne sont jamais déplacées vers `Relations.php`, `Scopes.php` ou `Attributes.php` ;
- n’est jamais supprimé, même s’il devient vide ou inutilisé.

Utile pour préserver des traits « métier » (ex. `HasUuid`, `Auditable`, etc.) que l’on ne veut pas voir réorganisés par la commande.

Un trait utilisé par une autre classe que les modèles traités bénéficie de la même protection sans figurer dans cette liste : voir la section « Traits partagés » de la page [Utilisation](./utilisation.md).
