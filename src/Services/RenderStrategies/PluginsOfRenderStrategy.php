<?php
namespace Module\HttpRenderer\Services\RenderStrategies;

use Module\HttpRenderer\RenderStrategy\aRenderStrategy;
use Poirot\Ioc\Container\aContainerCapped;
use Poirot\Ioc\Container\Exception\exContainerInvalidServiceType;


class PluginsOfRenderStrategy
    extends aContainerCapped
{
    /**
     * Validate Plugin Instance Object
     *
     * @param mixed $pluginInstance
     *
     * @throws \Exception
     */
    function validateService($pluginInstance)
    {
        if (! is_object($pluginInstance) )
            throw new \Exception(sprintf('Can`t resolve to (%s) Instance.', $pluginInstance));

        if (! $pluginInstance instanceof aRenderStrategy )
            throw new exContainerInvalidServiceType('Invalid Plugin Of Renderer Strategy Provided.');

    }
}
