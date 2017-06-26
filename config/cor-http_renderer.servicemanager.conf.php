<?php

use Module\HttpRenderer\Services\RenderStrategy\aListenerRenderStrategy;
use Module\HttpRenderer\Services\ServiceRenderStrategy;


return [
    'implementations' => [
        'RenderStrategy' => aListenerRenderStrategy::class,
    ],
    'services' => [
        'RenderStrategy' => ServiceRenderStrategy::class,
    ],
];
