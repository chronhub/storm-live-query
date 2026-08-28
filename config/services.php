<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/*
 * LiveQuery package wiring.
 *
 * autowire + autoconfigure so the console commands get the `console.command` tag and autowire their
 * deps: StreamReader, DBAL Connection, EventTypeMapper, RecipeRegistry. Every class implementing
 * LiveQueryRecipe is tagged `storm.live_query_recipe` via instanceof so the RecipeRegistry collects it.
 *
 * Excluded as non-services: the query filters + JsonCondition, value objects built per call; the output
 * renderers, instantiated by the command; the RecipeParam and RenderPlan value objects; interfaces,
 * auto-skipped; config; tests.
 */

use Storm\LiveQuery\Recipe\LiveQueryRecipe;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->instanceof(LiveQueryRecipe::class)->tag('storm.live_query_recipe');

    $services->load('Storm\\LiveQuery\\', dirname(__DIR__).'/')
        ->exclude([
            dirname(__DIR__).'/Filter/',
            dirname(__DIR__).'/Output/',
            dirname(__DIR__).'/Recipe/RecipeParam.php',
            dirname(__DIR__).'/Console/RenderPlan.php',
            dirname(__DIR__).'/config/',
            dirname(__DIR__).'/Tests/',
        ]);
};
