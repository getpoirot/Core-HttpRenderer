<?php
namespace Module\HttpRenderer\Services;

use Module\HttpRenderer\Services\RenderStrategies\PluginsOfRenderStrategy;
use Poirot\Application\aSapi;
use Poirot\Ioc\Container\BuildContainer;
use Poirot\Ioc\Container\Service\aServiceContainer;
use Poirot\Std\Struct\DataEntity;


class ServiceRenderStrategiesContainer
    extends aServiceContainer
{
    const CONF = 'plugins';


    /**
     * Create Service
     *
     * @return mixed
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
        /** @var DataEntity $config */
        $config = $config->get( \Module\HttpRenderer\Module::CONF, array() );
        $config = @$config[self::CONF];
        return $config;
    }
}
