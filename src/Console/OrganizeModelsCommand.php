<?php

namespace Axn\ModelsOrganizer\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Attributes\Scope as ScopeAttribute;
use Illuminate\Database\Eloquent\Casts\Attribute as EloquentAttribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use RuntimeException;
use Throwable;

class OrganizeModelsCommand extends Command
{
    protected $signature = 'models:organize
        {model? : Classe modèle précise, ex: App\\Models\\Article}
        {--dry-run : Affiche les fichiers modifiés sans écrire}';

    protected $description = 'Organise les modèles Eloquent en traits Attributes, Relations et Scopes.';

    private mixed $parser;

    private NodeFinder $finder;

    public function handle(): int
    {
        $this->removeUnusedTraitsInModelsDirectory();

        $this->parser = new ParserFactory()->createForNewestSupportedVersion();
        $this->finder = new NodeFinder();

        $models = $this->argument('model')
            ? [$this->argument('model')]
            : $this->discoverModels();

        foreach ($models as $modelClass) {
            if (! class_exists($modelClass)) {
                continue;
            }

            if (! is_subclass_of($modelClass, Model::class)) {
                continue;
            }

            try {
                $this->organizeModel($modelClass);
            } catch (Throwable $e) {
                $this->error('  '.$e->getMessage());
            }
        }

        $this->removeEmptyDirectories(app_path('Models'));

        $this->info('Organisation terminée.');

        return self::SUCCESS;
    }

    private function removeUnusedTraitsInModelsDirectory(): void
    {
        $traits = $this->discoverApplicationTraitsInModelsDirectory();

        if ($traits === []) {
            return;
        }

        $usedTraits = $this->discoverUsedTraitsByModels();

        foreach ($traits as $traitClass => $traitFile) {
            if (isset($usedTraits[$traitClass])) {
                continue;
            }

            $this->deleteUnusedTraitFile($traitFile);
        }
    }

    private function discoverApplicationTraitsInModelsDirectory(): array
    {
        $basePath = app_path('Models');

        if (! File::isDirectory($basePath)) {
            return [];
        }

        $traits = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basePath)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->classFromAppFilePath($file->getPathname());

            if (! $class || ! trait_exists($class)) {
                continue;
            }

            if ($this->isVendorFile($file->getPathname())) {
                continue;
            }

            $traits[$class] = $file->getPathname();
        }

        return $traits;
    }

    private function discoverUsedTraitsByModels(): array
    {
        $usedTraits = [];

        foreach ($this->discoverModels() as $modelClass) {
            if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
                continue;
            }

            $reflection = new ReflectionClass($modelClass);

            foreach ($this->allTraitsUsedByReflection($reflection) as $traitClass) {
                $usedTraits[$traitClass] = true;
            }
        }

        return $usedTraits;
    }

    private function allTraitsUsedByReflection(ReflectionClass $reflection): array
    {
        $traits = [];

        foreach ($reflection->getTraits() as $trait) {
            $traitClass = $trait->getName();

            if ($this->isVendorFile($trait->getFileName())) {
                continue;
            }

            $traits[$traitClass] = true;

            foreach ($this->allTraitsUsedByReflection($trait) as $nestedTraitClass) {
                $traits[$nestedTraitClass] = true;
            }
        }

        return array_keys($traits);
    }

    private function classFromAppFilePath(string $path): ?string
    {
        $realPath = realpath($path);

        if ($realPath === false) {
            return null;
        }

        $appPath = realpath(app_path());

        if ($appPath === false || ! str_starts_with($realPath, $appPath.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return 'App\\'.str($realPath)
            ->after($appPath.DIRECTORY_SEPARATOR)
            ->beforeLast('.php')
            ->replace(DIRECTORY_SEPARATOR, '\\')
            ->toString();
    }

    private function deleteUnusedTraitFile(string $path): void
    {
        if ($this->option('dry-run')) {
            $this->line('[dry-run] Trait inutilisé supprimé : '.$this->shortenPath($path));

            return;
        }

        File::delete($path);

        $this->line('Trait inutilisé supprimé : '.$this->shortenPath($path));
    }

    private function discoverModels(): array
    {
        $basePath = app_path('Models');

        if (! File::isDirectory($basePath)) {
            return [];
        }

        $models = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basePath)) as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains((string) $file->getPathname(), DIRECTORY_SEPARATOR.'Concerns'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relative = str($file->getPathname())
                ->after(app_path().DIRECTORY_SEPARATOR)
                ->beforeLast('.php')
                ->replace(DIRECTORY_SEPARATOR, '\\');

            $class = 'App\\'.$relative;

            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $models[] = $class;
            }
        }

        return $models;
    }

    private function organizeModel(string $modelClass): void
    {
        $reflection = new ReflectionClass($modelClass);
        $modelFile = $reflection->getFileName();

        if (! $modelFile) {
            return;
        }

        $this->info($modelClass);

        $this->ensureModelUsedTraitsExist($modelFile);

        $collected = [
            'Relations' => [],
            'Scopes' => [],
            'Attributes' => [],
            'unknown' => [],
        ];

        $removableMethods = [];

        $this->collectFinalMethodsFromModel(
            modelReflection: $reflection,
            collected: $collected,
            removableMethods: $removableMethods,
        );

        $concernBase = $this->concernBaseForModel($modelClass);
        $concernNamespace = $this->concernNamespaceForModel($modelClass);
        $generatedTraits = [];

        foreach (['Relations', 'Scopes', 'Attributes'] as $traitName) {
            $traitPath = $concernBase.DIRECTORY_SEPARATOR.$traitName.'.php';
            $traitClass = $concernNamespace.'\\'.$traitName;

            $traitMethods = [
                ...$collected[$traitName],
                ...collect($collected['unknown'])->where('sourceClass', $traitClass)->all(),
            ];

            if ($traitMethods === []) {
                continue;
            }

            $this->writeConcernTrait($traitPath, $concernNamespace, $traitName, $traitMethods);

            $generatedTraits[$traitPath] = $traitClass;
        }

        $emptyTraitFilesToDelete = $this->detectEmptyUsedTraitFiles($reflection);

        if ($generatedTraits === [] && $emptyTraitFilesToDelete === []) {
            return;
        }

        $this->rewriteSourceFiles(
            modelFile: $modelFile,
            generatedTraits: $generatedTraits,
            movedMethods: $removableMethods,
            emptyTraitFilesToDelete: $emptyTraitFilesToDelete,
        );
    }

    private function ensureModelUsedTraitsExist(string $modelFile): void
    {
        $code = File::get($modelFile);

        try {
            $ast = $this->parser->parse($code);
        } catch (Error $error) {
            throw new RuntimeException('Impossible de parser le code du modèle : '.$error->getMessage(), $error->getCode(), $error);
        }

        $namespaceNode = $this->finder->findFirstInstanceOf($ast, Namespace_::class);

        if (! $namespaceNode instanceof Namespace_) {
            return;
        }

        $imports = [];

        foreach ($namespaceNode->stmts as $stmt) {
            if (! $stmt instanceof Use_) {
                continue;
            }

            foreach ($stmt->uses as $use) {
                $shortName = $use->alias?->toString() ?? $use->name->getLast();
                $imports[$shortName] = $use->name->toString();
            }
        }

        $class = $this->finder->findFirstInstanceOf($ast, Class_::class);

        if (! $class instanceof Class_) {
            return;
        }

        foreach ($class->stmts as $stmt) {
            if (! $stmt instanceof TraitUse) {
                continue;
            }

            foreach ($stmt->traits as $traitName) {
                $shortName = $traitName->getLast();

                $fqcn = $imports[$shortName]
                    ?? $namespaceNode->name->toString().'\\'.$shortName;

                if (! trait_exists($fqcn)) {
                    throw new RuntimeException('Trait introuvable : '.$fqcn);
                }

                $reflection = new ReflectionClass($fqcn);
                $file = $reflection->getFileName();

                if (! $file || ! File::exists($file)) {
                    throw new RuntimeException('Fichier introuvable : '.$this->shortenPath($file));
                }
            }
        }
    }

    private function collectFinalMethodsFromModel(
        ReflectionClass $modelReflection,
        array &$collected,
        array &$removableMethods,
    ): void {
        $resolvedMethods = [];

        $this->collectOwnClassifiableMethods(
            reflection: $modelReflection,
            resolvedMethods: $resolvedMethods,
            removableMethods: $removableMethods,
            priority: 0,
        );

        $visitedTraits = [];

        foreach ($modelReflection->getTraits() as $trait) {
            $this->collectFinalMethodsFromTrait(
                trait: $trait,
                resolvedMethods: $resolvedMethods,
                removableMethods: $removableMethods,
                visitedTraits: $visitedTraits,
                priority: 1,
            );
        }

        foreach ($resolvedMethods as $methodData) {
            $type = $methodData['type'];

            unset($methodData['type'], $methodData['priority']);

            $collected[$type][$methodData['methodName']] = $methodData;
        }
    }

    private function collectFinalMethodsFromTrait(
        ReflectionClass $trait,
        array &$resolvedMethods,
        array &$removableMethods,
        array &$visitedTraits,
        int $priority,
    ): void {
        $traitName = $trait->getName();

        if (isset($visitedTraits[$traitName])) {
            return;
        }

        if ($this->isVendorFile($trait->getFileName())) {
            return;
        }

        $visitedTraits[$traitName] = true;

        $this->collectOwnClassifiableMethods(
            reflection: $trait,
            resolvedMethods: $resolvedMethods,
            removableMethods: $removableMethods,
            priority: $priority,
        );

        foreach ($trait->getTraits() as $nestedTrait) {
            $this->collectFinalMethodsFromTrait(
                trait: $nestedTrait,
                resolvedMethods: $resolvedMethods,
                removableMethods: $removableMethods,
                visitedTraits: $visitedTraits,
                priority: $priority + 1,
            );
        }
    }

    private function collectOwnClassifiableMethods(
        ReflectionClass $reflection,
        array &$resolvedMethods,
        array &$removableMethods,
        int $priority,
    ): void {
        $reflectionFile = $reflection->getFileName();

        if (! $reflectionFile) {
            return;
        }

        foreach ($reflection->getMethods() as $method) {
            $methodFile = $method->getFileName();

            if (! $methodFile) {
                continue;
            }

            if ($methodFile !== $reflectionFile) {
                continue;
            }

            if ($this->isVendorFile($methodFile)) {
                continue;
            }

            $type = $this->methodType($method);

            $methodName = $method->getName();

            $methodData = [
                'type' => $type,
                'priority' => $priority,
                'methodName' => $methodName,
                'method' => $method,
                'sourceClass' => $reflection->getName(),
                'sourceFile' => $methodFile,
                'code' => $this->getMethodOriginalCode($method),
                'uses' => $this->mergeRawUses(
                    $this->extractUsesFromFile($methodFile),
                    $this->inferSameNamespaceUsesForMethod($method),
                ),
            ];

            if ($type !== 'unknown') {
                $removableMethods[$methodFile.'::'.$method->getStartLine().'::'.$methodName] = $methodData;
            }

            if (
                isset($resolvedMethods[$methodName])
                && $resolvedMethods[$methodName]['priority'] <= $priority
            ) {
                continue;
            }

            $resolvedMethods[$methodName] = $methodData;
        }
    }

    private function methodType(ReflectionMethod $method): string
    {
        if ($this->methodIsScope($method)) {
            return 'Scopes';
        }

        if ($this->methodIsAttribute($method)) {
            return 'Attributes';
        }

        if ($this->methodIsRelation($method)) {
            return 'Relations';
        }

        return 'unknown';
    }

    private function methodIsScope(ReflectionMethod $method): bool
    {
        if (str_starts_with($method->getName(), 'scope')) {
            return true;
        }

        return array_any($method->getAttributes(), fn ($attribute): bool => $attribute->getName() === ScopeAttribute::class);
    }

    private function methodIsAttribute(ReflectionMethod $method): bool
    {
        $name = $method->getName();

        if (preg_match('/^(get|set).+Attribute$/', $name)) {
            return true;
        }

        $returnType = $method->getReturnType();

        return $returnType instanceof ReflectionNamedType
            && $returnType->getName() === EloquentAttribute::class;
    }

    private function methodIsRelation(ReflectionMethod $method): bool
    {
        if (! $method->isPublic()) {
            return false;
        }

        if ($method->getReturnType() instanceof ReflectionNamedType) {
            return str_starts_with(
                $method->getReturnType()->getName(),
                'Illuminate\\Database\\Eloquent\\Relations\\',
            );
        }

        if ($method->getReturnType() instanceof ReflectionType) {
            return false;
        }

        if ($method->getDeclaringClass()->getName() === Model::class) {
            return false;
        }

        return (bool) preg_match(
            '/(\{|;)\s*return\s*\$this\s*->\s*(belongsTo|belongsToMany|hasMany|hasManyThrough|hasOne|hasOneThrough|morphTo|morphToMany|morphMany|morphOne|morphedByMany)\s*\(/i',
            (string) $this->getMethodContentAsString($method),
        );
    }

    private function getMethodContentAsString(ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();

        if ($file === false || ! File::exists($file)) {
            return null;
        }

        $start = $method->getStartLine();
        $end = $method->getEndLine();

        if ($start === false || $end === false) {
            return null;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return null;
        }

        $lines = array_map(trim(...), \array_slice($lines, $start - 1, $end - $start + 1));
        $lines = array_filter($lines, fn (string $line): bool => ! preg_match('`^(//|/\*|\*|\*/)`', $line));

        return implode(' ', $lines);
    }

    private function getMethodOriginalCode(ReflectionMethod $method): string
    {
        $range = $this->getMethodRangeIncludingDocAndAttributes($method);

        if (! $range) {
            return '';
        }

        $file = $method->getFileName();

        if (! $file || ! File::exists($file)) {
            return '';
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return '';
        }

        return implode("\n", \array_slice(
            $lines,
            $range['start'] - 1,
            $range['end'] - $range['start'] + 1,
        ));
    }

    private function getMethodRangeIncludingDocAndAttributes(ReflectionMethod $method): ?array
    {
        $file = $method->getFileName();

        if (! $file || ! File::exists($file)) {
            return null;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false || $lines === []) {
            return null;
        }

        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        if ($startLine === false || $endLine === false) {
            return null;
        }

        $lineCount = \count($lines);

        if ($startLine < 1 || $endLine < $startLine || $startLine > $lineCount) {
            return null;
        }

        $endLine = min($endLine, $lineCount);
        $startIndex = $startLine - 1;

        while ($startIndex > 0 && isset($lines[$startIndex - 1])) {
            $previousLine = trim($lines[$startIndex - 1]);

            if ($previousLine === '') {
                break;
            }

            if (
                str_starts_with($previousLine, '#[')
                || str_starts_with($previousLine, '*')
                || str_starts_with($previousLine, '/**')
                || str_starts_with($previousLine, '*/')
            ) {
                $startIndex--;

                continue;
            }

            break;
        }

        return [
            'start' => $startIndex + 1,
            'end' => $endLine,
        ];
    }

    private function concernBaseForModel(string $modelClass): string
    {
        $relativeClass = str($modelClass)
            ->after('App\\Models\\')
            ->replace('\\', DIRECTORY_SEPARATOR)
            ->toString();

        return app_path('Models'.DIRECTORY_SEPARATOR.'Concerns'.DIRECTORY_SEPARATOR.$relativeClass);
    }

    private function concernNamespaceForModel(string $modelClass): string
    {
        $relativeClass = str($modelClass)
            ->after('App\\Models\\')
            ->toString();

        return 'App\\Models\\Concerns\\'.$relativeClass;
    }

    private function writeConcernTrait(
        string $path,
        string $namespace,
        string $name,
        array $methods,
    ): void {
        File::ensureDirectoryExists(dirname($path));

        $uses = $this->mergeUses($methods);

        $body = collect($methods)
            ->map(fn (array $method): string => '    '.trim((string) $method['code']))
            ->filter()
            ->sortKeys()
            ->implode("\n\n");

        $content = "<?php\n\n";
        $content .= "namespace {$namespace};\n\n";

        foreach ($uses as $use) {
            $fqcn = $use['fqcn'];
            $alias = $use['alias'];

            if (str_starts_with((string) $fqcn, $namespace.'\\')) {
                continue;
            }

            $content .= 'use '.$fqcn;

            if ($alias) {
                $content .= ' as '.$alias;
            }

            $content .= ";\n";
        }

        if ($uses !== []) {
            $content .= "\n";
        }

        $content .= 'trait '.$name."\n";
        $content .= "{\n";
        $content .= $body."\n";
        $content .= "}\n";

        $this->writeFile($path, $content);
    }

    private function mergeUses(array $methods): array
    {
        return collect($methods)
            ->flatMap(fn (array $method): array => $method['uses'])
            ->unique(fn (array $use): string => $use['fqcn'].' as '.($use['alias'] ?? ''))
            ->sortBy(fn (array $use): string => $use['fqcn'])
            ->values()
            ->all();
    }

    private function mergeRawUses(array ...$usesGroups): array
    {
        return collect($usesGroups)
            ->flatten(1)
            ->map(function (array|string $use): array {
                if (\is_string($use)) {
                    return [
                        'fqcn' => $use,
                        'alias' => null,
                    ];
                }

                return [
                    'fqcn' => $use['fqcn'],
                    'alias' => $use['alias'] ?? null,
                ];
            })
            ->unique(fn (array $use): string => $use['fqcn'].' as '.($use['alias'] ?? ''))
            ->values()
            ->all();
    }

    private function extractUsesFromFile(?string $file): array
    {
        if (! $file || ! File::exists($file)) {
            return [];
        }

        try {
            $ast = $this->parser->parse(File::get($file));
        } catch (Error) {
            return [];
        }

        $uses = [];

        foreach ($ast as $node) {
            if (! $node instanceof Namespace_) {
                continue;
            }

            foreach ($node->stmts as $stmt) {
                if (! $stmt instanceof Use_) {
                    continue;
                }

                foreach ($stmt->uses as $use) {
                    $uses[] = [
                        'fqcn' => $use->name->toString(),
                        'alias' => $use->alias?->toString(),
                    ];
                }
            }
        }

        return $uses;
    }

    private function inferSameNamespaceUsesForMethod(ReflectionMethod $method): array
    {
        $namespace = $method->getDeclaringClass()->getNamespaceName();

        if ($namespace === '') {
            return [];
        }

        $code = $this->getMethodOriginalCode($method);

        if ($code === '') {
            return [];
        }

        try {
            $ast = $this->parser->parse('<?php class Tmp {'.$code.'}');
        } catch (Error) {
            return [];
        }

        $names = [];

        $this->traverseNodes($ast, function (Node $node) use (&$names): void {
            if (! $node instanceof Name) {
                return;
            }

            $name = $node->toString();

            if (str_contains($name, '\\')) {
                return;
            }

            if (\in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
                return;
            }

            $names[] = $name;
        });

        return collect($names)
            ->unique()
            ->map(fn (string $name): array => [
                'fqcn' => $namespace.'\\'.$name,
                'alias' => null,
            ])
            ->filter(fn (array $use): bool => class_exists($use['fqcn'])
                || interface_exists($use['fqcn'])
                || trait_exists($use['fqcn'])
                || enum_exists($use['fqcn']))
            ->values()
            ->all();
    }

    private function traverseNodes(array|Node $nodes, callable $callback): void
    {
        foreach (\is_array($nodes) ? $nodes : [$nodes] as $node) {
            if (! $node instanceof Node) {
                continue;
            }

            $callback($node);

            foreach ($node->getSubNodeNames() as $subNodeName) {
                $subNode = $node->{$subNodeName};

                if ($subNode instanceof Node || \is_array($subNode)) {
                    $this->traverseNodes($subNode, $callback);
                }
            }
        }
    }

    private function rewriteSourceFiles(
        string $modelFile,
        array $generatedTraits,
        array $movedMethods,
        array $emptyTraitFilesToDelete = [],
    ): void {
        $methodsByFile = collect($movedMethods)
            ->filter(fn (array $method): bool => ! empty($method['sourceFile']))
            ->groupBy('sourceFile');

        if (! $methodsByFile->has($modelFile)) {
            $methodsByFile->put($modelFile, collect());
        }

        $deletedTraitShortNames = [];

        foreach ($emptyTraitFilesToDelete as $traitFile => $traitShortName) {
            $deletedTraitShortNames[] = $traitShortName;

            if (File::exists($traitFile)) {
                $this->deleteTraitFile($traitFile);
            }
        }

        $emptyTraitFiles = array_keys($emptyTraitFilesToDelete);

        $files = $methodsByFile->keys()
            ->reject(fn (string $file): bool => $file === $modelFile)
            ->reject(fn (string $file): bool => \in_array($file, $emptyTraitFiles, true))
            ->push($modelFile);

        foreach ($files as $file) {
            if (! File::exists($file)) {
                throw new RuntimeException('Fichier introuvable : '.$file);
            }

            $methods = $methodsByFile
                ->get($file, collect())
                ->reject(fn (array $method) => \in_array($method['sourceClass'], $generatedTraits, true));

            $content = File::get($file);

            if ($methods->isNotEmpty()) {
                $content = $this->removeMovedMethodsFromContent($content, $methods);
            }

            if ($file !== $modelFile && ! \array_key_exists($file, $generatedTraits) && $this->fileContainsTrait($content)) {
                if ($this->traitContentHasNoDeclaredMethods($content)) {
                    $traitShortName = $this->extractTraitShortNameFromContent($content);

                    if ($traitShortName) {
                        $deletedTraitShortNames[] = $traitShortName;
                    }

                    $this->deleteTraitFile($file);

                    continue;
                }

                $this->warn('  Trait conservé car il contient encore des méthodes : '.$this->shortenPath($file));
            }

            if ($file === $modelFile) {
                $content = $this->removeOldTraitUsesFromContent($content, $deletedTraitShortNames);
                $content = $this->addUseStatementsToContent($content, $generatedTraits);
                $content = $this->addTraitUsesInsideClassContent(
                    content: $content,
                    traitShortNames: array_map(
                        class_basename(...),
                        $generatedTraits,
                    ),
                );
            }

            $this->writeFile($file, $content);
        }
    }

    private function removeMovedMethodsFromContent(string $content, Collection $methods): string
    {
        $lines = explode("\n", $content);

        $ranges = $methods
            ->map(function (array $method): ?array {
                /** @var ReflectionMethod $reflectionMethod */
                $reflectionMethod = $method['method'];

                return $this->getMethodRangeIncludingDocAndAttributes($reflectionMethod);
            })
            ->filter()
            ->sortByDesc('start')
            ->values();

        foreach ($ranges as $range) {
            $startIndex = $range['start'] - 1;
            $length = $range['end'] - $range['start'] + 1;

            array_splice($lines, $startIndex, $length);
        }

        return implode("\n", $lines);
    }

    private function addUseStatementsToContent(string $content, array $fqcnList): string
    {
        $newUses = [];

        foreach ($fqcnList as $fqcn) {
            if (preg_match('/^use\s+'.preg_quote((string) $fqcn, '/').'\s*;/m', $content)) {
                continue;
            }

            $newUses[] = \sprintf('use %s;', $fqcn);
        }

        if ($newUses === []) {
            return $content;
        }

        $useBlock = implode("\n", $newUses)."\n";

        if (preg_match_all('/^use\s+[^;]+;\s*$/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $last = end($matches[0]);
            $insertAt = $last[1] + \strlen($last[0]);

            return substr($content, 0, $insertAt)."\n".$useBlock.substr($content, $insertAt);
        }

        return preg_replace(
            '/^(namespace\s+[^;]+;\s*\R)/m',
            '$1'."\n".$useBlock,
            $content,
            1,
        );
    }

    private function addTraitUsesInsideClassContent(string $content, array $traitShortNames): string
    {
        $newUses = [];

        foreach ($traitShortNames as $traitShortName) {
            if (preg_match('/^[ \t]*use\s+'.preg_quote((string) $traitShortName, '/').'\s*;/m', $content)) {
                continue;
            }

            $newUses[] = \sprintf('    use %s;', $traitShortName);
        }

        if ($newUses === []) {
            return $content;
        }

        return preg_replace(
            '/(class\s+\w+[^{]*\{\R)/',
            '$1'.implode("\n", $newUses)."\n",
            $content,
            1,
        );
    }

    private function removeOldTraitUsesFromContent(string $content, array $traitShortNamesToRemove): string
    {
        foreach (array_filter($traitShortNamesToRemove) as $traitShortName) {
            $content = preg_replace(
                '/^[ \t]*use[ \t]+'.preg_quote((string) $traitShortName, '/').'[ \t]*;[ \t]*\R/m',
                '',
                (string) $content,
            );
        }

        return $content;
    }

    private function fileContainsTrait(string $content): bool
    {
        return (bool) preg_match('/\btrait\s+\w+\b/', $content);
    }

    private function extractTraitShortNameFromContent(string $content): ?string
    {
        if (preg_match('/\btrait\s+(\w+)\b/', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function traitContentHasNoDeclaredMethods(string $content): bool
    {
        try {
            $ast = $this->parser->parse($content);
        } catch (Error) {
            return false;
        }

        $trait = $this->finder->findFirstInstanceOf($ast, Trait_::class);

        if (! $trait instanceof Trait_) {
            return false;
        }

        foreach ($trait->stmts as $stmt) {
            if ($stmt instanceof ClassMethod) {
                return false;
            }
        }

        return true;
    }

    private function detectEmptyUsedTraitFiles(ReflectionClass $modelReflection): array
    {
        $emptyTraits = [];
        $visitedTraits = [];

        foreach ($modelReflection->getTraits() as $trait) {
            $this->detectEmptyTraitRecursive(
                trait: $trait,
                emptyTraits: $emptyTraits,
                visitedTraits: $visitedTraits,
            );
        }

        return $emptyTraits;
    }

    private function detectEmptyTraitRecursive(
        ReflectionClass $trait,
        array &$emptyTraits,
        array &$visitedTraits,
    ): void {
        $traitName = $trait->getName();

        if (isset($visitedTraits[$traitName])) {
            return;
        }

        $visitedTraits[$traitName] = true;
        $file = $trait->getFileName();

        if ($this->isVendorFile($file)) {
            return;
        }

        if ($file && File::exists($file)) {
            $content = File::get($file);

            if (
                $this->fileContainsTrait($content)
                && $this->traitContentHasNoDeclaredMethods($content)
            ) {
                $emptyTraits[$file] = $trait->getShortName();
            }
        }

        foreach ($trait->getTraits() as $nestedTrait) {
            $this->detectEmptyTraitRecursive(
                trait: $nestedTrait,
                emptyTraits: $emptyTraits,
                visitedTraits: $visitedTraits,
            );
        }
    }

    private function isVendorFile(?string $path): bool
    {
        if (! $path) {
            return true;
        }

        $realPath = realpath($path);

        if ($realPath === false) {
            return true;
        }

        return str_contains($realPath, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR);
    }

    private function deleteTraitFile(string $path): void
    {
        if ($this->option('dry-run')) {
            $this->line('  [dry-run] Supprimé : '.$this->shortenPath($path));

            return;
        }

        File::delete($path);
    }

    private function removeEmptyDirectories(string $directory): bool
    {
        if (! File::isDirectory($directory)) {
            return false;
        }

        $isEmpty = true;

        foreach (File::directories($directory) as $subDirectory) {
            if (! $this->removeEmptyDirectories($subDirectory)) {
                $isEmpty = false;
            }
        }

        if (File::files($directory) !== [] || File::directories($directory) !== []) {
            $isEmpty = false;
        }

        if ($isEmpty && $directory !== app_path('Models')) {
            if ($this->option('dry-run')) {
                $this->line('[dry-run] Dossier vide supprimé : '.$this->shortenPath($directory));

                return true;
            }

            File::deleteDirectory($directory);

            $this->line('Dossier vide supprimé : '.$this->shortenPath($directory));

            return true;
        }

        return false;
    }

    private function writeFile(string $path, string $content): void
    {
        if ($this->option('dry-run')) {
            $this->line('  [dry-run] Écrit : '.$this->shortenPath($path));

            return;
        }

        File::put($path, $content);
    }

    private function shortenPath(string $path): string
    {
        return (string) str($path)->after(base_path().'/');
    }
}
