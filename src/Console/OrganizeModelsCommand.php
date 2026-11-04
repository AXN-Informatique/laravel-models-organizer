<?php

namespace Axn\ModelsOrganizer\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Attributes\Scope as ScopeAttribute;
use Illuminate\Database\Eloquent\Casts\Attribute as EloquentAttribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use PhpParser\Comment;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
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

    private array $ignoredTraits = [];

    /**
     * Classes, enums et traits de app/ relevés par analyse statique.
     *
     * @var array<string, array{file: string, isTrait: bool, traits: list<string>}>
     */
    private array $classLikes = [];

    /**
     * Traits que la commande ne parcourt ni ne modifie, avec la raison
     * affichée quand un modèle les utilise (null : aucun message).
     *
     * @var array<string, string|null>
     */
    private array $protectedTraits = [];

    /**
     * Contenu des fichiers lus, tel qu'au lancement de la commande : toute
     * l'analyse s'appuie dessus, même quand un fichier partagé par plusieurs
     * modèles a été réécrit ou supprimé entre-temps.
     *
     * @var array<string, string>
     */
    private array $originalContents = [];

    /**
     * @var array<string, array<int, Node>>
     */
    private array $parsedFiles = [];

    /**
     * Fichiers écrits et supprimés, y compris en simulation.
     *
     * @var array<string, true>
     */
    private array $writtenFiles = [];

    /**
     * @var array<string, true>
     */
    private array $deletedFiles = [];

    public function handle(): int
    {
        $this->parser = new ParserFactory()->createForNewestSupportedVersion();
        $this->finder = new NodeFinder;
        $this->ignoredTraits = array_map(
            fn (string $traitClass): string => ltrim($traitClass, '\\'),
            config('models-organizer.ignored_traits', []),
        );

        try {
            $this->classLikes = $this->discoverClassLikesInApplication();
        } catch (RuntimeException $runtimeException) {
            $this->error($runtimeException->getMessage());

            return self::FAILURE;
        }

        $models = collect($this->argument('model') ? [ltrim((string) $this->argument('model'), '\\')] : $this->discoverModels())
            ->filter(fn (string $modelClass): bool => class_exists($modelClass) && is_subclass_of($modelClass, Model::class))
            ->values()
            ->all();

        $this->removeUnusedTraitsInModelsDirectory();

        $this->protectTraits($this->traitsUsedByClassesOtherThan($models), 'Trait non traité car utilisé hors des modèles traités');
        $this->protectTraits($this->ignoredTraits, null);

        $this->applyPlans($this->analyzeModels($models));

        $this->removeEmptyDirectories(app_path('Models'));

        $this->info('Organisation terminée.');

        return self::SUCCESS;
    }

    /**
     * Relève chaque classe, enum et trait de app/ avec les traits qu'il
     * utilise, par analyse statique : aucune classe n'est chargée.
     *
     * @return array<string, array{file: string, isTrait: bool, traits: list<string>}>
     */
    private function discoverClassLikesInApplication(): array
    {
        if (! File::isDirectory(app_path())) {
            return [];
        }

        $classLikes = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            $nodes = $this->finder->find(
                $this->parseFile($path),
                fn (Node $node): bool => $node instanceof Class_ || $node instanceof Trait_ || $node instanceof Enum_,
            );

            foreach ($nodes as $classLike) {
                $name = $classLike->namespacedName?->toString() ?? $path.'@anonymous:'.$classLike->getStartLine();

                $classLikes[$name] = [
                    'file' => $path,
                    'isTrait' => $classLike instanceof Trait_,
                    'traits' => $this->traitsUsedByNode($classLike),
                ];
            }
        }

        return $classLikes;
    }

    /**
     * @return list<string>
     */
    private function traitsUsedByNode(ClassLike $classLike): array
    {
        $traits = [];

        foreach ($classLike->stmts as $stmt) {
            if (! $stmt instanceof TraitUse) {
                continue;
            }

            foreach ($stmt->traits as $trait) {
                $traits[] = $trait->toString();
            }
        }

        return $traits;
    }

    /**
     * Les traits donnés et, récursivement, ceux qu'ils utilisent.
     *
     * @param  list<string>  $traitClasses
     * @return array<string, true>
     */
    private function reachableTraits(array $traitClasses): array
    {
        $reachable = [];

        while ($traitClasses !== []) {
            $traitClass = array_pop($traitClasses);

            if (isset($reachable[$traitClass])) {
                continue;
            }

            $reachable[$traitClass] = true;

            array_push($traitClasses, ...($this->classLikes[$traitClass]['traits'] ?? []));
        }

        return $reachable;
    }

    /**
     * Traits utilisés directement par les classes et enums de app/ autres
     * que les modèles donnés.
     *
     * @param  list<string>  $models
     * @return list<string>
     */
    private function traitsUsedByClassesOtherThan(array $models): array
    {
        return collect($this->classLikes)
            ->reject(fn (array $classLike, string $name): bool => $classLike['isTrait'] || \in_array($name, $models, true))
            ->flatMap(fn (array $classLike): array => $classLike['traits'])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Protège les traits donnés et ceux qu'ils utilisent ; une protection
     * déjà posée garde sa raison.
     *
     * @param  list<string>  $traitClasses
     */
    private function protectTraits(array $traitClasses, ?string $reason): void
    {
        foreach (array_keys($this->reachableTraits($traitClasses)) as $traitClass) {
            if (! \array_key_exists($traitClass, $this->protectedTraits) || $reason === null) {
                $this->protectedTraits[$traitClass] = $reason;
            }
        }
    }

    private function removeUnusedTraitsInModelsDirectory(): void
    {
        $usedTraits = $this->reachableTraits([
            ...$this->ignoredTraits,
            ...$this->traitsUsedByClassesOtherThan([]),
        ]);

        $classLikesByFile = collect($this->classLikes)->groupBy('file', preserveKeys: true);

        foreach ($classLikesByFile as $file => $classLikes) {
            if (! $this->isInModelsDirectory($file)) {
                continue;
            }

            $onlyUnusedTraits = $classLikes->every(
                fn (array $classLike, string $name): bool => $classLike['isTrait'] && ! isset($usedTraits[$name]),
            );

            if ($onlyUnusedTraits) {
                $this->deleteUnusedTraitFile($file);
            }
        }
    }

    private function isInModelsDirectory(string $path): bool
    {
        $realPath = realpath($path);
        $modelsPath = realpath(app_path('Models'));

        return $realPath !== false
            && $modelsPath !== false
            && str_starts_with($realPath, $modelsPath.DIRECTORY_SEPARATOR);
    }

    private function deleteUnusedTraitFile(string $path): void
    {
        $this->deletedFiles[$path] = true;

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

    /**
     * Analyse tous les modèles avant la moindre écriture. Un modèle en erreur
     * n'est pas modifié, et ses traits sont protégés : l'analyse reprend sans
     * eux, puisque d'autres modèles peuvent les partager.
     *
     * @param  list<string>  $models
     * @return array<string, array<string, mixed>>
     */
    private function analyzeModels(array $models): array
    {
        $errors = [];

        do {
            $plans = [];
            $failedModel = null;

            foreach ($models as $modelClass) {
                if (isset($errors[$modelClass])) {
                    $plans[$modelClass] = ['error' => $errors[$modelClass]];

                    continue;
                }

                try {
                    $plans[$modelClass] = $this->analyzeModel($modelClass);
                } catch (Throwable $e) {
                    $errors[$modelClass] = $e->getMessage();
                    $failedModel = $modelClass;

                    break;
                }
            }

            if ($failedModel !== null) {
                $this->protectTraits(
                    $this->classLikes[$failedModel]['traits'] ?? [],
                    'Trait non traité car partagé avec un modèle en erreur',
                );
            }
        } while ($failedModel !== null);

        return $plans;
    }

    /**
     * Ce que la commande fera pour un modèle : traits à générer, lignes à
     * retirer des fichiers sources, traits sources à réexaminer.
     *
     * @return array<string, mixed>
     */
    private function analyzeModel(string $modelClass): array
    {
        $reflection = new ReflectionClass($modelClass);
        $modelFile = $reflection->getFileName();

        $plan = [
            'modelFile' => $modelFile ?: null,
            'generatedTraits' => [],
            'removals' => [],
            'traitFiles' => [],
            'notices' => [],
        ];

        if (! $modelFile) {
            return $plan;
        }

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

            $plan['generatedTraits'][$traitPath] = [
                'class' => $traitClass,
                'content' => $this->buildConcernTrait($concernNamespace, $traitName, $traitMethods),
            ];
        }

        $generatedClasses = array_column($plan['generatedTraits'], 'class');

        foreach ($removableMethods as $method) {
            if (\in_array($method['sourceClass'], $generatedClasses, true)) {
                continue;
            }

            $range = $this->getMethodRangeIncludingDocAndAttributes($method['method']);

            if ($range === null) {
                throw new RuntimeException('Emplacement introuvable pour la méthode '.$method['sourceClass'].'::'.$method['methodName']);
            }

            $plan['removals'][$method['sourceFile']][$range['start']] = $range['end'];
        }

        $plan['traitFiles'] = $this->collectModelTraitFiles(
            modelReflection: $reflection,
            generatedTraitFiles: array_keys($plan['generatedTraits']),
            notices: $plan['notices'],
        );

        return $plan;
    }

    private function ensureModelUsedTraitsExist(string $modelFile): void
    {
        $code = $this->originalContent($modelFile);

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

        if (\array_key_exists($traitName, $this->protectedTraits)) {
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

    /**
     * Fichiers des traits du modèle que la commande peut réécrire ou
     * supprimer : ni vendor, ni protégés, ni générés par la commande.
     *
     * @param  list<string>  $generatedTraitFiles
     * @param  list<string>  $notices
     * @return array<string, string>
     */
    private function collectModelTraitFiles(
        ReflectionClass $modelReflection,
        array $generatedTraitFiles,
        array &$notices,
    ): array {
        $traitFiles = [];
        $visitedTraits = [];
        $pendingTraits = array_values($modelReflection->getTraits());

        while ($pendingTraits !== []) {
            $trait = array_shift($pendingTraits);
            $traitName = $trait->getName();

            if (isset($visitedTraits[$traitName])) {
                continue;
            }

            $visitedTraits[$traitName] = true;
            $traitFile = $trait->getFileName();

            if ($this->isVendorFile($traitFile)) {
                continue;
            }

            if (\array_key_exists($traitName, $this->protectedTraits)) {
                if ($this->protectedTraits[$traitName] !== null) {
                    $notices[] = $this->protectedTraits[$traitName].' : '.$this->shortenPath($traitFile);
                }

                continue;
            }

            if (! \in_array($traitFile, $generatedTraitFiles, true)) {
                $traitFiles[$traitFile] = $traitName;
            }

            array_push($pendingTraits, ...array_values($trait->getTraits()));
        }

        return $traitFiles;
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

        return array_any($method->getAttributes(), fn (ReflectionAttribute $attribute): bool => $attribute->getName() === ScopeAttribute::class);
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

        if ($file === false) {
            return null;
        }

        $start = $method->getStartLine();
        $end = $method->getEndLine();

        if ($start === false || $end === false) {
            return null;
        }

        $lines = array_map(trim(...), \array_slice($this->originalLines($file), $start - 1, $end - $start + 1));
        $lines = array_filter($lines, fn (string $line): bool => ! preg_match('`^(//|/\*|\*|\*/)`', $line));

        return implode(' ', $lines);
    }

    private function getMethodOriginalCode(ReflectionMethod $method): string
    {
        $range = $this->getMethodRangeIncludingDocAndAttributes($method);

        if (! $range) {
            return '';
        }

        return implode("\n", \array_slice(
            $this->originalLines($method->getFileName()),
            $range['start'] - 1,
            $range['end'] - $range['start'] + 1,
        ));
    }

    /**
     * Lignes de la méthode, avec ses attributs et les commentaires collés
     * au-dessus d'elle (sans ligne vide entre eux), quelle que soit leur
     * forme : commentaire de ligne, bloc ou docblock.
     *
     * @return array{start: int, end: int}|null
     */
    private function getMethodRangeIncludingDocAndAttributes(ReflectionMethod $method): ?array
    {
        $file = $method->getFileName();

        if (! $file) {
            return null;
        }

        $node = $this->findMethodNode($file, $method);

        if (! $node instanceof ClassMethod) {
            return null;
        }

        // la ligne de départ du nœud inclut les attributs
        $startLine = $node->getStartLine();

        foreach (array_reverse($node->getComments()) as $comment) {
            if ($comment->getEndLine() < $startLine - 1 || ! $this->commentStartsItsLine($file, $comment)) {
                break;
            }

            $startLine = $comment->getStartLine();
        }

        return [
            'start' => $startLine,
            'end' => $node->getEndLine(),
        ];
    }

    private function findMethodNode(string $file, ReflectionMethod $method): ?ClassMethod
    {
        $node = $this->finder->findFirst(
            $this->parseFile($file),
            fn (Node $node): bool => $node instanceof ClassMethod
                && strcasecmp($node->name->toString(), $method->getName()) === 0
                && $node->getEndLine() === $method->getEndLine(),
        );

        return $node instanceof ClassMethod ? $node : null;
    }

    /**
     * Un commentaire qui suit du code sur sa ligne (fin de la méthode
     * précédente, par exemple) appartient à ce code.
     */
    private function commentStartsItsLine(string $file, Comment $comment): bool
    {
        $content = $this->originalContent($file);
        $position = $comment->getStartFilePos();
        $lineStart = strrpos(substr($content, 0, $position), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;

        return trim(substr($content, $lineStart, $position - $lineStart)) === '';
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

    private function buildConcernTrait(
        string $namespace,
        string $name,
        array $methods,
    ): string {
        foreach ($methods as $method) {
            if (trim((string) $method['code']) === '') {
                throw new RuntimeException('Code introuvable pour la méthode '.$method['sourceClass'].'::'.$method['methodName']);
            }
        }

        $uses = $this->mergeUses($methods);

        $body = collect($methods)
            ->map(fn (array $method): string => '    '.trim((string) $method['code']))
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

        return $content."}\n";
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
        if (! $file) {
            return [];
        }

        try {
            $ast = $this->parser->parse($this->originalContent($file));
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

    /**
     * Applique les plans de tous les modèles. Les retraits de méthodes sont
     * cumulés par fichier sur son contenu d'origine : un trait partagé est
     * vidé une seule fois, et chaque modèle en a reçu sa copie.
     *
     * @param  array<string, array<string, mixed>>  $plans
     */
    private function applyPlans(array $plans): void
    {
        $removals = [];
        $traitFiles = [];
        $owners = [];

        foreach ($plans as $modelClass => $plan) {
            if (isset($plan['error'])) {
                continue;
            }

            foreach ($plan['removals'] as $file => $ranges) {
                $removals[$file] = ($removals[$file] ?? []) + $ranges;
            }

            foreach ($plan['traitFiles'] as $file => $traitClass) {
                $traitFiles[$file] = $traitClass;
                $owners[$file] ??= $modelClass;
            }
        }

        $traitContents = [];

        foreach (array_keys($traitFiles) as $file) {
            $traitContents[$file] = $this->contentWithoutRanges($file, $removals[$file] ?? []);
        }

        $deletedTraits = $this->detectDeletableTraits($traitFiles, $traitContents);

        foreach ($plans as $modelClass => $plan) {
            $this->info($modelClass);

            if (isset($plan['error'])) {
                $this->error('  '.$plan['error']);

                continue;
            }

            foreach ($plan['notices'] as $notice) {
                $this->warn('  '.$notice);
            }

            foreach ($plan['generatedTraits'] as $path => $generatedTrait) {
                $this->writeFile($path, $generatedTrait['content']);
            }

            foreach ($traitFiles as $file => $traitClass) {
                if ($owners[$file] !== $modelClass) {
                    continue;
                }

                if (isset($deletedTraits[$traitClass])) {
                    $this->deleteTraitFile($file);

                    continue;
                }

                $content = $this->removeTraitUsesFromContent($traitContents[$file], $deletedTraits);

                if ($content === $this->originalContent($file)) {
                    continue;
                }

                $this->warn('  Trait conservé car il contient encore '.$this->describeRemainingMembers($content).' : '.$this->shortenPath($file));

                $this->writeFile($file, $content);
            }

            if ($plan['modelFile'] === null) {
                continue;
            }

            $generatedClasses = array_column($plan['generatedTraits'], 'class');

            $content = $this->contentWithoutRanges($plan['modelFile'], $removals[$plan['modelFile']] ?? []);
            $content = $this->removeTraitUsesFromContent($content, $deletedTraits);
            $content = $this->addUseStatementsToContent($content, $generatedClasses);
            $content = $this->addTraitUsesInsideClassContent(
                content: $content,
                traitShortNames: array_map(class_basename(...), $generatedClasses),
            );

            if ($content !== $this->originalContent($plan['modelFile'])) {
                $this->writeFile($plan['modelFile'], $content);
            }
        }
    }

    /**
     * Traits à supprimer : sans méthode, propriété ni constante, et dont les
     * traits utilisés sont eux-mêmes supprimés.
     *
     * @param  array<string, string>  $traitFiles
     * @param  array<string, string>  $traitContents
     * @return array<string, string>
     */
    private function detectDeletableTraits(array $traitFiles, array $traitContents): array
    {
        $deletedTraits = [];

        do {
            $changed = false;

            foreach ($traitFiles as $file => $traitClass) {
                if (isset($deletedTraits[$traitClass]) || ! $this->traitIsEmpty($traitContents[$file], $deletedTraits)) {
                    continue;
                }

                $deletedTraits[$traitClass] = $file;
                $changed = true;
            }
        } while ($changed);

        return $deletedTraits;
    }

    /**
     * @param  array<string, string>  $deletedTraits
     */
    private function traitIsEmpty(string $content, array $deletedTraits): bool
    {
        $trait = $this->findTraitNode($content);

        if (! $trait instanceof Trait_) {
            return false;
        }

        foreach ($trait->stmts as $stmt) {
            if ($stmt instanceof ClassMethod || $stmt instanceof Property || $stmt instanceof ClassConst) {
                return false;
            }

            if ($stmt instanceof TraitUse && array_any($stmt->traits, fn (Name $name): bool => ! isset($deletedTraits[$name->toString()]))) {
                return false;
            }
        }

        return true;
    }

    private function findTraitNode(string $content): ?Trait_
    {
        try {
            $ast = $this->resolveNames($this->parser->parse($content) ?? []);
        } catch (Error) {
            return null;
        }

        $trait = $this->finder->findFirstInstanceOf($ast, Trait_::class);

        return $trait instanceof Trait_ ? $trait : null;
    }

    private function describeRemainingMembers(string $content): string
    {
        $trait = $this->findTraitNode($content);

        if (! $trait instanceof Trait_) {
            return 'des membres';
        }

        $methods = [];
        $properties = [];
        $constants = [];
        $traits = [];

        foreach ($trait->stmts as $stmt) {
            if ($stmt instanceof ClassMethod) {
                $methods[] = $stmt->name->toString();
            } elseif ($stmt instanceof Property) {
                foreach ($stmt->props as $property) {
                    $properties[] = '$'.$property->name->toString();
                }
            } elseif ($stmt instanceof ClassConst) {
                foreach ($stmt->consts as $constant) {
                    $constants[] = $constant->name->toString();
                }
            } elseif ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $name) {
                    $traits[] = $name->getAttribute('originalName', $name)->toString();
                }
            }
        }

        $descriptions = array_filter([
            $this->describeMembers('la méthode', 'les méthodes', $methods),
            $this->describeMembers('la propriété', 'les propriétés', $properties),
            $this->describeMembers('la constante', 'les constantes', $constants),
            $this->describeMembers('le trait', 'les traits', $traits),
        ]);

        return $descriptions === [] ? 'des membres' : implode(', ', $descriptions);
    }

    /**
     * @param  list<string>  $names
     */
    private function describeMembers(string $singular, string $plural, array $names): ?string
    {
        return match (\count($names)) {
            0 => null,
            1 => $singular.' '.$names[0],
            default => $plural.' '.implode(', ', $names),
        };
    }

    /**
     * Contenu d'origine du fichier, privé des lignes données (début => fin).
     *
     * @param  array<int, int>  $ranges
     */
    private function contentWithoutRanges(string $file, array $ranges): string
    {
        $lines = $this->originalLines($file);

        krsort($ranges);

        foreach ($ranges as $start => $end) {
            array_splice($lines, $start - 1, $end - $start + 1);
        }

        return implode("\n", $lines);
    }

    /**
     * Retire les « use Trait; » des traits supprimés, quel que soit le nom
     * sous lequel le fichier les emploie (alias compris).
     *
     * @param  array<string, string>  $deletedTraits
     */
    private function removeTraitUsesFromContent(string $content, array $deletedTraits): string
    {
        if ($deletedTraits === []) {
            return $content;
        }

        try {
            $ast = $this->resolveNames($this->parser->parse($content) ?? []);
        } catch (Error) {
            return $content;
        }

        $edits = [];

        foreach ($this->finder->findInstanceOf($ast, TraitUse::class) as $traitUse) {
            $keptTraits = array_values(array_filter(
                $traitUse->traits,
                fn (Name $name): bool => ! isset($deletedTraits[$name->toString()]),
            ));

            if (\count($keptTraits) === \count($traitUse->traits)) {
                continue;
            }

            if ($keptTraits === []) {
                $edits[] = $this->statementLinesRange($content, $traitUse->getStartFilePos(), $traitUse->getEndFilePos()) + ['replacement' => ''];

                continue;
            }

            $edits[] = [
                'start' => $traitUse->traits[0]->getStartFilePos(),
                'end' => end($traitUse->traits)->getEndFilePos() + 1,
                'replacement' => implode(', ', array_map(
                    fn (Name $name): string => $name->getAttribute('originalName', $name)->toString(),
                    $keptTraits,
                )),
            ];
        }

        usort($edits, fn (array $a, array $b): int => $b['start'] <=> $a['start']);

        foreach ($edits as $edit) {
            $content = substr_replace($content, $edit['replacement'], $edit['start'], $edit['end'] - $edit['start']);
        }

        return $content;
    }

    /**
     * Étendue d'une instruction, étendue à ses lignes entières quand elle
     * est seule dessus.
     *
     * @return array{start: int, end: int}
     */
    private function statementLinesRange(string $content, int $startPosition, int $endPosition): array
    {
        $lineStart = strrpos(substr($content, 0, $startPosition), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;

        $lineEnd = strpos($content, "\n", $endPosition);
        $lineEnd = $lineEnd === false ? \strlen($content) : $lineEnd + 1;

        $aloneOnItsLines = trim(substr($content, $lineStart, $startPosition - $lineStart)) === ''
            && trim(substr($content, $endPosition + 1, $lineEnd - $endPosition - 1)) === '';

        return $aloneOnItsLines
            ? ['start' => $lineStart, 'end' => $lineEnd]
            : ['start' => $startPosition, 'end' => $endPosition + 1];
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

    private function originalContent(string $file): string
    {
        if (! isset($this->originalContents[$file])) {
            if (! File::exists($file)) {
                throw new RuntimeException('Fichier introuvable : '.$this->shortenPath($file));
            }

            $this->originalContents[$file] = File::get($file);
        }

        return $this->originalContents[$file];
    }

    /**
     * @return list<string>
     */
    private function originalLines(string $file): array
    {
        return explode("\n", $this->originalContent($file));
    }

    /**
     * Arbre syntaxique du contenu d'origine, noms résolus.
     *
     * @return array<int, Node>
     */
    private function parseFile(string $file): array
    {
        if (! isset($this->parsedFiles[$file])) {
            try {
                $this->parsedFiles[$file] = $this->resolveNames($this->parser->parse($this->originalContent($file)) ?? []);
            } catch (Error $error) {
                throw new RuntimeException('Analyse impossible de '.$this->shortenPath($file).' : '.$error->getMessage(), $error->getCode(), $error);
            }
        }

        return $this->parsedFiles[$file];
    }

    /**
     * @param  array<int, Node>  $ast
     * @return array<int, Node>
     */
    private function resolveNames(array $ast): array
    {
        return new NodeTraverser(new NameResolver(options: ['preserveOriginalNames' => true]))->traverse($ast);
    }

    private function deleteTraitFile(string $path): void
    {
        $this->deletedFiles[$path] = true;

        if ($this->option('dry-run')) {
            $this->line('  [dry-run] Supprimé : '.$this->shortenPath($path));

            return;
        }

        File::delete($path);
    }

    /**
     * Supprime les dossiers que l'exécution laisse vides ; en simulation,
     * les annonce d'après les écritures et suppressions simulées.
     */
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

        foreach (File::files($directory) as $file) {
            if (! isset($this->deletedFiles[$file->getPathname()])) {
                $isEmpty = false;
            }
        }

        foreach (array_keys($this->writtenFiles) as $writtenFile) {
            if (str_starts_with($writtenFile, $directory.DIRECTORY_SEPARATOR)) {
                $isEmpty = false;
            }
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
        $this->writtenFiles[$path] = true;

        if ($this->option('dry-run')) {
            $this->line('  [dry-run] Écrit : '.$this->shortenPath($path));

            return;
        }

        File::ensureDirectoryExists(\dirname($path));

        File::put($path, $content);
    }

    private function shortenPath(string $path): string
    {
        return (string) str($path)->after(base_path().'/');
    }
}
