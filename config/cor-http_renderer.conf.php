<?php
use Module\Foundation\ServiceManager\ServiceViewModelResolver;
use Module\Foundation\Services\PathService;
use Module\HttpRenderer\Services\RenderStrategy\DefaultStrategy\ListenerError;
use Module\HttpRenderer\Services\RenderStrategy\ListenersRenderDefaultStrategy;

// TODO Themes Folder Define Within Module as Setting
// TODO by this considered that themes always exists within www that seems ok when not using asset-manager
$themesFolder = trim(str_replace(PT_DIR_WWW, '', PT_DIR_THEME_DEFAULT), '/');

return [
    // Path Helper Action Options
    PathService::CONF => [
        'paths' => [
            'www-assets' => "\$baseUrl/{$themesFolder}/www",
        ],
        'variables' => [
            // force base url value; but still detect from within path service
            # 'baseUrl' => ($baseurl = getenv('PT_BASEURL')) ? $baseurl : null,
        ],
    ],

    ServiceViewModelResolver::CONF => [
        'Poirot\Loader\LoaderNamespaceStack' => [
            // Use Default Theme Folder To Achieve Views With Force First ("**")
            '**' => PT_DIR_THEME_DEFAULT,
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
