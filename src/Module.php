<?php
namespace Module\HttpRenderer;

use Poirot\Application\Interfaces\Sapi\iSapiModule;
use Poirot\Application\aSapi;
use Poirot\Application\Interfaces\Sapi;
use Poirot\Application\ModuleManager\Interfaces\iModuleManager;
use Poirot\Application\Sapi\Event\EventHeapOfSapi;

use Poirot\Ioc\Container;

use Poirot\Ioc\Container\BuildContainer;
use Poirot\Loader\Autoloader\LoaderAutoloadAggregate;
use Poirot\Loader\Autoloader\LoaderAutoloadNamespace;
use Poirot\Loader\LoaderAggregate;
use Poirot\Loader\LoaderNamespaceStack;

use Poirot\Router\BuildRouterStack;
use Poirot\Router\Interfaces\iRouterStack;
use Poirot\Std\Interfaces\Struct\iDataEntity;

use Module\Foundation\Services\PathService\PathAction;
use Module\HttpRenderer\Services\RenderStrategies\PluginsOfRenderStrategy;


/**
 * // TODO remove unnecessary assets from theme folder
 * // TODO define some sample pages + using json renderer hydration samples
 *
 * - Provide Render Strategies To Represent Dispatch Result.
 *   include html and json render strategy,
 *   html render strategy has become with extensible template engine mechanism.
 *
 *   include default bootstrap theme.
 *
 *   @see ServiceRenderStrategy
 *
 *
 * - With defined route name "www-theme" as fileServe all static file
 *   from within theme/www are accessible.
 *
 *   also define a static path "www-theme" point to this url.
 *
 *   @see cor-http_renderer.routes.conf.php
 */
class Module implements iSapiModule
    , Sapi\Module\Feature\iFeatureModuleInitSapi
    , Sapi\Module\Feature\iFeatureModuleAutoload
    , Sapi\Module\Feature\iFeatureModuleMergeConfig
    , Sapi\Module\Feature\iFeatureModuleInitModuleManager
    , Sapi\Module\Feature\iFeatureModuleNestServices
    , Sapi\Module\Feature\iFeatureModuleInitSapiEvents
    , Sapi\Module\Feature\iFeatureOnPostLoadModulesGrabServices
{
    const CONF = 'module.http-renderer';


    /**
     * @inheritdoc
     */
    function initialize($sapi)
    {
        if ( \Poirot\isCommandLine( $sapi->getSapiName() ) )
            // Sapi Is Not HTTP. SKIP Module Load!!
            return false;
    }

    /**
     * @inheritdoc
     */
    function initAutoload(LoaderAutoloadAggregate $baseAutoloader)
    {
        $nameSpaceLoader = \Poirot\Loader\Autoloader\LoaderAutoloadNamespace::class;
        /** @var LoaderAutoloadNamespace $nameSpaceLoader */
        $nameSpaceLoader = $baseAutoloader->loader($nameSpaceLoader);
        $nameSpaceLoader->addResource(__NAMESPACE__, __DIR__);

    }

    /**
     * @inheritdoc
     */
    function initModuleManager(iModuleManager $moduleManager)
    {
        // Module Is Required.
        if (! $moduleManager->hasLoaded('HttpFoundation') )
            $moduleManager->loadModule('HttpFoundation');

    }

    /**
     * @inheritdoc
     */
    function initConfig(iDataEntity $config)
    {
        return \Poirot\Config\load(__DIR__ . '/../config/cor-http_renderer');
    }

    /**
     * @inheritdoc
     */
    function getServices(Container $moduleContainer = null)
    {
        $conf    = include __DIR__ . '/../config/cor-http_renderer.services.conf.php';

        $builder = new BuildContainer;
        $builder->with($builder::parseWith($conf));
        return $builder;
    }

    /**
     * @inheritdoc
     */
    function initSapiEvents(EventHeapOfSapi $events)
    {
        /** @var aSapi $sapi */
        $sapi     = $events->collector()->getSapi();
        $services = $sapi->services();

        // Attach Renderer To Application Events; Lets Strategies Rules ...
        /** @var PluginsOfRenderStrategy $renderStrategies */
        $renderStrategies = $services->get('/module/httpRenderer/services/RenderStrategies');
        $renderStrategies->attachToEvent($events);
    }

    /**
     * @inheritdoc
     *
     * @param LoaderAggregate $viewModelResolver
     * @param iRouterStack    $router
     * @param PathAction      $path              @IoC /module/foundation/services/Path
     *
     * @throws \Exception
     */
    function resolveRegisteredServices(
        $viewModelResolver = null
        , $router = null
        , PathAction $path = null
    ) {
        ## Register Routes:
        #
        $this->_setupHttpRouter($router);


        # Attach Module Scripts To View Resolver:
        #
        /** @var LoaderNamespaceStack $resolver */
        $resolver = $viewModelResolver->loader(LoaderNamespaceStack::class);
        $resolver->with([
            'main/'    => __DIR__. '/../view/main/',
            'partial/' => __DIR__.'/../view/partial',
            'error/'   => __DIR__.'/../view/error',
        ]);

        ## Register Paths and Variables:
        #
        if ($path)
        {
            // According to route name 'www-theme' to serve statics files
            // @see cor-http_renderer.routes
            $path->setPath('www-theme', "\$baseUrl/p/theme/");
        }
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
