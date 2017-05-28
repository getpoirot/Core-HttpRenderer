<?php
namespace Module\HttpRenderer;

use Poirot\Application\Interfaces\iApplication;
use Poirot\Application\Interfaces\Sapi\iSapiModule;
use Poirot\Application\aSapi;
use Poirot\Application\Interfaces\Sapi;
use Poirot\Application\ModuleManager\Interfaces\iModuleManager;
use Poirot\Application\Sapi\Event\EventHeapOfSapi;

use Poirot\Ioc\Container;

use Poirot\Loader\Autoloader\LoaderAutoloadAggregate;
use Poirot\Loader\Autoloader\LoaderAutoloadNamespace;
use Poirot\Loader\Interfaces\iLoaderAutoload;
use Poirot\Loader\LoaderAggregate;
use Poirot\Loader\LoaderNamespaceStack;

use Poirot\Router\BuildRouterStack;
use Poirot\Router\Interfaces\iRouterStack;
use Poirot\Std\Interfaces\Struct\iDataEntity;


class Module implements iSapiModule
    , Sapi\Module\Feature\iFeatureModuleInitSapi
    , Sapi\Module\Feature\iFeatureModuleAutoload
    , Sapi\Module\Feature\iFeatureModuleMergeConfig
    , Sapi\Module\Feature\iFeatureModuleInitServices
    , Sapi\Module\Feature\iFeatureModuleInitModuleManager
    , Sapi\Module\Feature\iFeatureModuleInitSapiEvents
    , Sapi\Module\Feature\iFeatureOnPostLoadModulesGrabServices
{
    /**
     * Init Module Against Application
     *
     * - determine sapi server, cli or http
     *
     * priority: 1000 A
     *
     * @param iApplication|aSapi $sapi Application Instance
     *
     * @return false|null False mean not setup with other module features (skip module)
     * @throws \Exception
     */
    function initialize($sapi)
    {
        if ( \Poirot\isCommandLine( $sapi->getSapiName() ) )
            // Sapi Is Not HTTP. SKIP Module Load!!
            return false;
    }

    /**
     * Register class autoload on Autoload
     *
     * priority: 1000 B
     *
     * @param LoaderAutoloadAggregate $baseAutoloader
     *
     * @return iLoaderAutoload|array|\Traversable|void
     */
    function initAutoload(LoaderAutoloadAggregate $baseAutoloader)
    {
        #$nameSpaceLoader = \Poirot\Loader\Autoloader\LoaderAutoloadNamespace::class;
        $nameSpaceLoader = 'Poirot\Loader\Autoloader\LoaderAutoloadNamespace';
        /** @var LoaderAutoloadNamespace $nameSpaceLoader */
        $nameSpaceLoader = $baseAutoloader->loader($nameSpaceLoader);
        $nameSpaceLoader->addResource(__NAMESPACE__, __DIR__);

    }

    /**
     * Initialize Module Manager
     *
     * priority: 1000 C
     *
     * @param iModuleManager $moduleManager
     *
     * @return void
     */
    function initModuleManager(iModuleManager $moduleManager)
    {
        // ( ! ) ORDER IS MANDATORY

        if (! $moduleManager->hasLoaded('HttpFoundation') )
            // Module Is Required.
            $moduleManager->loadModule('HttpFoundation');

    }

    /**
     * Register config key/value
     *
     * priority: 1000 D
     *
     * - you may return an array or Traversable
     *   that would be merge with config current data
     *
     * @param iDataEntity $config
     *
     * @return array|\Traversable
     */
    function initConfig(iDataEntity $config)
    {
        return \Poirot\Config\load(__DIR__ . '/../config/cor-http_renderer');
    }

    /**
     * Build Service Container
     *
     * priority: 1000 X
     *
     * - register services
     * - define aliases
     * - add initializers
     * - ...
     *
     * @param Container $services
     *
     * @return array|\Traversable|void Container Builder Config
     */
    function initServiceManager(Container $services)
    {
        return \Poirot\Config\load(__DIR__ . '/../config/cor-http_renderer.servicemanager');
    }

    /**
     * Attach Listeners To Application Events
     * @see ApplicationEvents
     *
     * priority: Just Before Dispatch Request When All Modules Loaded
     *           Completely
     *
     * @param EventHeapOfSapi $events
     *
     * @return void
     */
    function initSapiEvents(EventHeapOfSapi $events)
    {
        // EVENT: Render Dispatch Result .................................................

        # achieve viewModel
        /** @var aSapi $sapi */
        $sapi     = $events->collector()->getSapi();
        $services = $sapi->services();

        $renderStrategy = $services->get('RenderStrategy');
        $renderStrategy->attachToEvent($events);
    }

    /**
     * Resolve to service with name
     *
     * - each argument represent requested service by registered name
     *   if service not available default argument value remains
     * - "services" as argument will retrieve services container itself.
     *
     * ! after all modules loaded
     *
     * @param LoaderAggregate  $viewModelResolver
     *
     * @internal param null $services service names must have default value
     */
    function resolveRegisteredServices($viewModelResolver = null, $router = null)
    {
        # Register Routes:
        $this->_setupHttpRouter($router);

        # Attach Module Scripts To View Resolver:

        // But We May Need Template Rendering Even In API Calls
        /** @var LoaderNamespaceStack $resolver */
        $resolver = $viewModelResolver->loader(LoaderNamespaceStack::class);
        $resolver->with([
            // Use Default Theme Folder To Achieve Views With Force First ("**")
            '**'       => __DIR__.'/../theme',
            'main/'    => __DIR__. '/../view/main/',
            'partial/' => __DIR__.'/../view/partial',
            'error/'   => __DIR__.'/../view/error',
        ]);
    }


    // ..

    /**
     * Setup Http Stack Router
     *
     * @param iRouterStack $router
     *
     * @return void
     */
    protected function _setupHttpRouter(iRouterStack $router)
    {
        $routes = include __DIR__ . '/../config/cor-http_renderer.routes.conf.php';
        $buildRoute = new BuildRouterStack();
        $buildRoute->setRoutes($routes);

        $buildRoute->build($router);
    }
}
