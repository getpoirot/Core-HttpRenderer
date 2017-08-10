<?php
namespace Module\HttpRenderer\RenderStrategy\DefaultStrategy;

use Module\HttpFoundation\Events\Listener\ListenerDispatch;
use Module\HttpRenderer\RenderStrategy\RenderDefaultStrategy;
use Poirot\Application\aSapi;
use Poirot\Application\Sapi;

use Poirot\Http\HttpMessage\Response\Plugin\Status;
use Poirot\Http\Interfaces\iHttpResponse;
use Poirot\Std\Environment\EnvServerDefault;
use Poirot\Std\Struct\CollectionPriority;


class ListenerError
{
    /** @var RenderDefaultStrategy */
    protected $viewRendererStrategy;


    /**
     * ListenerError constructor.
     *
     * @param RenderDefaultStrategy $defaultStrategy
     * @param CollectionPriority             $themeQueue
     */
    function __construct(RenderDefaultStrategy $defaultStrategy, &$themeQueue)
    {
        $this->viewRendererStrategy = $defaultStrategy;
        $this->themeQueue = &$themeQueue;
    }


    /**
     * @param \Exception             $exception
     * @param aSapi                  $sapi
     * @param Sapi\Event\EventError  $event
     *
     * @return void|array
     */
    function __invoke($exception = null, aSapi $sapi = null, $event = null)
    {
        if (! $exception instanceof \Exception )
            ## unknown error
            return;


        $viewRenderStrategy = $this->viewRendererStrategy;

        # View Script Model Template
        #
        $scriptViewModel = $viewRenderStrategy->viewModelOfScripts();
        if ( null === $errorTemplate   = $this->_attainViewScriptTemplateOfError($exception) )
            throw new \Exception(sprintf(
                'Cant find error template while exception (%s) catch.'
                , get_class($exception)
            ));

        $scriptViewModel->setTemplate((is_array($errorTemplate)) ? $errorTemplate[0] : $errorTemplate);


        # Error Layout Template
        #
        $layoutViewModel = $viewRenderStrategy->viewModelOfLayouts();
        $layoutTemplate  = (is_array($errorTemplate)) ? $errorTemplate[1] : $this->_attainLayoutTemplate();
        if ($layoutTemplate)
            $layoutViewModel->setTemplate($layoutTemplate);
        else
            ## just render view script and disable layout template
            $scriptViewModel->setFinal();


        // ...

        $isAllowDisplayExceptions = new EnvServerDefault;
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


        return [
            # view result
            ListenerDispatch::RESULT_DISPATCH => [
                'exception' => new \Exception(
                    'An error occurred during execution; please try again later.'
                    , null
                    , $exception
                ),
                'display_exceptions' => $isAllowDisplayExceptions
            ],
        ];
    }


    // ..

    /** @see \Application\Module::initConfig */
    protected function _attainViewScriptTemplateOfError($exception)
    {
        $exceptionTemplate = null;

        foreach (clone $this->themeQueue as $theme)
        {
            $templates = @$theme->layout['exception'];
            if ( is_array($templates) ) {
                $exClass = get_class($exception);
                while($exClass) {
                    if (isset($templates[$exClass])) {
                        $exceptionTemplate = $templates[$exClass];
                        break;
                    }

                    $exClass = get_parent_class($exClass);
                }
            }

            if ( isset($exceptionTemplate) )
                break;
        }

        return $exceptionTemplate;
    }

    protected function _attainLayoutTemplate()
    {
        $exceptionTemplate = null;

        foreach (clone $this->themeQueue as $theme)
        {
            $templates = @$theme->layout['exception'];
            if ( is_array($templates) ) {
                if (isset($templates['Exception']) && isset($templates['Exception'][1])) {
                    #! here (blank) is defined as default layout for all error pages
                    #- 'Exception' => ['error/error', 'blank'],
                    $exceptionTemplate = $templates['Exception'][1];
                    break;
                }
            }
        }

        return $exceptionTemplate;
    }
}
