<?php

declare(strict_types=1);

use Axn\ModelsOrganizer\Tests\Support\TemporaryApplication;

/**
 * Application d'un modèle $model dont le corps est $body, et d'un modèle
 * $related vers lequel pointent ses relations.
 */
function applicationWithModel(string $model, string $related, string $body): TemporaryApplication
{
    return TemporaryApplication::create([
        "Models/{$related}.php" => <<<PHP
            <?php

            namespace App\\Models;

            use Illuminate\\Database\\Eloquent\\Model;

            class {$related} extends Model
            {
            }
            PHP,
        "Models/{$model}.php" => <<<PHP
            <?php

            namespace App\\Models;

            use Illuminate\\Database\\Eloquent\\Model;
            use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

            class {$model} extends Model
            {
            {$body}
            }
            PHP,
    ]);
}

it('déplace une méthode avec tout son commentaire /* */', function (): void {
    $app = applicationWithModel('Vin', 'Appellation', <<<'PHP'
            /*
             * Relation permettant de retrouver l'appellation
             */
            public function appellation()
            {
                return $this->belongsTo(Appellation::class);
            }
        PHP);

    $app->organize();

    expect($app->lintErrors())->toBe([])
        ->and($app->contents('Models/Concerns/Vin/Relations.php'))
        ->toContain("    /*\n     * Relation permettant de retrouver l'appellation\n     */\n    public function appellation()")
        ->and($app->contents('Models/Vin.php'))->not->toContain('/*', 'Relation permettant');
});

it('déplace un commentaire /* */ dont les lignes intérieures ne commencent pas par une étoile', function (): void {
    $app = applicationWithModel('Cepage', 'Region', <<<'PHP'
            /*
               Ancienne relation, conservée pour l'import
            */
            public function region(): BelongsTo
            {
                return $this->belongsTo(Region::class);
            }
        PHP);

    $app->organize();

    expect($app->lintErrors())->toBe([])
        ->and($app->contents('Models/Concerns/Cepage/Relations.php'))
        ->toContain("    /*\n       Ancienne relation, conservée pour l'import\n    */\n    public function region(): BelongsTo")
        ->and($app->contents('Models/Cepage.php'))->not->toContain('/*', 'Ancienne relation');
});

it('déplace un commentaire // collé à la méthode, pas celui qu\'une ligne vide en sépare', function (): void {
    $app = applicationWithModel('Millesime', 'Domaine', <<<'PHP'
            // Relations

            // Domaine qui produit ce millésime
            public function domaine(): BelongsTo
            {
                return $this->belongsTo(Domaine::class);
            }
        PHP);

    $app->organize();

    expect($app->lintErrors())->toBe([])
        ->and($app->contents('Models/Concerns/Millesime/Relations.php'))
        ->toContain("    // Domaine qui produit ce millésime\n    public function domaine(): BelongsTo")
        ->not->toContain('// Relations')
        ->and($app->contents('Models/Millesime.php'))
        ->toContain('// Relations')
        ->not->toContain('Domaine qui produit');
});

it('déplace un attribut écrit sur plusieurs lignes', function (): void {
    $app = applicationWithModel('Etiquette', 'Producteur', <<<'PHP'
            #[\Deprecated(
                message: 'Utiliser domaine()',
            )]
            public function producteur(): BelongsTo
            {
                return $this->belongsTo(Producteur::class);
            }
        PHP);

    $app->organize();

    expect($app->lintErrors())->toBe([])
        ->and($app->contents('Models/Concerns/Etiquette/Relations.php'))
        ->toContain("    #[\\Deprecated(\n        message: 'Utiliser domaine()',\n    )]\n    public function producteur(): BelongsTo")
        ->and($app->contents('Models/Etiquette.php'))->not->toContain('Deprecated', 'Utiliser domaine()');
});

it('laisse à sa place un commentaire écrit en fin de ligne de la méthode précédente', function (): void {
    $app = applicationWithModel('Bouteille', 'Cellier', <<<'PHP'
            public function contenance(): string
            {
                return '75 cl';
            } // fin de contenance
            public function cellier(): BelongsTo
            {
                return $this->belongsTo(Cellier::class);
            }
        PHP);

    $app->organize();

    expect($app->lintErrors())->toBe([])
        ->and($app->contents('Models/Bouteille.php'))->toContain("return '75 cl';\n    } // fin de contenance")
        ->and($app->contents('Models/Concerns/Bouteille/Relations.php'))->not->toContain('fin de contenance');
});
