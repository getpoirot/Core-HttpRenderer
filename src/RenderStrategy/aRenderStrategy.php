<?php
namespace Module\HttpRenderer\RenderStrategy;

use Module\HttpRenderer\Interfaces\iRenderStrategy;

use Poirot\Http\Interfaces\iHttpResponse;
use Poirot\Std\Traits\tConfigurable;


/**
 * Renderer Strategies are an Event related object that will attached to
 * an Event during module loading, or somehow before dispatching usually.
 *
 * @see \Module\HttpRenderer\Module::initSapiEvents()
 */
abstract class aRenderStrategy
    implements iRenderStrategy
{
    use tConfigurable;


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


    // Implement Configurable

    /**
     * Build Object With Provided Options
     *
     * @param array $options        Associated Array
     * @param bool  $throwException Throw Exception On Wrong Option
     *
     * @return $this
     * @throws \Exception
     * @throws \InvalidArgumentException
     */
    abstract function with(array $options, $throwException = false);
}
