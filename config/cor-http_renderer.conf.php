<?php
use Module\HttpRenderer\Services\RenderStrategy\DefaultStrategy\ListenerError;
use Module\HttpRenderer\Services\RenderStrategy\ListenersRenderDefaultStrategy;

return [
    \Module\Foundation\Services\PathService::CONF => [
        'paths' => [
            // According to route name 'www-assets' to serve statics files
            // @see cor-http_foundation.routes
            'www-theme' => "\$baseUrl/p/theme/",
        ],
        'variables' => [
            'serverUrl' => function() { return \Module\HttpFoundation\getServerUrl(); },
            'basePath'  => function() { return \Module\HttpFoundation\getBasePath(); },
            'baseUrl'   => function() { return \Module\HttpFoundation\getBaseUrl(); },
        ],
    ],

    // View Renderer Options
    ListenersRenderDefaultStrategy::CONF_KEY => [
        'default_layout' => 'default',

        ListenerError::CONF_KEY => [
            ## full name of class exception

            ## use null on second index cause view template render as final layout
            // 'Exception' => ['error/error', null],
            // 'Specific\Error\Exception' => ['error/spec', 'override_layout_name_here']

            ## here (blank) is defined as default layout for all error pages
            'Exception' => ['error/error', 'blank'],
            'Poirot\Application\Exception\exRouteNotMatch' => 'error/404',
        ],
    ],
];
