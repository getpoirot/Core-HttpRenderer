<?php
namespace Module\HttpRenderer\RenderStrategy;

use Module\HttpRenderer\Interfaces\iRenderStrategy;

use Poirot\Http\Interfaces\iHttpResponse;


/**
 * Renderer Strategies are an Event related object that will attached to
 * an Event during module loading, or somehow before dispatching usually.
 *
 * @see \Module\HttpRenderer\Module::initSapiEvents()
 */
abstract class aRenderStrategy
    implements iRenderStrategy
{
    const APP_ERROR_HANDLE_RENDERER_PRIORITY = -1000;


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

    /**
     * Should this renderer skipped?
     *
     * @return bool
     */
    abstract function shouldSkipRenderer();
}
