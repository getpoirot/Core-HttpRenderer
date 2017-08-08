<?php
use Module\HttpRenderer\Services\RenderStrategies\PluginsOfRenderStrategy;
use Module\HttpRenderer\Services\ServiceRenderStrategiesContainer;

return [
    'implementations' => [
        'RenderStrategies' => PluginsOfRenderStrategy::class,
    ],
    'services' => [
        'RenderStrategies' => ServiceRenderStrategiesContainer::class,
    ],
];

