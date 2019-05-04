<?php
namespace Module\HttpRenderer\RenderStrategy;

use Module\HttpFoundation\Events\Listener\ListenerDispatchResult;
use Module\HttpRenderer\Exceptions\ResultNotRenderableError;
use function Poirot\Http\Header\renderHeaderValue;

use Poirot\Http\HttpResponse;
use Poirot\Std\Environment\FactoryEnvironment;
use Poirot\Std\Interfaces\Pact\ipConfigurable;
use Poirot\Std\Traits\tConfigurable;
use Poirot\Std\Type\StdArray;
use Poirot\View\Interfaces\iViewModel;
use Module\HttpFoundation\Events\Listener\ListenerDispatch;
use Poirot\Events\Interfaces\iEvent;

use Poirot\Http\Header\FactoryHttpHeader;
use Poirot\Http\HttpMessage\Response\Plugin\Status;

use Poirot\Http\Interfaces\iHttpRequest;
use Poirot\Http\Interfaces\iHttpResponse;

use Poirot\Application\Sapi\Event\EventHeapOfSapi;
use Poirot\Ioc\instance;
use Poirot\Router\Interfaces\iRouterStack;
use Poirot\Std\Environment\EnvServerDefault;
use Poirot\Std\Struct\aDataAbstract;
use Poirot\Std\Type\StdTravers;


class RenderJsonStrategy
    extends aRenderStrategy
    implements ipConfigurable
{
    use tConfigurable;


    /** @var iHttpRequest */
    protected $request;
    /** @var iHttpResponse */
    protected $response;
    protected $config;


    /**
     * Construct.
     *
     * @param iHttpRequest       $request  @IoC /httpRequest
     * @param iHttpResponse|null $response @IoC /httpResponse
     */
    function __construct(iHttpRequest $request, iHttpResponse $response = null)
    {
        $this->setRequest($request);

        if (null !== $response)
            $this->setResponse($response);
    }


    /**
     * Initialize To Events
     *
     * - usually bind listener(s) to events
     *
     * @param EventHeapOfSapi|iEvent $events
     *
     * @return $this
     * @throws \Exception
     */
    function attachToEvent(iEvent $events)
    {
        $events
            ->on(
                EventHeapOfSapi::EVENT_APP_RENDER
                , function ($result = null, $route_match = null)
                {
                    if ( $this->shouldSkipRenderer() || !$this->isRenderable($result) )
                        return false;

                    // change the "result" param value inside event
                    return [
                        ListenerDispatchResult::RESULT_DISPATCH => $this->makeResponse($result, $route_match)
                    ];
                }
                , 100
            )
            ->on(
                EventHeapOfSapi::EVENT_APP_ERROR
                , function ($exception = null, $e = null) use ($events)
                {
                    if ( $this->shouldSkipRenderer() )
                        return false;

                    # disable default throw exception listener at the end
                    $e->collector()->setExceptionShouldThrow(false);
                    $e->stopPropagation();

                    // change the "result" param value inside event
                    return [
                        ListenerDispatch::RESULT_DISPATCH => $this->makeErrorResponse($exception),
                    ];
                }
                , self::APP_ERROR_HANDLE_RENDERER_PRIORITY + 100
            )
        ;
        
        return $this;
    }

    /**
     * Make Response From Given Result
     *
     * @param mixed        $result
     * @param iRouterStack $routeMatch
     *
     * @return iHttpResponse|iViewModel|string
     * @throws \Exception
     */
    function makeResponse($result, $routeMatch = null)
    {
        if (! $this->isRenderable($result) )
            throw new ResultNotRenderableError(sprintf(
                'Result (%s) is not renderable by Render Strategy.'
                    , (is_object($result)) ? get_class($result) : gettype($result)
            ));


        ## Handle Result Registered Hydration
        #
        if ( is_array($result) )
            $result = new \ArrayIterator($result);

        if ( $result instanceof \Traversable )
            $result = StdTravers::of($result)->toArray(null, true);


        ## Build Response
        #
        if ($routeMatch)
            // Handle Result Hydration
            $result = $this->_handleResultHydration($result, $routeMatch);


        $result = [
            'status' => 'OK',
            'result' => $result
        ];

        $content  = json_encode($result);

        $response = $this->getResponse();
        $response->headers()
            ->insert( FactoryHttpHeader::of(['Content-Type' => 'application/json']) )
        ;

        $response->setBody($content);
        return $response;
    }

    /**
     * @inheritdoc
     */
    function makeErrorResponse(\Exception $exception, $_ = null)
    {
        // TODO Response Aware Exception; map exception code to response code aware and more; include headers
        //      Might be good to attach it to finish event
        $exception_code = $exception->getCode();

        try {
            $exRef = new \ReflectionClass($exception);
            $errorName = $exRef->getShortName();
        } catch (\Exception $e) {
            $errorName = get_class($exception);
        }


        $result = [
            'status' => 'ERROR',
            'error'  => [
                'state'   => $errorName,
                'code'    => $exception_code,
                'message' => $exception->getMessage(),
            ],
        ];


        if ( $isAllowDisplayExceptions = FactoryEnvironment::hasCurrentEnvironment() )
            $isAllowDisplayExceptions = new EnvServerDefault;

        $isAllowDisplayExceptions = $isAllowDisplayExceptions->getErrorReporting();
        if ($isAllowDisplayExceptions) {
            do {
                $result = array_merge_recursive($result, [
                    'error' => [
                        '_debug_' => [
                            'exception' => [
                                [
                                    'message' => $exception->getMessage(),
                                    'class'   => get_class($exception),
                                    'file'    => $exception->getFile(),
                                    'line'    => $exception->getLine(),
                                ],
                            ],
                        ],
                    ],
                ]);
            } while ($exception = $exception->getPrevious());
        }

        $content  = json_encode($result);
        /** @var iHttpResponse $response */
        $response = $this->getResponse();
        if (Status::_($response)->isSuccess()) {
            if (! (is_numeric($exception_code)
                && $exception_code > 100
                && $exception_code <= 600
            ))
                $exception_code = 500;
        }

        $response->setStatusCode(($exception_code) ? $exception_code : 500);
        $response->headers()->insert(
            FactoryHttpHeader::of(['Content-Type' => $this->getContentType()])
        );
        $response->setBody($content);

        return $response;
    }

    /**
     * @inheritdoc
     */
    function getContentType()
    {
        return 'application/json';
    }

    /**
     * Should this renderer skipped?
     *
     * @return bool
     */
    function shouldSkipRenderer()
    {
        // Check What is Accept Request Header by Client
        //
        $r = true;
        if ( $this->getRequest()->headers()->has('Accept') ) {
            $values = renderHeaderValue($this->getRequest(), 'Accept');
            if (strtolower($values) === 'application/json')
                // Accept application/json given by http client
                $r = false;
        }

        return $r;
    }


    // Implement configurable:

    /**
     * @inheritdoc
     */
    function with(array $options, $throwException = false)
    {
        if (! $this->config)
            $this->config = new StdArray;

        $this->config = $this->config->withMergeRecursive(new StdArray($options));
        return $this;
    }


    // Options:

    /**
     * Set Request Object
     *
     * @param iHttpRequest $request
     *
     * @return $this
     */
    function setRequest(iHttpRequest $request)
    {
        $this->request = $request;
        return $this;
    }

    /**
     * Get Request Object
     *
     * @return iHttpRequest
     */
    function getRequest()
    {
        return $this->request;
    }

    /**
     * Set Response
     *
     * @param iHttpResponse $response
     *
     * @return $this
     */
    function setResponse(iHttpResponse $response)
    {
        $this->response = $response;
        return $this;
    }

    /**
     * Get Response Object
     *
     * @return iHttpResponse
     */
    function getResponse()
    {
        if (null === $this->response)
            $this->setResponse(new HttpResponse);

        return $this->response;
    }


    // ..

    /**
     * Handle Hydrate Chain Of Result
     *
     * @param \Traversable|array $result
     * @param iRouterStack $routeMatch
     *
     *
     * @return array|\Traversable
     * @throws \Exception
     */
    private function _handleResultHydration($result, iRouterStack $routeMatch)
    {
        ## Get Hydrator if has registered
        #
        $routeName = $routeMatch->getName();


        ## Merge Hydration Renderer
        #
        $r = null;
        foreach ( $this->_yieldRenderConf($routeName) as  $confHydration )
        {
            if ($r === null)
                $r = [];

            // Chain Hydration
            //
            $mergeResult = true;
            while ( $hydrator = array_shift($confHydration) )
            {
                if ($hydrator == '_') {
                    $mergeResult = false;
                    continue;
                }


                if (! is_object($hydrator) )
                    $hydrator = \Poirot\Ioc\newInitIns( new instance($hydrator) );

                if (! $hydrator instanceof aDataAbstract )
                    throw new \RuntimeException(sprintf(
                        'Hydrator Invalid For Route (%s), given: (%s).'
                        , $routeName, get_class($hydrator)
                    ));


                $h = $hydrator->import($result);
                $h = StdTravers::of($h)->toArray(null, true);
                $r = $this->mergeRecursive($r, $h);
            }


            if ( $mergeResult )
                $r = array_merge($result, $r);
        }


        return ($r === null) ? $result : $r;
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
    private function _getConf($key = null, $_ = null)
    {
        $config = $this->config;

        foreach (func_get_args() as $key) {
            if (! isset($config[$key]) )
                return null;

            $config = $config[$key];
        }

        return $config;
    }

    private function _yieldRenderConf($routeName)
    {
        if ( $confHydration = $this->_getConf('routes', $routeName) )
            yield $confHydration;

        // looking for aliases hydration
        if ( $confAliases = $this->_getConf('aliases') )
        {
            foreach ($confAliases as $routeAlias => $routes) {
                if ( in_array($routeName, $routes) )
                    yield $this->_getConf('routes', $routeAlias);
            }
        }

        return null;
    }

    private function mergeRecursive(array $a, array $b)
    {
        foreach ($b as $key => $value)
        {
            if (! array_key_exists($key, $a)) {
                // key not exists so simply add it to array
                $a[$key] = $value;
                continue;
            }


            if ( is_int($key) && !is_array($value)) {
                // [ 'value' ] if value not exists append to array!
                if (! in_array($value, $a) )
                    $a[] = $value;

            } elseif (is_array($value) && is_array($a[$key]))  {
                // a= [k=>[]] , b=[k=>['value']]
                $a[$key] = $this->mergeRecursive($a[$key], $value);

            } else {
                // save old value and push them into new array list
                $a[$key] = $value;
            }
        }

        return $a;
    }
}
