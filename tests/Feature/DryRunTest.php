<?php

declare(strict_types=1);

use Axn\ModelsOrganizer\Tests\Support\TemporaryApplication;

/**
 * Application où la commande écrit, supprime des traits et vide des
 * dossiers ; $prefix rend ses classes propres au test.
 */
function applicationToReorganize(string $prefix): TemporaryApplication
{
    return TemporaryApplication::create([
        "Models/{$prefix}Client.php" => <<<PHP
            <?php

            namespace App\\Models;

            use Illuminate\\Database\\Eloquent\\Model;

            class {$prefix}Client extends Model
            {
            }
            PHP,
        "Models/{$prefix}Commande.php" => <<<PHP
            <?php

            namespace App\\Models;

            use App\\Models\\Concerns\\Relations\\{$prefix}CommandeRelations;
            use Illuminate\\Database\\Eloquent\\Model;

            class {$prefix}Commande extends Model
            {
                use {$prefix}CommandeRelations;
            }
            PHP,
        "Models/Concerns/Relations/{$prefix}CommandeRelations.php" => <<<PHP
            <?php

            namespace App\\Models\\Concerns\\Relations;

            use App\\Models\\Concerns\\GeneratedRelations\\{$prefix}CommandeRelations as GeneratedRelations;

            trait {$prefix}CommandeRelations
            {
                use GeneratedRelations;
            }
            PHP,
        "Models/Concerns/GeneratedRelations/{$prefix}CommandeRelations.php" => <<<PHP
            <?php

            namespace App\\Models\\Concerns\\GeneratedRelations;

            use App\\Models\\{$prefix}Client;
            use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

            trait {$prefix}CommandeRelations
            {
                public function client(): BelongsTo
                {
                    return \$this->belongsTo({$prefix}Client::class);
                }
            }
            PHP,
        "Models/Concerns/Inutilises/{$prefix}HasRien.php" => <<<PHP
            <?php

            namespace App\\Models\\Concerns\\Inutilises;

            trait {$prefix}HasRien
            {
            }
            PHP,
    ]);
}

/**
 * Actions annoncées par une simulation, en chemins relatifs à app/.
 *
 * @return array{written: list<string>, deleted: list<string>, directories: list<string>}
 */
function announcedActions(string $output, TemporaryApplication $app): array
{
    preg_match_all('/\[dry-run\] (Écrit|Supprimé|Trait inutilisé supprimé|Dossier vide supprimé) : (\S+)/u', $output, $matches, PREG_SET_ORDER);

    $actions = ['written' => [], 'deleted' => [], 'directories' => []];

    foreach ($matches as [, $action, $path]) {
        $key = match ($action) {
            'Écrit' => 'written',
            'Dossier vide supprimé' => 'directories',
            default => 'deleted',
        };

        $actions[$key][] = substr($path, strlen($app->path()) + 1);
    }

    return array_map(function (array $paths): array {
        sort($paths);

        return array_values(array_unique($paths));
    }, $actions);
}

it('n\'écrit rien sur le disque en simulation', function (): void {
    $app = applicationToReorganize('Simulee');
    $before = $app->snapshot();

    $app->organize(['--dry-run' => true]);

    expect($app->snapshot())->toBe($before);
});

it('annonce en simulation ce que fait l\'exécution réelle, et seulement cela', function (): void {
    $app = applicationToReorganize('Annoncee');
    $before = $app->snapshot();

    $announced = announcedActions($app->organize(['--dry-run' => true]), $app);

    $app->organize();
    $after = $app->snapshot();

    $done = ['written' => [], 'deleted' => [], 'directories' => []];

    foreach ($after as $path => $content) {
        if ($content !== null && ($before[$path] ?? null) !== $content) {
            $done['written'][] = $path;
        }
    }

    foreach ($before as $path => $content) {
        if (! array_key_exists($path, $after)) {
            $done[$content === null ? 'directories' : 'deleted'][] = $path;
        }
    }

    $done = array_map(function (array $paths): array {
        sort($paths);

        return $paths;
    }, $done);

    expect($announced)->toBe($done)
        ->and($done['directories'])->toBe(['Models/Concerns/GeneratedRelations', 'Models/Concerns/Inutilises', 'Models/Concerns/Relations']);
});
