<?php
namespace Module\HttpRenderer\Services\RenderStrategy;

use Poirot\Events\Interfaces\iCorrelatedEvent;
use Poirot\Ioc\Container;
use Poirot\Ioc\Interfaces\iContainer;
use Poirot\Ioc\Interfaces\Respec\iServicesAware;
use Poirot\Std\ConfigurableSetter;


abstract class aListenerRenderStrategy
    extends ConfigurableSetter
    implements iCorrelatedEvent
    , iServicesAware
{
    const APP_ERROR_HANDLE_RENDERER_PRIORITY = -1000;

    /** @var Container */
    protected $sc;


    /**
     * Get Content Type That Renderer Will Provide
     * exp. application/json; text/html
     *
     * @return string
     */
    abstract function getContentType();


    // Implement iCServiceAware

    /**
     * Set Service Container
     *
     * @param iContainer $container
     */
    function setServices(iContainer $container)
    {
        $this->sc = $container;
    }
}
