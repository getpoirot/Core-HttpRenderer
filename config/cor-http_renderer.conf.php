<?php
use Module\HttpRenderer\RenderStrategy\RenderDefaultStrategy;
use Module\HttpRenderer\RenderStrategy\RenderJsonStrategy;
use Module\HttpRenderer\RenderStrategy\RenderRouterStrategy;
use Module\HttpRenderer\Services\ServiceRenderStrategiesContainer;

return
[
    ServiceRenderStrategiesContainer::CONF => [
        'services' => [
            'router'  => RenderRouterStrategy::class,
        ],
    ],

    // View Renderer Options
    RenderDefaultStrategy::class => [
        'themes' => [
            'default' => [
                'dir' => __DIR__.'/../theme',
                // (bool) func()
                // function will instantiated for resolve arguments
                // or true|false
                'when' => true, // always use this template
                'priority' => -1000,
                'layout' => [
                    'default' => 'default',
                    'exception' => [
                        ## full name of class exception

                        ## use null on second index cause view template render as final layout
                        // 'Exception' => ['error/error', null],
                        // 'Specific\Error\Exception' => ['error/spec', 'override_layout_name_here']

                        ## here (blank) is defined as default layout for all error pages
                        'Exception' => ['error/error', 'blank'],
                        \Poirot\Application\Exception\exRouteNotMatch::class => 'error/404',
                    ],
                ],
            ],
        ],
    ],

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
