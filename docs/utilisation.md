---
title: Utilisation
order: 2
---

Utilisation
===========

Lancer la commande
------------------

| Commande | Effet |
|---|---|
| `php artisan models:organize` | traite tous les modèles de `app/Models` |
| `php artisan models:organize "App\Models\Article"` | traite un seul modèle |
| `php artisan models:organize --dry-run` | simule, sans rien écrire sur le disque |

La simulation annonce exactement ce que ferait l’exécution réelle : fichiers écrits, traits supprimés, dossiers vidés. La lancer d’abord, et travailler sur une copie de travail propre (git) pour pouvoir relire le résultat.

La commande analyse tous les modèles avant de modifier le moindre fichier. Si un fichier PHP de `app/` ne peut pas être analysé, elle s’arrête sans rien modifier.

Résultat
--------

La commande déplace :

- les relations Eloquent vers `Relations.php` ;
- les scopes vers `Scopes.php` ;
- les accessors, mutators et attributes vers `Attributes.php`.

```txt
app/
└── Models/
    ├── Article.php
    └── Concerns/
        └── Article/
            ├── Relations.php
            ├── Scopes.php
            └── Attributes.php
```

Pour un modèle dans un sous-namespace (`App\Models\Catalogue\Article`), les traits vont dans `app/Models/Concerns/Catalogue/Article/`.

Avant :

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Article extends Model
{
    public function rubrique(): BelongsTo
    {
        return $this->belongsTo(Rubrique::class);
    }
}
```

Après :

```php
namespace App\Models;

use App\Models\Concerns\Article\Relations;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    use Relations;
}
```

```php
namespace App\Models\Concerns\Article;

use App\Models\Rubrique;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait Relations
{
    public function rubrique(): BelongsTo
    {
        return $this->belongsTo(Rubrique::class);
    }
}
```

Règles de détection
-------------------

| Type | La méthode est retenue si |
|---|---|
| Relation | elle est publique, et son type de retour commence par `Illuminate\Database\Eloquent\Relations\`, ou, sans type de retour, elle retourne `$this->belongsTo(...)`, `$this->hasMany(...)`, etc. |
| Scope | son nom commence par `scope`, ou elle porte l’attribut `#[Scope]` |
| Attribute | son nom respecte `get*Attribute` ou `set*Attribute`, ou son type de retour est `Illuminate\Database\Eloquent\Casts\Attribute` |

Une relation dérivée sans type de retour (`return $this->conditionnements()->where(...)`) n’est pas reconnue : elle reste dans son fichier d’origine.

Méthodes et traits analysés
---------------------------

La commande lit les méthodes déclarées dans le modèle, dans les traits qu’il utilise et dans les traits imbriqués. Les traits vendor sont ignorés.

Si une méthode est déclarée à plusieurs niveaux, seule la version réellement utilisée par le modèle est copiée, par ordre de priorité :

1. méthode déclarée dans le modèle ;
2. méthode déclarée dans un trait directement utilisé par le modèle ;
3. méthode déclarée dans un trait imbriqué.

Les versions surchargées sont supprimées de leurs traits sources.

Commentaires et attributs
-------------------------

Une méthode déplacée emporte ses attributs (`#[...]`, y compris sur plusieurs lignes) et les commentaires collés au-dessus d’elle, sans ligne vide entre eux, quelle que soit leur forme : docblock `/** */`, bloc `/* */` ou `//`.

Un commentaire séparé de la méthode par une ligne vide (un titre de section, par exemple) reste dans le fichier d’origine.

Traits partagés
---------------

Un trait utilisé par plusieurs modèles, directement ou par un trait intermédiaire, est copié dans les traits générés de chacun d’eux, puis supprimé s’il ne contient plus rien.

Un trait utilisé aussi par une autre classe que les modèles traités n’est ni parcouru ni modifié, pas plus que les traits qu’il utilise. C’est le cas d’un trait utilisé par une classe de `app/` qui n’est pas un modèle, ou par un autre modèle quand la commande vise un modèle précis. La commande le signale par le message « Trait non traité car utilisé hors des modèles traités ».

Suppression des traits
----------------------

Un ancien trait est supprimé lorsqu’il ne contient plus ni méthode, ni propriété, ni constante après déplacement, et que les traits qu’il utilise sont eux-mêmes supprimés. La directive `use AncienTrait;` est retirée du modèle et des traits conservés qui l’utilisaient, sous quelque nom qu’ils l’emploient (alias compris).

La commande supprime aussi les traits de `app/Models` que plus rien n’utilise : aucune classe ni aucun enum de `app/` ne les utilise, directement ou par un autre trait. La détection lit les fichiers de `app/` sans les charger. Un trait listé dans `ignored_traits`, et les traits qu’il utilise, ne sont jamais supprimés.

Les dossiers vidés dans `app/Models` sont supprimés en fin de traitement.

Imports
-------

Les traits générés reprennent les imports nécessaires, alias compris :

```php
use Axn\Multilingual\Models\Language as BaseModel;
```

Les classes situées dans le même namespace que la méthode source sont également importées dans le trait généré si nécessaire.

Après le passage de la commande
-------------------------------

Relire les messages de la commande :

- « Trait conservé car il contient encore… », suivi des méthodes, propriétés, constantes ou traits restants : ce trait contient peut-être des relations qui n’ont pas pu être identifiées en tant que telles, à déplacer à la main.
- Un message en rouge : une erreur a empêché le traitement de ce modèle. Ce modèle et ses traits restent intacts, même quand d’autres modèles partagent ces traits ; une révision manuelle est nécessaire.

Exécuter ensuite `composer dump` et corriger l’erreur qui remonte, par exemple deux relations du même nom rapatriées dans le trait `Relations` d’un modèle. Recommencer jusqu’à ce qu’il n’y ait plus d’erreur.

Optionnellement, vérifier les relations avec le package `axn/laravel-models-scanner`.

Enfin, exécuter Pint pour supprimer les blancs laissés par les déplacements et les imports inutilisés :

```bash
vendor/bin/pint app/Models
```
