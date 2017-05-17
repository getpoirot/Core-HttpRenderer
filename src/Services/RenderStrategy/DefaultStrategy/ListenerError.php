<?php
namespace Module\HttpRenderer\Services\RenderStrategy\DefaultStrategy;

use Module\HttpRenderer\Services\RenderStrategy\ListenersRenderDefaultStrategy;
use Poirot\Application\Sapi;

use Poirot\Application\SapiHttp;
use Poirot\Events\Listener\aListener;

use Poirot\Http\HttpMessage\Response\Plugin\Status;
use Poirot\Http\Interfaces\iHttpResponse;
use Poirot\Std\Environment\EnvServerDefault;


/**
 * @see SapiHttp::_attachToEvents
 */

class ListenerError
    extends aListener
{
    const CONF_KEY = 'error_templates';

    /** @var ListenersRenderDefaultStrategy */
    protected $viewRendererStrategy;


    /**
     * ListenerError constructor.
     * 
     * @param ListenersRenderDefaultStrategy $defaultStrategy
     */
    function __construct(ListenersRenderDefaultStrategy $defaultStrategy)
    {
        $this->viewRendererStrategy = $defaultStrategy;

        parent::__construct(null); // has no options
    }

    /**
     * @param \Exception             $exception
     * @param SapiHttp               $sapi
     * @param Sapi\Event\EventError  $event
     *
     * @return void|array
     */
    function __invoke($exception = null, SapiHttp $sapi = null, $event = null)
    {
        if (!$exception instanceof \Exception)
            ## unknown error
            return;

        $viewRenderStrategy = $this->viewRendererStrategy;

        # View Script Model Template
        $scriptViewModel = $viewRenderStrategy->viewModelOfScripts();
        $errorTemplate   = $this->_getErrorViewTemplates($exception, $sapi);
        $scriptViewModel->setTemplate((is_array($errorTemplate)) ? $errorTemplate[0] : $errorTemplate);

        # Error Layout Template
        $layoutViewModel = $viewRenderStrategy->viewModelOfLayouts();
        $layoutTemplate  = (is_array($errorTemplate)) ? $errorTemplate[1] : $this->_getDefaultLayoutTemplate($sapi);
        if ($layoutTemplate)
            $layoutViewModel->setTemplate($layoutTemplate);
        else
            ## just render view script and disable layout template
            $scriptViewModel->setFinal();

        // ...

        $isAllowDisplayExceptions = new EnvServerDefault();
        $isAllowDisplayExceptions = $isAllowDisplayExceptions->getErrorReporting();


        $exception_code = $exception->getCode();

        /** @var iHttpResponse $response */
        $response = $sapi->services()->get('HttpResponse');
        if (Status::_($response)->isSuccess()) {
            if (! (is_numeric($exception_code)
                && $exception_code > 100
                && $exception_code <= 600
            ))
                $exception_code = 500;

            $response->setStatusCode(($exception_code) ? $exception_code : 500);
        }


        return array(
            # view result
            Sapi\Server\Http\ListenerDispatch::RESULT_DISPATCH => array(
                'exception' => new \Exception(
                    'An error occurred during execution; please try again later.'
                    , null
                    , $exception
                ),
                'display_exceptions' => $isAllowDisplayExceptions
            ),

            # disable default throw exception listener at the end
            'exception' => null, // Grab Exception and not pass to other handlers
        );
    }


    // ..

    /** @see \Application\Module::initConfig */
    protected function _getErrorViewTemplates($exception, SapiHttp $sapi)
    {
        $exceptionTemplate = 'error/error';

        $templates = $sapi->config()->get(ListenersRenderDefaultStrategy::CONF_KEY);
        if (is_array($templates) && isset($templates[self::CONF_KEY])) {
            $templates = $templates[self::CONF_KEY];
            $exClass = get_class($exception);
            while($exClass) {
                if (isset($templates[$exClass])) {
                    $exceptionTemplate = $templates[$exClass];
                    break;
                }

                $exClass = get_parent_class($exClass);
            }
        }

        return $exceptionTemplate;
    }

    protected function _getDefaultLayoutTemplate(SapiHttp $sapi)
    {
        $templates = $sapi->config()->get(ListenersRenderDefaultStrategy::CONF_KEY);
        $templates = $templates[self::CONF_KEY];

        return $templates['Exception'][1];
    }
}
