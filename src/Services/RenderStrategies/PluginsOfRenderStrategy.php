<?php
namespace Module\HttpRenderer\Services\RenderStrategies;

use Module\HttpRenderer\RenderStrategy\aRenderStrategy;
use Poirot\Events\Interfaces\iCorrelatedEvent;
use Poirot\Events\Interfaces\iEvent;
use Poirot\Ioc\Container\aContainerCapped;
use Poirot\Ioc\Container\Exception\exContainerInvalidServiceType;


class PluginsOfRenderStrategy
    extends aContainerCapped
    implements iCorrelatedEvent
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


    // Implement iCorrelatedEvent

    /**
     * Attach To Event
     *
     * @param iEvent $event
     *
     * @return $this
     */
    function attachToEvent(iEvent $event)
    {
        foreach ( $this->listServices() as $strategyName ) {
            $strategy = $this->get($strategyName);
            $strategy->attachToEvent($event);
        }

        return $this;
    }
}
