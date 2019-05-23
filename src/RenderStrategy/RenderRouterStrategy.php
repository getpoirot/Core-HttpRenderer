<?php
namespace Module\HttpRenderer\RenderStrategy;

use Module\HttpFoundation\Events\Listener\ListenerDispatch;
use Module\HttpFoundation\Events\Listener\ListenerDispatchResult;
use Module\HttpRenderer\Interfaces\iRenderStrategy;
use Module\HttpRenderer\Exceptions\ResultNotRenderableError;
use Poirot\Events\Interfaces\iEvent;

use Poirot\Application\Sapi\Event\EventHeapOfSapi;
use Poirot\Http\Interfaces\iHttpResponse;
use Poirot\Ioc\Interfaces\iContainer;
use Poirot\Ioc\Interfaces\Respec\iServicesAware;
use Poirot\Router\Interfaces\iRouterStack;
use function Poirot\Std\flatten;
use Poirot\Std\Struct\DataEntity;
use Poirot\View\Interfaces\iViewModel;


/*
// Can be added as route param
//
'image' => [
    'route'   => 'RouteMethodSegment',
    'options' => [
        'criteria'    => '/</u/:username~[a-zA-Z0-9._]+~><:userid~\w{24}~>_profile.jpg',
        'method'      => 'GET',
        'match_whole' => true,
    ],
    'params'  => [
        ListenerDispatch::ACTIONS => [
            \Module\Folio\Actions\Profile\Avatar\RenderProfileAvatarAction::class,
        ],
        RenderRouterStrategy::ConfRouteParam => [
            'strategy' => 'json',
        ],
    ],
],
*/
class RenderRouterStrategy
    implements iRenderStrategy
    , iServicesAware
{
    const ConfRouteParam = 'route.renderer';

    protected $matchedRoute;
    protected $sc;


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
        $events->on(
            EventHeapOfSapi::EVENT_APP_RENDER
            , function ($result = null, $route_match = null, $e = null)
            {
                if (! $route_match )
                    return false;

                if (! $this->getMatchedRoute() )
                    $this->setMatchedRoute($route_match);

                if ( $this->shouldSkipRenderer() )
                    return false;

                $e->stopPropagation();

                return [
                    ListenerDispatchResult::RESULT_DISPATCH => $this->makeResponse($result, $route_match)
                ];
            }
            , PHP_INT_MAX
        )

        ## handle error pages
        ->on(
            EventHeapOfSapi::EVENT_APP_ERROR
            , function ($exception = null, $e = null, $route_match = null) use ($events)
            {
                if (! $route_match )
                    return false;

                if (! $this->getMatchedRoute() )
                    $this->setMatchedRoute($route_match);

                if ( $this->shouldSkipRenderer() )
                    return null;

                # disable default throw exception listener at the end
                $e->collector()->setExceptionShouldThrow(false);
                $e->stopPropagation();

                // change the "result" param value inside event
                return [
                    ListenerDispatch::RESULT_DISPATCH => $this->makeErrorResponse($exception),
                ];
            }
            , PHP_INT_MAX
        );


        return $this;
    }

    /**
     * Make Response From Given Result
     *
     * @param mixed $result
     *
     * @return iHttpResponse|iViewModel|string
     * @throws ResultNotRenderableError
     * @throws \Exception
     */
    function makeResponse($result, $_ = null)
    {
        $strategy = $this->_getStrategyFromRouteParams();
        if (! $strategy->isRenderable($result) )
            throw new \Exception(sprintf(
                'Strategy %s is unable to render %s'
                    , get_class($strategy), flatten($result)
            ));


        return $strategy->makeResponse($result);
    }

    /**
     * Make Error Response From Given Exception
     *
     * @param \Exception $exception
     *
     * @return iHttpResponse|iViewModel|string
     * @throws \Exception
     */
    function makeErrorResponse(\Exception $exception, $_ = null)
    {
        $strategy = $this->_getStrategyFromRouteParams();
        return $strategy->makeErrorResponse($exception);
    }

    /**
     * Get Content Type That Renderer Will Provide
     * exp. application/json; text/html
     *
     * @return string
     * @throws \Exception
     */
    function getContentType()
    {
        return $this->_getStrategyFromRouteParams()->getContentType();
    }

    /**
     * Should this renderer skipped?
     *
     * @return bool
     */
    function shouldSkipRenderer()
    {
        if (! $this->getMatchedRoute() )
            return true;

        // check weather matched route is include param option
        $routeParams = $this->getMatchedRoute()->params();
        return !$routeParams->has(self::ConfRouteParam);
    }

    /**
     * @inheritdoc
     */
    function isRenderable($result)
    {
        $r = true;

        if ( $result instanceof iHttpResponse )
            // Response Prepared; Do Nothing.
            $r = false;
        elseif (! (is_array($result) || $result instanceof \Traversable) )
            // Result Can`t Handle With Renderer!
            $r = false;

        return $r;
    }


    // Implement Service Aware

    /**
     * Set Service Container
     *
     * @param iContainer $container
     *
     * @return RenderRouterStrategy
     */
    function setServices(iContainer $container)
    {
        $this->sc = $container;
        return $this;
    }


    // Options:

    /**
     * Set Matched Route
     *
     * @param iRouterStack $request
     *
     * @return $this
     */
    function setMatchedRoute(iRouterStack $request)
    {
        $this->matchedRoute = $request;
        return $this;
    }

    /**
     * Get Request Object
     *
     * @return iRouterStack
     */
    function getMatchedRoute()
    {
        return $this->matchedRoute;
    }


    // ..

    /**
     * Make Strategy Instance Object From Options
     *
     * @return array|aRenderStrategy
     * @throws \Exception
     */
    protected function _getStrategyFromRouteParams()
    {
        if ( $this->shouldSkipRenderer() )
            throw new \Exception(
                'Unable to Render Response; MatchedRoute Not Set Or Consist Required Params.'
            );


        /** @var DataEntity $routeParams */
        $routeParams = $this->getMatchedRoute()->params();
        $settings    = $routeParams->get(self::ConfRouteParam);

        /** @var aRenderStrategy $strategy */
        $strategy = $settings['strategy'];
        if ( is_string($strategy) && !class_exists($strategy) ) {
            $strategy = $this->sc->get($strategy);
        } else {
            $strategy = \Poirot\Ioc\newInitIns($strategy, $this->sc);

            if (! $strategy instanceof iRenderStrategy )
                throw new \Exception(sprintf(
                    'Strategy must instance of iRenderStrategy; given: (%s).'
                    , (is_object($strategy)) ? get_class($strategy) : gettype($strategy)
                ));
        }


        return $strategy;
    }
}
