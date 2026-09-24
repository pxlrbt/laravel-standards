<?php

namespace Pxlrbt\LaravelStandards;

use Pest\Rector\Rules\ChainExpectCallsRector;
use Pest\Rector\Rules\ConvertAndToExpectRector;
use Pest\Rector\Set\PestSetList;
use Rector\CodingStyle\Rector\Enum_\EnumCaseToPascalCaseRector;
use Rector\Config\RectorConfig;
use Rector\Configuration\RectorConfigBuilder;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Set\ValueObject\SetList;
use Rector\Symfony\CodeQuality\Rector\ClassMethod\ResponseReturnTypeControllerActionRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromReturnDirectArrayRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictTypedCallRector;
use Rector\TypeDeclaration\Rector\Closure\AddClosureVoidReturnTypeWhereNoReturnRector;
use Rector\TypeDeclaration\Rector\Closure\ClosureReturnTypeRector;
use RectorLaravel\Rector\FuncCall\TypeHintTappableCallRector;
use RectorLaravel\Rector\MethodCall\AssertStatusToAssertMethodRector;
use RectorLaravel\Rector\MethodCall\EloquentWhereRelationTypeHintingParameterRector;
use RectorLaravel\Rector\MethodCall\EloquentWhereTypeHintClosureParameterRector;
use RectorLaravel\Rector\PropertyFetch\OptionalToNullsafeOperatorRector;
use RectorLaravel\Rector\StaticCall\AssertWithClassStringToTypeHintedClosureRector;
use RectorLaravel\Rector\StaticCall\CarbonToDateFacadeRector;
use RectorLaravel\Rector\StaticCall\RouteActionCallableRector;
use RectorLaravel\Set\LaravelSetList;

class Standards
{
    public static function rector(string $basePath): RectorConfigBuilder
    {
        $config = RectorConfig::configure()
            ->withPaths(array_filter(
                array_map(
                    fn (string $directory): string => $basePath.'/'.$directory,
                    ['app', 'bootstrap', 'config', 'database', 'lang', 'routes', 'resources', 'tests'],
                ),
                is_dir(...),
            ))
            ->withSkip([
                $basePath.'/bootstrap/cache',
                ClosureToArrowFunctionRector::class,
                AddOverrideAttributeToOverriddenMethodsRector::class,
                ResponseReturnTypeControllerActionRector::class,
                AddClosureVoidReturnTypeWhereNoReturnRector::class,
                AddArrowFunctionReturnTypeRector::class,
                ClosureReturnTypeRector::class,
                CarbonToDateFacadeRector::class,
                ReturnTypeFromStrictTypedCallRector::class => [$basePath.'/app/Http/Controllers'],
                ReturnTypeFromReturnDirectArrayRector::class => [$basePath.'/app/Http/Controllers'],
            ])
            ->withPhpSets()
            ->withSets([
                SetList::EARLY_RETURN,
                SetList::CARBON,
                PHPUnitSetList::PHPUNIT_CODE_QUALITY,
                LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
                LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
                LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
                LaravelSetList::LARAVEL_ELOQUENT_MAGIC_METHOD_TO_QUERY_BUILDER,
                LaravelSetList::LARAVEL_IF_HELPERS,
                LaravelSetList::LARAVEL_CODE_QUALITY,
            ])
            ->withRules([
                RouteActionCallableRector::class,
                EnumCaseToPascalCaseRector::class,
                EloquentWhereRelationTypeHintingParameterRector::class,
                EloquentWhereTypeHintClosureParameterRector::class,
                TypeHintTappableCallRector::class,
                OptionalToNullsafeOperatorRector::class,
                AssertWithClassStringToTypeHintedClosureRector::class,
                AssertStatusToAssertMethodRector::class,
            ])
            ->withComposerBased(laravel: true, phpunit: true);

        if (! class_exists(PestSetList::class)) {
            return $config;
        }

        return $config
            ->withSets([PestSetList::CODING_STYLE])
            ->withConfiguredRule(ChainExpectCallsRector::class, ['merge_different_variables' => false])
            ->withRules([ConvertAndToExpectRector::class]);
    }
}
