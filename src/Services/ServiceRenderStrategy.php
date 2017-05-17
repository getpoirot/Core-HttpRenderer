<?php
namespace Module\HttpRenderer\Services;

use Module\HttpRenderer\Services\RenderStrategy\ListenersRenderDefaultStrategy;
use Module\HttpRenderer\Services\RenderStrategy\ListenersRenderJsonStrategy;
use Poirot\Http\Header\HeaderLine;
use Poirot\Http\Interfaces\iHeader;
use Poirot\Ioc\Container\Service\aServiceContainer;


class ServiceRenderStrategy
    extends aServiceContainer
{
    /** @var string Service Name */
    protected $name = 'RenderStrategy';


    /**
     * Create Service
     *
     * @return mixed
     */
    function newService()
    {
        // TODO Strategy/Aggregate Renderer meet tender-bin requirements

        ## can't override after creation
        $this->setAllowOverride(false);

        /** @var \Poirot\Http\HttpRequest $request */
        $request = $this->services()->get('HttpRequest');

        if ( $request->headers()->has('Accept') ) {
            $acceptHeader = $request->headers()->get('Accept');
            /** @var HeaderLine $acceptHeader */
            /** @var iHeader $h */
            foreach ($acceptHeader as $h) {
                $values = $h->renderValueLine();
                if (strtolower($values) === 'application/json')
                    return $renderStrategy = new ListenersRenderJsonStrategy;
            }
        }

        $renderStrategy = new ListenersRenderDefaultStrategy;
        return $renderStrategy;
    }
}
