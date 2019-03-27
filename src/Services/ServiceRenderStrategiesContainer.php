<?php
namespace Module\HttpRenderer\Services;

use Poirot\Application\aSapi;
use Poirot\Ioc\Container\BuildContainer;
use Poirot\Ioc\Container\Service\aServiceContainer;

use Module\HttpRenderer\Services\RenderStrategies\PluginsOfRenderStrategy;


class ServiceRenderStrategiesContainer
    extends aServiceContainer
{
    const CONF = 'plugins';


    /**
     * @inheritdoc
     *
     * @return PluginsOfRenderStrategy
     * @throws \Exception
     */
    function newService()
    {
        $settings = $this->_getConf();

        $builder = new BuildContainer;
        $builder->with( $builder::parseWith($settings) );

        $plugins = new PluginsOfRenderStrategy($builder);
        return $plugins;
    }


    // ..

    /**
     * Get Config Values
     *
     * @return mixed|null
     * @throws \Exception
     */
    protected function _getConf()
    {
        // retrieve and cache config
        $services = $this->services();

        /** @var aSapi $config */
        $config = $services->get('/sapi');
        $config = $config->config();
        $config = $config->{\Module\HttpRenderer\Module::class}->{self::CONF};
        return $config;
    }
}
