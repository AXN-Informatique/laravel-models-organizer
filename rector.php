<?php

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Config\RectorConfig;
use RectorLaravel\Rector\Class_\UnifyModelDatesWithCastsRector;
use RectorLaravel\Rector\FuncCall\RemoveDumpDataDeadCodeRector;
use RectorLaravel\Rector\MethodCall\EloquentWhereRelationTypeHintingParameterRector;
use RectorLaravel\Rector\MethodCall\EloquentWhereTypeHintClosureParameterRector;
use RectorLaravel\Rector\MethodCall\RedirectBackToBackHelperRector;
use RectorLaravel\Rector\MethodCall\ReplaceServiceContainerCallArgRector;
use RectorLaravel\Rector\MethodCall\ReverseConditionableMethodCallRector;
use RectorLaravel\Rector\MethodCall\ValidationRuleArrayStringValueToArrayRector;
use RectorLaravel\Rector\PropertyFetch\OptionalToNullsafeOperatorRector;
use RectorLaravel\Rector\StaticCall\RouteActionCallableRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withImportNames()
    ->withParallel(
        timeoutSeconds: 320,
        maxNumberOfProcess: 16,
        jobSize: 20,
    )
    ->withCache(
        cacheClass: FileCacheStorage::class,
        cacheDirectory: __DIR__.'/.rector_cache',
    )
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/src',
    ])
    ->withPhpSets()
    ->withRules([
        EloquentWhereRelationTypeHintingParameterRector::class,
        EloquentWhereTypeHintClosureParameterRector::class,
        OptionalToNullsafeOperatorRector::class,
        RemoveDumpDataDeadCodeRector::class,
        RedirectBackToBackHelperRector::class,
        ReplaceServiceContainerCallArgRector::class,
        ReverseConditionableMethodCallRector::class,
        RouteActionCallableRector::class,
        UnifyModelDatesWithCastsRector::class,
        ValidationRuleArrayStringValueToArrayRector::class,
    ])
    ->withSets([
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,

        typeDeclarationDocblocks: false,
        privatization: false,
        naming: false,
        namedArgs: false,
        carbon: false,
        rectorPreset: false,
        phpunitCodeQuality: false,
        phpunitNarrowAsserts: false,
        phpunitMockToStub: false,
        doctrineCodeQuality: false,
        symfonyCodeQuality: false,
        symfonyConfigs: false,
    );
