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
use Poirot\Std\Environment\EnvServerDefault;
use Poirot\Std\Type\StdTravers;
use ReflectionClass;


/**
 *
 * @see doAttachDefaultEvents::_attachDefaultEvents
 */
class RenderJsonStrategy
    extends aRenderStrategy
{
    /** @var Container */
    protected $sc;
    protected $request;

    protected $canHandle = false;


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
                , function ($result = null, $sapi = null) use ($self) {
                    return $self->createResponseFromResult($result, $sapi);
                }
                , 100
            )
            ->on(
                EventHeapOfSapi::EVENT_APP_ERROR
                , function ($exception = null, $e = null, $sapi = null) use ($self) {
                    return $self->handleErrorRender($exception, $e, $sapi);
                }
                , self::APP_ERROR_HANDLE_RENDERER_PRIORITY
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
    protected function createResponseFromResult($result = null, $sapi = null)
    {
        if (! $this->canHandle() )
            return null;

        if ( $result instanceof iHttpResponse )
            // Response Prepared; Do Nothing.
            return null;


        if ( is_array($result) )
            $result = new \ArrayIterator($result);

        if ( $result instanceof \Traversable ) {
            $result = new StdTravers($result);
            $result = $result->toArray(null, true);
        }
        
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
}
