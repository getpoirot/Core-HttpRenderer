<?php
use Module\HttpFoundation\Events\Listener\ListenerDispatch;


return [
    'www-theme' => [
        'route' => 'RouteMethodSegment',
        'options' => [
            'method'   => 'GET',
            'criteria' => '/p/theme/:file~.+~',
            'match_whole' => false,
        ],
        'params' => [
            ListenerDispatch::ACTIONS => \Poirot\Ioc\newInitIns( new \Poirot\Ioc\instance(
                '/module/httpfoundation/actions/FileServeAction'
                , [ 'baseDir' => __DIR__.'/../theme/www' ]
            ) ),
        ],
    ],
];