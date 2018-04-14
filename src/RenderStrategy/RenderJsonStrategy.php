<?php
namespace Module\HttpRenderer\RenderStrategy;

use Module\HttpFoundation\Events\Listener\ListenerDispatch;
use Poirot\Application\aSapi;
use Poirot\Application\Sapi\Event\EventError;
use Poirot\Events\Interfaces\iEvent;

use Poirot\Http\Header\FactoryHttpHeader;
use Poirot\Http\HttpMessage\Response\Plugin\Status;

use Poirot\Http\Interfaces\iHttpRequest;
use Poirot\Http\Interfaces\iHttpResponse;
use Poirot\Ioc\Container;

use Poirot\Application\Sapi\Event\EventHeapOfSapi;
use Poirot\Ioc\instance;
use Poirot\Router\Interfaces\iRouterStack;
use Poirot\Std\Environment\EnvServerDefault;
use Poirot\Std\Struct\aDataAbstract;
use Poirot\Std\Struct\DataEntity;
use Poirot\Std\Type\StdTravers;
use ReflectionClass;


/**
 *
 * @see doAttachDefaultEvents::_attachDefaultEvents
 */
class RenderJsonStrategy
    extends aRenderStrategy
{
    const CONF_KEY = 'json_renderer';

    /** @var Container */
    protected $sc;
    protected $request;

    protected $canHandle;


    /**
     * aAction constructor.
     * @param iHttpRequest $httpRequest @IoC /HttpRequest
     */
    function __construct(iHttpRequest $httpRequest)
    {
        $this->request = $httpRequest;
    }


    // Implement Setter/Getter
    
    /**
     * Initialize To Events
     *
     * - usually bind listener(s) to events
     *
     * @param EventHeapOfSapi|iEvent $events
     *
     * @return $this
     */
    function attachToEvent(iEvent $events)
    {
        $self = $this;
        $events
            ->on(
                EventHeapOfSapi::EVENT_APP_RENDER
                , function ($result = null, $sapi = null, $route_match = null) use ($self) {
                    return $self->createResponseFromResult($result, $sapi, $route_match);
                }
                , 100
            )
            ->on(
                EventHeapOfSapi::EVENT_APP_ERROR
                , function ($exception = null, $e = null, $sapi = null) use ($self) {
                    return $self->handleErrorRender($exception, $e, $sapi);
                }
                , self::APP_ERROR_HANDLE_RENDERER_PRIORITY + 100
            )
        ;
        
        return $this;
    }

    /**
     * Create ViewModel From Actions Result
     *
     * priority -10
     *
     * @param mixed $result Result from dispatch action
     * @param aSapi $sapi
     *
     * @return array|void
     */
    protected function createResponseFromResult($result = null, $sapi = null, $route_match = null)
    {
        if (! $this->canHandle() )
            return null;

        if ( $result instanceof iHttpResponse )
            // Response Prepared; Do Nothing.
            return null;

        if (! (is_array($result) || $result instanceof \Traversable) )
            // Result Can`t Handle With Json Renderer!
            return null;


        ## Handle Result Registered Hydration
        #
        if ( is_array($result) )
            $result = new \ArrayIterator($result);

        if ( $result instanceof \Traversable )
            $result = StdTravers::of($result)->toArray(null, true);


        $result = $this->_handleResultHydration($result, $route_match);



        ## Build Response
        #
        if ( $result instanceof \Traversable )
            $result = StdTravers::of($result)->toArray(null, true);


        $result = [
            'status' => 'OK',
            'result' => $result
        ];

        $content  = json_encode($result);
        $response = $sapi->services()->get('HttpResponse');
        $response->headers()->insert(
            FactoryHttpHeader::of(array('Content-Type' => 'application/json')) );
        $response->setBody($content);

        return array(ListenerDispatch::RESULT_DISPATCH => $response);
    }


    /**
     * note: the result param from this will then pass
     *       to render event by sapi application
     *      @see self::createResponseFromResult()
     * 
     * @param \Exception $exception
     * @param EventError $event
     * @param aSapi      $sapi
     *
     * @return array
     */
    protected function handleErrorRender($exception = null, $event = null, $sapi = null)
    {
        if (! $this->canHandle() )
            // Do Nothing;
            return null;

        if (! $exception instanceof \Exception )
            ## unknown error
            return;


        // TODO Response Aware Exception; map exception code to response code aware and more; include headers
        $exception_code = $exception->getCode();

        $exRef = new ReflectionClass($exception);
        $result = array(
            'status' => 'ERROR',
            'error'  => array(
                'state'   => $exRef->getShortName(),
                'code'    => $exception_code,
                'message' => $exception->getMessage(),
            ),
        );

        $isAllowDisplayExceptions = new EnvServerDefault();
        $isAllowDisplayExceptions = $isAllowDisplayExceptions->getErrorReporting();
        if ($isAllowDisplayExceptions) {
            do {
                $result = array_merge_recursive($result, array(
                    'error' => array(
                        '_debug_' => array(
                            'exception' => array(
                                array(
                                    'message' => $exception->getMessage(),
                                    'class'   => get_class($exception),
                                    'file'    => $exception->getFile(),
                                    'line'    => $exception->getLine(),
                                ),
                            ),
                        ),
                    ),
                ));
            } while ($exception = $exception->getPrevious());
        }

        $content  = json_encode($result);
        /** @var iHttpResponse $response */
        $response = $sapi->services()->get('HttpResponse');
        if (Status::_($response)->isSuccess()) {
            if (! (is_numeric($exception_code)
                && $exception_code > 100
                && $exception_code <= 600
            ))
                $exception_code = 500;
        }

        $response->setStatusCode(($exception_code) ? $exception_code : 500);
        $response->headers()->insert(
            FactoryHttpHeader::of(array('Content-Type' => $this->getContentType())) );
        $response->setBody($content);

        return array(
            ListenerDispatch::RESULT_DISPATCH => $response,

            # disable default throw exception listener at the end
            'exception' => null, // Grab Exception and not pass to other handlers
        );
    }

    /**
     * Get Content Type That Renderer Will Provide
     * exp. application/json; text/html
     *
     * @return string
     */
    function getContentType()
    {
        return 'application/json';
    }


    // ..

    private function canHandle()
    {
        if ($this->canHandle !== null)
            return $this->canHandle;


        if ( $this->request->headers()->has('Accept') ) {
            $acceptHeader = $this->request->headers()->get('Accept');
            foreach ($acceptHeader as $h) {
                $values = $h->renderValueLine();
                if (strtolower($values) === 'application/json')
                    return $this->canHandle = true;
            }
        }
    }

    /**
     * Handle Hydrate Chain Of Result
     *
     * @param \Traversable|array $result
     * @param iRouterStack       $routeMatch
     *
     *
     * @return array|\Traversable
     */
    private function _handleResultHydration($result, $routeMatch)
    {
        ## Get Hydrator if has registered
        #
        $routeName = ($routeMatch) ? $routeMatch->getName() : null;

        if (null === $confHydration = $this->_getConf('routes', $routeName) )
            // looking for aliases hydration
            $confHydration = $this->_getFromAliases($routeName);

        if ( null === $confHydration )
            // No Hydration Registered With Merged Configs
            return $result;


        ## Chain Hydrations
        #
        while ( $hydrator = array_shift($confHydration) )
        {
            if (! is_object($hydrator) )
                $hydrator = \Poirot\Ioc\newInitIns(new instance($hydrator));


            if (! $hydrator instanceof aDataAbstract )
                throw new \RuntimeException(sprintf(
                    'Hydrator Invalid For Route (%s), given: (%s).'
                    , $routeName, get_class($hydrator)
                ));


            $result = $hydrator->import($result);
        }


        return $result;
    }

    /**
     * Get Config Values
     *
     * Argument can passed and map to config if exists [$key][$_][$__] ..
     *
     * @param $key
     * @param null $_
     *
     * @return mixed|null
     * @throws \Exception
     */
    protected function _getConf($key = null, $_ = null)
    {
        // retrieve and cache config
        $services = $this->sc;

        /** @var aSapi $config */
        $config = $services->get('/sapi');
        $config = $config->config();
        /** @var DataEntity $config */
        $config = $config->get( self::CONF_KEY, [] );
        foreach (func_get_args() as $key) {
            if (! isset($config[$key]) )
                return null;

            $config = $config[$key];
        }

        return $config;
    }

    private function _getFromAliases($routeName)
    {
        if (null === $confAliases = $this->_getConf('aliases') )
            return null;


        foreach ($confAliases as $routeAlias => $routes) {
            if (in_array($routeName, $routes)) {
                $confRoutes = $this->_getConf('routes', $routeAlias);
                return $confRoutes;
            }
        }
    }
}
