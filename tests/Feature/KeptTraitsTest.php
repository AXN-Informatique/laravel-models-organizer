<?php

declare(strict_types=1);

use Axn\ModelsOrganizer\Tests\Support\TemporaryApplication;

it('nomme les méthodes restées dans un trait conservé', function (): void {
    $app = TemporaryApplication::create([
        'Models/Rayon.php' => emptyModel('Rayon'),
        'Models/Magasin.php' => modelUsingTrait('Magasin', 'App\Models\Concerns\Magasin\HasRayons'),
        'Models/Concerns/Magasin/HasRayons.php' => <<<'PHP'
            <?php

            namespace App\Models\Concerns\Magasin;

            use App\Models\Rayon;
            use Illuminate\Database\Eloquent\Relations\HasMany;

            trait HasRayons
            {
                public function rayons(): HasMany
                {
                    return $this->hasMany(Rayon::class);
                }

                public function rayonsOuverts()
                {
                    return $this->rayons()->where('ouvert', true);
                }
            }
            PHP,
    ]);

    $output = $app->organize();

    expect($output)->toContain('Trait conservé', 'HasRayons.php', 'rayonsOuverts');
});

it('conserve un trait sans méthode qui porte encore une propriété ou un trait', function (): void {
    $app = TemporaryApplication::create([
        'Models/Categorie.php' => emptyModel('Categorie'),
        'Models/Fiche.php' => modelUsingTrait('Fiche', 'App\Models\Concerns\Fiche\HasReferencement'),
        'Models/Concerns/Fiche/HasReferencement.php' => <<<'PHP'
            <?php

            namespace App\Models\Concerns\Fiche;

            use App\Models\Categorie;
            use Illuminate\Database\Eloquent\Relations\BelongsTo;
            use Illuminate\Database\Eloquent\SoftDeletes;

            trait HasReferencement
            {
                use SoftDeletes;

                public $champsReferencement = ['titre', 'description'];

                public function categorie(): BelongsTo
                {
                    return $this->belongsTo(Categorie::class);
                }
            }
            PHP,
    ]);

    $before = $app->methods(['App\Models\Fiche']);

    $app->organize();

    expect($app->lintErrors())->toBe([])
        ->and($app->methods(['App\Models\Fiche']))->toBe($before)
        ->and($app->contents('Models/Concerns/Fiche/HasReferencement.php'))
        ->toContain('use SoftDeletes;', '$champsReferencement')
        ->not->toContain('function categorie()')
        ->and($app->contents('Models/Fiche.php'))->toContain('    use HasReferencement;');
});

it('supprime en cascade un trait qui ne faisait qu\'en inclure un autre', function (): void {
    $app = TemporaryApplication::create([
        'Models/Vignoble.php' => emptyModel('Vignoble'),
        'Models/Cave.php' => modelUsingTrait('Cave', 'App\Models\Concerns\Relations\CaveRelations'),
        'Models/Concerns/Relations/CaveRelations.php' => <<<'PHP'
            <?php

            namespace App\Models\Concerns\Relations;

            use App\Models\Concerns\GeneratedRelations\CaveRelations as CaveGeneratedRelations;

            trait CaveRelations
            {
                use CaveGeneratedRelations;
            }
            PHP,
        'Models/Concerns/GeneratedRelations/CaveRelations.php' => <<<'PHP'
            <?php

            namespace App\Models\Concerns\GeneratedRelations;

            use App\Models\Vignoble;
            use Illuminate\Database\Eloquent\Relations\BelongsTo;

            trait CaveRelations
            {
                public function vignoble(): BelongsTo
                {
                    return $this->belongsTo(Vignoble::class);
                }
            }
            PHP,
    ]);

    $before = $app->methods(['App\Models\Cave']);

    $app->organize();

    expect($app->lintErrors())->toBe([])
        ->and($app->methods(['App\Models\Cave']))->toBe($before)
        ->and($app->exists('Models/Concerns/Relations/CaveRelations.php'))->toBeFalse()
        ->and($app->exists('Models/Concerns/GeneratedRelations/CaveRelations.php'))->toBeFalse()
        ->and($app->contents('Models/Cave.php'))->not->toContain('    use CaveRelations;');
});
