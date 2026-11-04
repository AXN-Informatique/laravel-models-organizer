<?php

declare(strict_types=1);

use Axn\ModelsOrganizer\Tests\Support\TemporaryApplication;

it('donne à chaque modèle les méthodes d\'un trait qu\'il partage avec un autre', function (): void {
    $app = TemporaryApplication::create([
        'Models/Pays.php' => emptyModel('Pays'),
        'Models/Ville.php' => emptyModel('Ville'),
        'Models/Fournisseur.php' => modelUsingTrait('Fournisseur', 'App\Models\Concerns\Partage\HasAdresse'),
        'Models/Entrepot.php' => modelUsingTrait('Entrepot', 'App\Models\Concerns\Partage\HasAdresse'),
        'Models/Concerns/Partage/HasAdresse.php' => <<<'PHP'
            <?php

            namespace App\Models\Concerns\Partage;

            use App\Models\Pays;
            use App\Models\Ville;
            use Illuminate\Database\Eloquent\Relations\BelongsTo;

            trait HasAdresse
            {
                public function pays(): BelongsTo
                {
                    return $this->belongsTo(Pays::class);
                }

                public function ville(): BelongsTo
                {
                    return $this->belongsTo(Ville::class);
                }
            }
            PHP,
    ]);

    $models = ['App\Models\Entrepot', 'App\Models\Fournisseur'];
    $before = $app->methods($models);

    $app->organize();

    expect($app->lintErrors())->toBe([])
        ->and($app->methods($models))->toBe($before)
        ->and($app->exists('Models/Concerns/Partage/HasAdresse.php'))->toBeFalse()
        ->and($app->contents('Models/Concerns/Fournisseur/Relations.php'))->toContain('function pays()', 'function ville()')
        ->and($app->contents('Models/Concerns/Entrepot/Relations.php'))->toContain('function pays()', 'function ville()');
});

it('donne à chaque modèle les méthodes d\'un trait partagé par des traits intermédiaires', function (): void {
    $app = TemporaryApplication::create([
        'Models/Couleur.php' => emptyModel('Couleur'),
        'Models/Conditionnement.php' => emptyModel('Conditionnement'),
        'Models/Produit.php' => modelUsingTrait('Produit', 'App\Models\Concerns\Relations\ProduitRelations'),
        'Models/ViewProduit.php' => modelUsingTrait('ViewProduit', 'App\Models\Concerns\Relations\ViewProduitRelations'),
        'Models/Concerns/GeneratedRelations/ProduitRelations.php' => <<<'PHP'
            <?php

            namespace App\Models\Concerns\GeneratedRelations;

            use App\Models\Conditionnement;
            use App\Models\Couleur;
            use Illuminate\Database\Eloquent\Relations\BelongsTo;
            use Illuminate\Database\Eloquent\Relations\HasMany;

            trait ProduitRelations
            {
                public function couleur(): BelongsTo
                {
                    return $this->belongsTo(Couleur::class);
                }

                public function conditionnements(): HasMany
                {
                    return $this->hasMany(Conditionnement::class);
                }
            }
            PHP,
        'Models/Concerns/Relations/ProduitRelations.php' => <<<'PHP'
            <?php

            namespace App\Models\Concerns\Relations;

            use App\Models\Concerns\GeneratedRelations\ProduitRelations as ProduitGeneratedRelations;

            trait ProduitRelations
            {
                use ProduitGeneratedRelations;

                public function conditionnementsDisponibles()
                {
                    return $this->conditionnements()->where('stock', '>', 0);
                }
            }
            PHP,
        'Models/Concerns/Relations/ViewProduitRelations.php' => <<<'PHP'
            <?php

            namespace App\Models\Concerns\Relations;

            use App\Models\Concerns\GeneratedRelations\ProduitRelations as ProduitGeneratedRelations;
            use App\Models\Produit;

            trait ViewProduitRelations
            {
                use ProduitGeneratedRelations;

                public function conditionnementsDisponibles()
                {
                    return $this->conditionnements()->where('stock', '>', 0);
                }

                public function produitOriginal()
                {
                    return $this->belongsTo(Produit::class, 'id');
                }
            }
            PHP,
    ]);

    $models = ['App\Models\Produit', 'App\Models\ViewProduit'];
    $before = $app->methods($models);

    $app->organize();

    expect($app->lintErrors())->toBe([])
        ->and($app->methods($models))->toBe($before)
        ->and($app->exists('Models/Concerns/GeneratedRelations/ProduitRelations.php'))->toBeFalse()
        ->and($app->contents('Models/Concerns/ViewProduit/Relations.php'))
        ->toContain('function couleur()', 'function conditionnements()', 'function produitOriginal()')
        ->and($app->contents('Models/Concerns/Relations/ViewProduitRelations.php'))
        ->toContain('function conditionnementsDisponibles()')
        ->not->toContain('use ProduitGeneratedRelations;');
});

it('laisse intact, pour un modèle précis, un trait qu\'il partage avec un autre modèle', function (): void {
    $trait = <<<'PHP'
        <?php

        namespace App\Models\Concerns\Partage;

        use App\Models\Transporteur;
        use Illuminate\Database\Eloquent\Relations\BelongsTo;

        trait HasTransporteur
        {
            public function transporteur(): BelongsTo
            {
                return $this->belongsTo(Transporteur::class);
            }
        }
        PHP;

    $app = TemporaryApplication::create([
        'Models/Transporteur.php' => emptyModel('Transporteur'),
        'Models/Livraison.php' => modelUsingTrait('Livraison', 'App\Models\Concerns\Partage\HasTransporteur'),
        'Models/Retour.php' => modelUsingTrait('Retour', 'App\Models\Concerns\Partage\HasTransporteur'),
        'Models/Concerns/Partage/HasTransporteur.php' => $trait,
    ]);

    $output = $app->organize(['model' => 'App\Models\Livraison']);

    expect($app->contents('Models/Concerns/Partage/HasTransporteur.php'))->toBe($trait)
        ->and($output)->toContain('Trait non traité car utilisé hors des modèles traités', 'HasTransporteur.php')
        ->and($app->methods(['App\Models\Livraison', 'App\Models\Retour']))->toBe([
            'App\Models\Livraison' => ['transporteur'],
            'App\Models\Retour' => ['transporteur'],
        ]);
});

it('copie le code exact d\'un trait partagé qui reste en place', function (): void {
    $app = TemporaryApplication::create([
        'Models/Contact.php' => emptyModel('Contact'),
        'Models/Societe.php' => modelUsingTrait('Societe', 'App\Models\Concerns\Partage\HasContacts'),
        'Models/Agence.php' => modelUsingTrait('Agence', 'App\Models\Concerns\Partage\HasContacts'),
        'Models/Concerns/Partage/HasContacts.php' => <<<'PHP'
            <?php

            namespace App\Models\Concerns\Partage;

            use App\Models\Contact;
            use Illuminate\Database\Eloquent\Relations\BelongsTo;
            use Illuminate\Database\Eloquent\Relations\HasMany;

            trait HasContacts
            {
                public function contactPrincipal(): BelongsTo
                {
                    return $this->belongsTo(Contact::class);
                }

                public function nomDuContactPrincipal(): string
                {
                    return $this->contactPrincipal->nom;
                }

                public function contacts(): HasMany
                {
                    return $this->hasMany(Contact::class);
                }
            }
            PHP,
    ]);

    $models = ['App\Models\Agence', 'App\Models\Societe'];
    $before = $app->methods($models);

    $app->organize();

    $relations = <<<'PHP'
            public function contactPrincipal(): BelongsTo
            {
                return $this->belongsTo(Contact::class);
            }

            public function contacts(): HasMany
            {
                return $this->hasMany(Contact::class);
            }
        PHP;

    expect($app->lintErrors())->toBe([])
        ->and($app->methods($models))->toBe($before)
        ->and($app->contents('Models/Concerns/Societe/Relations.php'))->toContain($relations)
        ->and($app->contents('Models/Concerns/Agence/Relations.php'))->toContain($relations)
        ->and($app->contents('Models/Concerns/Partage/HasContacts.php'))
        ->toContain('function nomDuContactPrincipal()')
        ->not->toContain('function contactPrincipal()', 'function contacts()');
});
