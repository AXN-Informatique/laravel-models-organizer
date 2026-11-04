<?php

declare(strict_types=1);

use Axn\ModelsOrganizer\Tests\Support\TemporaryApplication;

it('conserve un trait de app/Models utilisé par une classe qui n\'est pas un modèle', function (): void {
    $app = TemporaryApplication::create([
        'Models/Panier.php' => <<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;

            class Panier extends Model
            {
            }
            PHP,
        'Models/Concerns/Client/HasDoubleFacteur.php' => <<<'PHP'
            <?php

            namespace App\Models\Concerns\Client;

            trait HasDoubleFacteur
            {
                public function codeDoubleFacteur(): string
                {
                    return '123456';
                }
            }
            PHP,
        'Data/Client.php' => <<<'PHP'
            <?php

            namespace App\Data;

            use App\Models\Concerns\Client\HasDoubleFacteur;
            use Illuminate\Auth\GenericUser;

            class Client extends GenericUser
            {
                use HasDoubleFacteur;
            }
            PHP,
    ]);

    $output = $app->organize();

    expect($app->exists('Models/Concerns/Client/HasDoubleFacteur.php'))->toBeTrue()
        ->and($output)->not->toContain('HasDoubleFacteur')
        ->and($app->methods(['App\Data\Client']))->toBe(['App\Data\Client' => ['codeDoubleFacteur']]);
});

it('ne supprime jamais un trait de ignored_traits, ni les traits qu\'il utilise', function (): void {
    $app = TemporaryApplication::create([
        'Models/Archive.php' => <<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;

            class Archive extends Model
            {
            }
            PHP,
        'Models/Concerns/Historique/HasCodesHistoriques.php' => <<<'PHP'
            <?php

            namespace App\Models\Concerns\Historique;

            trait HasCodesHistoriques
            {
                use HasFormatHistorique;
            }
            PHP,
        'Models/Concerns/Historique/HasFormatHistorique.php' => <<<'PHP'
            <?php

            namespace App\Models\Concerns\Historique;

            trait HasFormatHistorique
            {
                public function formatHistorique(): string
                {
                    return 'Y-m-d';
                }
            }
            PHP,
    ]);

    config(['models-organizer.ignored_traits' => ['App\Models\Concerns\Historique\HasCodesHistoriques']]);

    $output = $app->organize();

    expect($app->exists('Models/Concerns/Historique/HasCodesHistoriques.php'))->toBeTrue()
        ->and($app->exists('Models/Concerns/Historique/HasFormatHistorique.php'))->toBeTrue()
        ->and($output)->not->toContain('Historique');
});

it('supprime un trait que rien n\'utilise', function (): void {
    $app = TemporaryApplication::create([
        'Models/Journal.php' => <<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;

            class Journal extends Model
            {
            }
            PHP,
        'Models/Concerns/Orphelin/HasOrphelin.php' => <<<'PHP'
            <?php

            namespace App\Models\Concerns\Orphelin;

            trait HasOrphelin
            {
                public function orphelin(): bool
                {
                    return true;
                }
            }
            PHP,
    ]);

    $output = $app->organize();

    expect($app->exists('Models/Concerns/Orphelin/HasOrphelin.php'))->toBeFalse()
        ->and($output)->toContain('Trait inutilisé supprimé', 'HasOrphelin.php');
});

it('laisse intact un trait partagé entre un modèle et une autre classe', function (): void {
    $trait = <<<'PHP'
        <?php

        namespace App\Models\Concerns\Facture;

        use App\Models\Devise;
        use Illuminate\Database\Eloquent\Relations\BelongsTo;

        trait HasMontants
        {
            public function devise(): BelongsTo
            {
                return $this->belongsTo(Devise::class);
            }

            public function montantTtc(): float
            {
                return $this->montant_ht * 1.2;
            }
        }
        PHP;

    $app = TemporaryApplication::create([
        'Models/Devise.php' => <<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;

            class Devise extends Model
            {
            }
            PHP,
        'Models/Facture.php' => <<<'PHP'
            <?php

            namespace App\Models;

            use App\Models\Concerns\Facture\HasMontants;
            use Illuminate\Database\Eloquent\Model;

            class Facture extends Model
            {
                use HasMontants;
            }
            PHP,
        'Models/Concerns/Facture/HasMontants.php' => $trait,
        'Services/ExportComptable.php' => <<<'PHP'
            <?php

            namespace App\Services;

            use App\Models\Concerns\Facture\HasMontants;

            class ExportComptable
            {
                use HasMontants;
            }
            PHP,
    ]);

    $output = $app->organize();

    expect($app->contents('Models/Concerns/Facture/HasMontants.php'))->toBe($trait)
        ->and($app->contents('Models/Facture.php'))->toContain('    use HasMontants;')
        ->and($app->exists('Models/Concerns/Facture/Relations.php'))->toBeFalse()
        ->and($output)->toContain('utilisé hors des modèles', 'HasMontants.php')
        ->and($app->methods(['App\Models\Facture', 'App\Services\ExportComptable']))->toBe([
            'App\Models\Facture' => ['devise', 'montantTtc'],
            'App\Services\ExportComptable' => ['devise', 'montantTtc'],
        ]);
});
