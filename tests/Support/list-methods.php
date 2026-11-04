<?php

/*
 * Usage : php list-methods.php <dossier app> <classe>...
 *
 * Affiche en JSON, pour chaque classe, les méthodes déclarées dans le dossier
 * app donné (la classe et ses traits), triées par nom. Lancé dans un processus
 * séparé : les classes y sont chargées telles que la commande les a laissées.
 */

declare(strict_types=1);

$appPath = realpath($argv[1]);
$classes = array_slice($argv, 2);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class) use ($appPath): void {
    if (! str_starts_with($class, 'App\\')) {
        return;
    }

    $file = $appPath.'/'.str_replace('\\', '/', substr($class, 4)).'.php';

    if (is_file($file)) {
        require $file;
    }
}, prepend: true);

$result = [];

foreach ($classes as $class) {
    $methods = [];

    foreach (new ReflectionClass($class)->getMethods() as $method) {
        if (str_starts_with((string) $method->getFileName(), $appPath.'/')) {
            $methods[] = $method->getName();
        }
    }

    sort($methods);

    $result[$class] = $methods;
}

echo json_encode($result, JSON_THROW_ON_ERROR);
