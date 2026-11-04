<?php

declare(strict_types=1);

use Axn\ModelsOrganizer\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Cas de test
|--------------------------------------------------------------------------
|
| Les tests s'appuient sur le cas de base Testbench : il amorce une
| application minimale et enregistre le ServiceProvider du package. Chaque
| test monte ses modèles et ses traits dans une application jetable
| (Support\TemporaryApplication).
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Fixtures partagées
|--------------------------------------------------------------------------
*/

/**
 * Modèle vide $name, cible des relations des fixtures.
 */
function emptyModel(string $name): string
{
    return <<<PHP
        <?php

        namespace App\\Models;

        use Illuminate\\Database\\Eloquent\\Model;

        class {$name} extends Model
        {
        }
        PHP;
}

/**
 * Modèle $name qui n'utilise que le trait $traitClass.
 */
function modelUsingTrait(string $name, string $traitClass): string
{
    $shortName = class_basename($traitClass);

    return <<<PHP
        <?php

        namespace App\\Models;

        use {$traitClass};
        use Illuminate\\Database\\Eloquent\\Model;

        class {$name} extends Model
        {
            use {$shortName};
        }
        PHP;
}
