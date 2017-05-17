<?php

return [
    'implementations' => [
        'RenderStrategy' => \Module\HttpRenderer\Services\RenderStrategy\aListenerRenderStrategy::class,
    ],
    'services' => [
        \Module\HttpRenderer\Services\ServiceRenderStrategy::class,
    ],
];
