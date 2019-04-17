<?php
namespace Module\HttpRenderer\Interfaces;

use Module\HttpRenderer\Exceptions\ResultNotRenderableError;
use Poirot\Events\Interfaces\iCorrelatedEvent;
use Poirot\Http\Interfaces\iHttpResponse;
use Poirot\View\Interfaces\iViewModel;


interface iRenderStrategy
    extends iCorrelatedEvent
{
    /**
     * Make Response From Given Result
     *
     * @param mixed $result
     *
     * @return iHttpResponse|iViewModel|string
     * @throws ResultNotRenderableError
     */
    function makeResponse($result, $_ = null);

    /**
     * Make Error Response From Given Exception
     *
     * @param \Exception $exception
     *
     * @return iHttpResponse|iViewModel|string
     */
    function makeErrorResponse(\Exception $exception, $_ = null);

    /**
     * Determine result is acceptable by renderer?
     *
     * @param mixed $result This is the result usually returned from exec. action
     *
     * @return bool
     */
    function isRenderable($result);

    /**
     * Get Content Type That Renderer Will Provide
     * exp. application/json; text/html
     *
     * @return string
     */
    function getContentType();
}
