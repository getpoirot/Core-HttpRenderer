<?php
namespace Module\HttpRenderer\RenderStrategy;

use Poirot\Events\Interfaces\iEvent;

use Poirot\Application\Sapi\Event\EventHeapOfSapi;
use Poirot\Router\Interfaces\iRouterStack;
use Poirot\Std\aConfigurable;
use Poirot\Std\Struct\DataEntity;

// TODO seems almost not needed or atleast cause bad design problems
class RenderRouterStrategy
    extends aRenderStrategy
{
    const CONF = 'renderer.route';


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
        $self = $this;
        $events
            ->on(
                EventHeapOfSapi::EVENT_APP_RENDER
                , function ($result = null, $route_match = null) use ($self, $events) {
                    return $self->handleStrategyFromRoute($events, $result, $route_match);
                }
                , PHP_INT_MAX
            )
        ;
        
        return $this;
    }


    /**
     * Get Content Type That Renderer Will Provide
     * exp. application/json; text/html
     *
     * @return string
     */
    function getContentType()
    {
        return 'text/html; charset=UTF-8';
    }


    // ..

    /**
     * @param $events
     * @param $result
     * @param iRouterStack $route_match
     *
     * @throws \Exception
     */
    protected function handleStrategyFromRoute($events, $result, $route_match)
    {
        if (! $route_match )
            return;


        /** @var DataEntity $routeParams */
        $routeParams = $route_match->params();
        if (! $settings = $routeParams->get(self::CONF) )
            return;


        if (! isset($settings['strategy']) )
            throw new \Exception('Router Strategy Settings Missing Config: "strategy".');


        /** @var aRenderStrategy $strategy */
        $strategy = $settings['strategy'];
        if ( is_string($strategy) )
        {
            $strategy = $this->sc->get($strategy);
        }
        else
        {
            $strategy = \Poirot\Ioc\newInitIns($strategy, $this->sc);

            if (! $strategy instanceof aRenderStrategy )
                throw new \Exception(sprintf(
                    'Strategy must instance of aRenderStrategy; given: (%s).'
                    , (is_object($strategy)) ? get_class($strategy) : gettype($strategy)
                ));
        }


        // TODO using like this is almost unused
        $strategy->attachToEvent($events);

        if ( is_callable($strategy) )
            // Invoke Strategy With Current Result
            return $strategy($result);
    }

    /**
     * Build Object With Provided Options
     *
     * @param array $options Associated Array
     * @param bool $throwException Throw Exception On Wrong Option
     *
     * @return $this
     * @throws \Exception
     * @throws \InvalidArgumentException
     */
    function with(array $options, $throwException = false)
    {
        // TODO: Implement with() method.
    }
}
