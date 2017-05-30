<?php
namespace Module\HttpRenderer\Services\RenderStrategy\DefaultStrategy;

use Module\HttpRenderer\Services\RenderStrategy\ListenersRenderDefaultStrategy;
use Poirot\Ioc\instance;
use Poirot\Router\Interfaces\iRouterStack;
use Poirot\Std\Struct\CollectionPriority;


class ListenerThemes
{
    /** @var ListenersRenderDefaultStrategy */
    protected $viewRendererStrategy;


    /**
     * ListenerError constructor.
     *
     * @param ListenersRenderDefaultStrategy $defaultStrategy
     * @param array                          $themesConf
     */
    function __construct(ListenersRenderDefaultStrategy $defaultStrategy, array $themesConf)
    {
        $this->viewRendererStrategy = $defaultStrategy;
        $this->themes = $themesConf;
    }


    /**
     * Execute Themes When Callable To Determine The Theme is
     * Acceptable in Current Dispatch?
     * and Give Queue list to Renderer Listener.
     *
     * @param iRouterStack $routeMatch
     *
     * @return array|void
     */
    function __invoke(iRouterStack $routeMatch)
    {
        $queue = new CollectionPriority;

        foreach ($this->themes as $name => $settings) {
            $when = $settings['when'];
            if ( is_callable($when) ) {
                $callable = \Poirot\Ioc\newInitIns( new instance($when) );
                $when = (boolean) call_user_func($callable);
            }

            if ($when)
                $queue->insert(
                    (object) [ 'name' => $name, 'dir' => $settings['dir'], 'layout' => $settings['layout'] ]
                    , $settings['priority']
                );
        }

        $this->viewRendererStrategy->giveThemes($queue);
    }
}
