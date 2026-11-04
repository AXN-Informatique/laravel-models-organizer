<?php

declare(strict_types=1);

namespace Axn\ModelsOrganizer\Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Application jetable sur laquelle la commande travaille : ses fichiers sont
 * écrits dans un dossier temporaire désigné comme app_path(), et ses classes
 * App\ sont chargées depuis ce dossier.
 *
 * Une classe chargée le reste jusqu'à la fin du processus : chaque test
 * emploie des noms de classes qu'aucun autre test n'utilise. Le résultat
 * d'une commande se vérifie donc dans un processus séparé (lintErrors(),
 * methods()), qui lit les fichiers tels qu'elle les a laissés.
 */
final class TemporaryApplication
{
    private static ?string $currentBasePath = null;

    private static bool $autoloaderRegistered = false;

    private function __construct(
        public readonly string $basePath,
    ) {}

    /**
     * @param  array<string, string>  $files  contenus indexés par chemin relatif à app/
     */
    public static function create(array $files): self
    {
        self::registerAutoloader();

        $basePath = sys_get_temp_dir().'/models-organizer-tests/'.bin2hex(random_bytes(6));

        foreach ($files as $relativePath => $content) {
            $class = 'App\\'.str_replace('/', '\\', substr($relativePath, 0, -4));

            if (class_exists($class, false) || trait_exists($class, false)) {
                throw new LogicException('Classe déjà chargée par un autre test : '.$class);
            }

            File::ensureDirectoryExists(\dirname($basePath.'/app/'.$relativePath));
            File::put($basePath.'/app/'.$relativePath, $content);
        }

        self::$currentBasePath = $basePath;

        app()->useAppPath($basePath.'/app');

        return new self($basePath);
    }

    public static function cleanup(): void
    {
        if (self::$currentBasePath !== null) {
            File::deleteDirectory(self::$currentBasePath);
        }

        self::$currentBasePath = null;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function organize(array $parameters = []): string
    {
        Artisan::call('models:organize', $parameters);

        return Artisan::output();
    }

    public function path(string $relativePath = ''): string
    {
        return $this->basePath.'/app'.($relativePath === '' ? '' : '/'.$relativePath);
    }

    public function exists(string $relativePath): bool
    {
        return file_exists($this->path($relativePath));
    }

    public function contents(string $relativePath): string
    {
        return File::get($this->path($relativePath));
    }

    /**
     * État du disque : contenu de chaque fichier, null pour chaque dossier.
     *
     * @return array<string, string|null>
     */
    public function snapshot(): array
    {
        $entries = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path(), RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            $relativePath = substr((string) $entry->getPathname(), \strlen($this->path()) + 1);

            $entries[$relativePath] = $entry->isDir() ? null : File::get($entry->getPathname());
        }

        ksort($entries);

        return $entries;
    }

    /**
     * Erreurs de php -l sur chaque fichier PHP de l'application.
     *
     * @return list<string>
     */
    public function lintErrors(): array
    {
        $errors = [];

        foreach ($this->snapshot() as $relativePath => $content) {
            if ($content === null || ! str_ends_with($relativePath, '.php')) {
                continue;
            }

            $result = Process::run([PHP_BINARY, '-l', $this->path($relativePath)]);

            if ($result->failed()) {
                $errors[] = $relativePath.' : '.trim($result->output().$result->errorOutput());
            }
        }

        return $errors;
    }

    /**
     * Méthodes de chaque classe déclarées dans l'application (modèle et
     * traits), relevées par réflexion dans un processus séparé.
     *
     * @param  list<string>  $classes
     * @return array<string, list<string>>
     */
    public function methods(array $classes): array
    {
        $result = Process::run([PHP_BINARY, __DIR__.'/list-methods.php', $this->path(), ...$classes]);

        if ($result->failed()) {
            throw new RuntimeException('Classes non chargeables : '.trim($result->output().$result->errorOutput()));
        }

        return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function registerAutoloader(): void
    {
        if (self::$autoloaderRegistered) {
            return;
        }

        spl_autoload_register(static function (string $class): void {
            if (self::$currentBasePath === null || ! str_starts_with($class, 'App\\')) {
                return;
            }

            $file = self::$currentBasePath.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';

            if (is_file($file)) {
                require $file;
            }
        }, prepend: true);

        self::$autoloaderRegistered = true;
    }
}
