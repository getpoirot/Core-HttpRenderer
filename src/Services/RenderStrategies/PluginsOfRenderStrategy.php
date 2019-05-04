<?php
namespace Module\HttpRenderer\Services\RenderStrategies;

use Module\HttpRenderer\Interfaces\iRenderStrategy;
use Poirot\Events\Interfaces\iCorrelatedEvent;
use Poirot\Events\Interfaces\iEvent;
use Poirot\Ioc\Container\aContainerCapped;
use Poirot\Ioc\Container\BuildContainer;
use Poirot\Ioc\Container\Exception\exContainerInvalidServiceType;
use Poirot\Ioc\Container\Service\ServicePluginLoader;
use Poirot\Loader\LoaderMapResource;

use Module\HttpRenderer\RenderStrategy\aRenderStrategy;
use Module\HttpRenderer\RenderStrategy\RenderDefaultStrategy;
use Module\HttpRenderer\RenderStrategy\RenderJsonStrategy;


class PluginsOfRenderStrategy
    extends aContainerCapped
    implements iCorrelatedEvent
{
    const VIEW      = 'view';
    const JSON      = 'json';

    protected $_map_resolver_options = [
        self::VIEW => RenderDefaultStrategy::class,
        self::JSON => RenderJsonStrategy::class,
    ];


    /**
     * @inheritdoc
     */
    function __construct(BuildContainer $cBuilder = null)
    {
        // Keep Lines Priority, It`s Mandatory
        $this->_setDefaultContainerServices();

        parent::__construct($cBuilder);
    }


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

        if (! $pluginInstance instanceof iRenderStrategy )
            throw new exContainerInvalidServiceType('Invalid Plugin Of Renderer Strategy Provided.');
    }

    /**
     * @override Add default services that is transparent from service loader
     * @inheritdoc
     */
    function listServices()
    {
        return array_merge(
            parent::listServices()
            , array_keys($this->_map_resolver_options)
        );
    }


    // Implement iCorrelatedEvent

    /**
     * Attach Registered Renderer To (Sapi) Event Heap
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


    // ..

    /**
     * Set Default Plugins into Container
     *
     * @throws \Exception
     */
    protected function _setDefaultContainerServices()
    {
        ## Default Services
        #
        $service = new ServicePluginLoader([
            'resolver_options' => [
                LoaderMapResource::class => $this->_map_resolver_options
            ],
        ]);

        $this->set($service);


        ## Config Settings For Services and Initializers
        #
        $this->initializer()
            ->addCallable(function($service){
                $this->_injectSettingsFromMergedConfig($service);
            });
    }

    /**
     * Build renderer with merged configs that are available
     *
     * @param aRenderStrategy $service
     *
     * @throws \Exception
     */
    protected function _injectSettingsFromMergedConfig($service)
    {
        if (! $service instanceof RenderJsonStrategy )
            // Nothing to do with other service instances
            return;


        $config = \Poirot\config(\Module\HttpRenderer\Module::class, get_class($service));
        $service->with($service::parseWith($config));
    }
}
