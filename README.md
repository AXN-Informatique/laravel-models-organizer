# Laravel Models Organizer

Package Laravel permettant d’organiser automatiquement le code des modèles Eloquent en déplaçant les relations, scopes et attributes dans des traits dédiés.

## Installation

```bash
composer require axn/laravel-models-organizer
```

## Utilisation

Analyser tous les modèles :

```bash
php artisan models:organize
```

Analyser un modèle précis :

```bash
php artisan models:organize "App\Models\Article"
```

Simuler les modifications sans écrire les fichiers :

```bash
php artisan models:organize --dry-run
```

La simulation n’écrit rien sur le disque et annonce exactement ce que ferait l’exécution réelle : fichiers écrits, traits supprimés, dossiers vidés.

La commande analyse tous les modèles avant de modifier le moindre fichier. Si un fichier PHP de `app/` ne peut pas être analysé, elle s’arrête sans rien modifier.

## Objectif

La commande déplace automatiquement :

* les relations Eloquent vers `Relations.php`
* les scopes vers `Scopes.php`
* les accessors / mutators / attributes vers `Attributes.php`

Exemple :

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

Pour un modèle dans un sous-namespace :

```txt
app/
└── Models/
    ├── Catalogue/
    │   └── Article.php
    └── Concerns/
        └── Catalogue/
            └── Article/
                ├── Relations.php
                ├── Scopes.php
                └── Attributes.php
```

## Règles de détection

### Relations

Une méthode est considérée comme relation si :

* son type de retour commence par `Illuminate\Database\Eloquent\Relations\`
* ou si elle retourne explicitement une relation Eloquent via `$this->belongsTo(...)`, `$this->hasMany(...)`, etc.

### Scopes

Une méthode est considérée comme scope si :

* son nom commence par `scope`
* ou si elle possède l’attribut `#[Scope]`

### Attributes

Une méthode est considérée comme attribute si :

* son nom respecte `get*Attribute`
* son nom respecte `set*Attribute`
* ou si son type de retour est `Illuminate\Database\Eloquent\Casts\Attribute`

## Traits analysés

La commande analyse :

* les méthodes directement déclarées dans le modèle
* les méthodes déclarées dans les traits utilisés par le modèle
* les méthodes déclarées dans les traits imbriqués

Les traits vendor sont ignorés.

## Commentaires et attributs

Une méthode déplacée emporte ses attributs (`#[...]`, y compris sur plusieurs lignes) et les commentaires collés au-dessus d’elle, sans ligne vide entre eux, quelle que soit leur forme : docblock `/** */`, bloc `/* */` ou `//`.

Un commentaire séparé de la méthode par une ligne vide (un titre de section, par exemple) reste dans le fichier d’origine.

## Traits partagés

Un trait utilisé par plusieurs modèles, directement ou par un trait intermédiaire, est copié dans les traits générés de chacun d’eux, puis supprimé s’il ne contient plus rien.

Un trait utilisé aussi par une autre classe que les modèles traités (une classe de `app/` qui n’est pas un modèle, ou un autre modèle quand la commande vise un modèle précis) n’est ni parcouru ni modifié, pas plus que les traits qu’il utilise. La commande le signale par le message « Trait non traité car utilisé hors des modèles traités ».

## Méthodes surchargées

Si une méthode est déclarée à plusieurs niveaux, seule la version finale réellement utilisée par le modèle est copiée.

Priorité :

1. méthode déclarée dans le modèle
2. méthode déclarée dans un trait directement utilisé par le modèle
3. méthode déclarée dans un trait imbriqué

Les anciennes versions surchargées sont supprimées de leurs traits sources lorsqu’elles deviennent inutiles.

## Suppression des traits vides

Lorsqu’un ancien trait ne contient plus ni méthode, ni propriété, ni constante après déplacement, et que les traits qu’il utilise sont eux-mêmes supprimés, son fichier est supprimé.

La directive `use AncienTrait;` est également retirée du modèle et des traits conservés qui l’utilisaient, sous quelque nom qu’ils l’emploient (alias compris).

Les dossiers vides dans `app/Models` sont supprimés automatiquement en fin de traitement.

## Suppression des traits inutilisés

La commande supprime également les traits présents dans `app/Models` que plus rien n’utilise.

Un trait est considéré comme inutilisé s’il n’est utilisé, directement ou par un autre trait, par aucune classe ni aucun enum de `app/`, qu’il s’agisse d’un modèle ou non. La détection lit les fichiers de `app/` sans les charger. Un trait listé dans `ignored_traits`, et les traits qu’il utilise, ne sont jamais supprimés.

## Imports

La commande conserve les imports nécessaires, y compris les alias :

```php
use Axn\Multilingual\Models\Language as BaseModel;
```

Les classes situées dans le même namespace que la méthode source sont également ajoutées automatiquement au trait généré si nécessaire.

## Configuration

La configuration peut être publiée via `vendor:publish` (uniquement disponible en environnement local) :

```bash
php artisan vendor:publish --tag=models-organizer-config
```

Cela crée le fichier `config/models-organizer.php` dans l'application cible.

### `ignored_traits`

Liste des traits (noms de classe complets, FQCN) à exclure totalement de l'analyse, par exemple :

```php
'ignored_traits' => [
    \App\Models\Concerns\HasUuid::class,
],
```

Un trait listé ici, ainsi que tous les traits qu'il utilise lui-même, ne sera pas parcouru : ses méthodes ne seront donc jamais déplacées vers `Relations.php`, `Scopes.php` ou `Attributes.php`, et il ne sera jamais supprimé même s'il devient vide ou inutilisé.

Utile pour préserver des traits « métier » (ex. `HasUuid`, `Auditable`, etc.) que l'on ne veut pas voir réorganisés par la commande.


## Exemple

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

## Sécurité

Une fois la commande exécutée, vérifier les messages d’informations de la commande :

* Si pour un modèle est affiché le message « Trait conservé car il contient encore… », suivi des méthodes, propriétés,
constantes ou traits restants, c’est peut-être parce que ce trait contient des relations qui n’ont pas pu être identifiées
en tant que telles (par exemple une relation dérivée sans type de retour, `return $this->conditionnements()->where(...)`)
et qui doivent donc être déplacées manuellement.

* Si pour un modèle est affiché un message en rouge, c’est qu’une erreur a empêché le traitement de ce modèle. Ce modèle et
ses traits restent intacts, même quand d’autres modèles partagent ces traits. Une révision manuelle est alors nécessaire.

Une fois les corrections effectuées, exécutez la commande `composer dump` et corrigez l’erreur qui remonte. Il se peut
par exemple que deux relations portant le même nom aient été rapatriées dans le trait `Relations` d’un modèle. Effectuez
cette étape jusqu’à ce qu’il n’y ait plus d’erreur.

Optionnellement, faites une vérification avec le package `axn/laravel-models-scanner`.

Enfin, exécutez la commande `pint` pour supprimer les blancs laissés par les déplacements ainsi que les imports non utilisés.
