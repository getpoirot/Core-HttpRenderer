<?php
use Module\HttpRenderer\RenderStrategy\RenderJsonStrategy;
use Module\HttpRenderer\RenderStrategy\RenderRouterStrategy;
use Module\HttpRenderer\Services\ServiceRenderStrategiesContainer;

return
[
    // Add Extra Renderer Strategies
    //
    ServiceRenderStrategiesContainer::CONF => [
        'services' => [
            'router'  => RenderRouterStrategy::class,
        ],
    ],


    // Renderer Options
    //
    /** @see \Module\HttpRenderer\Services\RenderStrategies\PluginsOfRenderStrategy */

    // Json Renderer
    // TODO put real samples
    RenderJsonStrategy::class => [
        'routes' => [
            '@cart' => [
                \Module\Shopping\RenderStrategy\JsonRenderer\CartRenderer::class,
            ],
            '@itemsResult' => [
                \Module\Shopping\RenderStrategy\JsonRenderer\ResultSet\CartItemsResultRenderHydrate::class,
            ],
        ],
        'aliases' => [
            '@cart' => [
                'main/shopping/carts/get',
                'main/shopping/carts/insert',
            ],
            '@itemsResult' => [
                'main/shopping/carts/insert',
            ],
        ],
    ],
];
