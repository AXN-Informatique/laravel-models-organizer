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

## Méthodes surchargées

Si une méthode est déclarée à plusieurs niveaux, seule la version finale réellement utilisée par le modèle est copiée.

Priorité :

1. méthode déclarée dans le modèle
2. méthode déclarée dans un trait directement utilisé par le modèle
3. méthode déclarée dans un trait imbriqué

Les anciennes versions surchargées sont supprimées de leurs traits sources lorsqu’elles deviennent inutiles.

## Suppression des traits vides

Lorsqu’un ancien trait ne contient plus aucune méthode après déplacement, son fichier est supprimé.

La directive `use AncienTrait;` est également retirée du modèle.

Les dossiers vides dans `app/Models` sont supprimés automatiquement en fin de traitement.

## Suppression des traits inutilisés

La commande peut également supprimer les traits présents dans `app/Models` qui ne sont plus utilisés par aucun modèle.

Attention : un trait est considéré comme inutilisé uniquement s’il n’est utilisé directement ou récursivement par aucun modèle Eloquent détecté dans `app/Models`.

## Imports

La commande conserve les imports nécessaires, y compris les alias :

```php
use Axn\Multilingual\Models\Language as BaseModel;
```

Les classes situées dans le même namespace que la méthode source sont également ajoutées automatiquement au trait généré si nécessaire.

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

* Si pour un modèle est affiché le message « Trait conservé car il contient encore des méthodes », c’est peut-être parce
que ce trait contient des relations qui n’ont pas pu être identifiées en tant que telles et qui doivent donc être déplacées
manuellement.

* Si pour un modèle est affiché un message en rouge, c’est qu’une erreur a empêché le traitement de ce modèle. Une révision
manuelle est alors nécessaire.

Une fois les corrections effectuées, exécutez la commande `composer dump` et corrigez l’erreur qui remonte. Il se peut
par exemple que deux relations portant le même nom aient été rapatriées dans le trait `Relations` d’un modèle. Effectuez
cette étape jusqu’à ce qu’il n’y ait plus d’erreur.

Optionnellement, faites une vérification avec le package `axn/laravel-models-scanner`.

Enfin, exécutez la commande `pint` pour supprimer les blancs laissés par les déplacements ainsi que les imports non utilisés.
