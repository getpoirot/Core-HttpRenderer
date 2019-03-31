<?php
namespace Module\HttpRenderer\Services;

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
        $settings = \Poirot\config(\Module\HttpRenderer\Module::class, self::CONF);

        $builder = new BuildContainer;
        $builder->with( $builder::parseWith($settings) );

        $plugins = new PluginsOfRenderStrategy($builder);
        return $plugins;
    }
}
