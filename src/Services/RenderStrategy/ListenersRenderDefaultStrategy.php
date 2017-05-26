<?php
namespace Module\HttpRenderer\Services\RenderStrategy;

use Module\HttpRenderer\Services\RenderStrategy\DefaultStrategy\ListenerError;
use Poirot\Application\Sapi\Event\EventHeapOfSapi;

use Poirot\Events\Interfaces\iEvent;

use Poirot\Router\Interfaces\iRouterStack;
use Poirot\Router\RouterStack;

use Poirot\Std\Interfaces\Struct\iDataEntity;

use Poirot\View\DecorateViewModelFeatures;
use Poirot\View\Interfaces\iViewModel;
use Poirot\View\Interfaces\iViewModelPermutation;
use Poirot\View\ViewModelStatic;
use Poirot\View\ViewModelTemplate;


/**
 * // TODO Template on matched route as params; each route match can define render layout
 *
 * @see doAttachDefaultEvents::_attachDefaultEvents*
 */
class ListenersRenderDefaultStrategy
    extends aListenerRenderStrategy
{
    const CONF_KEY = 'view_renderer';

    const PRIORITY_CREATE_VIEWMODEL_RESULT    = -10;
    const PRIORITY_PREPARE_VIEWMODEL_TEMPLATE = -100;
    const PRIORITY_DECORATE_VIEWMODEL_LAYOUT  = -900;
    const PRIORITY_FINALIZE                   = -1000;

    protected $defaultLayout = 'default';

    /** @var iViewModelPermutation View script model */
    protected $scriptView;
    /** @var iViewModelPermutation */
    protected $templateView;


    // Implement Setter/Getter

    /**
     * Set Default Template Name
     *
     * ! name without extension of absolute path
     *
     * @param $template
     *
     * @return $this
     */
    function setDefaultLayout($template)
    {
       $this->defaultLayout = (string) $template;
        return $this;
    }

    /**
     * Get Default Template
     *
     * @return string
     */
    function getDefaultLayout()
    {
        if (!$this->defaultLayout) {
            $sapi = $this->sc->get('sapi');
            /** @var iDataEntity $config */
            $config = $sapi->config();
            if ($config = $config->get(self::CONF_KEY)) {
                if (is_array($config) && isset($config['default_layout']))
                    $this->setDefaultLayout($config['default_layout']);

            }
        }

        return $this->defaultLayout;
    }

    /**
     * Get View Script Model
     *
     * @return ViewModelTemplate|iViewModelPermutation
     */
    function viewModelOfScripts()
    {
        if (! $this->scriptView )
            $this->scriptView = $this->sc->fresh('ViewModel');

        return $this->scriptView;
    }

    /**
     * Get View Template Decorator Model
     *
     * @return ViewModelTemplate|iViewModelPermutation
     */
    function viewModelOfLayouts()
    {
        if (! $this->templateView ) {
            $viewAsTemplate = $this->sc->fresh('ViewModel');
            $viewAsTemplate->setFinal();
            $this->templateView = $viewAsTemplate;
        }

        return $this->templateView;
    }


    // ...

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
            ## create view model from string result
            ->on(
                EventHeapOfSapi::EVENT_APP_RENDER
                , function ($result = null, $route_match = null) use ($self) {
                    return $self->createScriptViewModelFromResult($result, $route_match);
                }
                , self::PRIORITY_CREATE_VIEWMODEL_RESULT
            )
            ## template decorator for view model
            ->on(
                EventHeapOfSapi::EVENT_APP_RENDER
                , function ($result = null, $route_match = null) use ($self) {
                    return $self->injectToLayoutDecorator($result, $route_match);
                }
                , self::PRIORITY_DECORATE_VIEWMODEL_LAYOUT
            )

            ## handle error pages
            ->on(
                EventHeapOfSapi::EVENT_APP_ERROR
                , new ListenerError($this)
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
     *
     * @return array|void
     */
    protected function createScriptViewModelFromResult($result = null, $route_match = null)
    {
        if (\Poirot\Std\isStringify($result)) {
            ## null, string, objects with __toString
            $viewModel = new ViewModelStatic();
            $viewModel->setContent($result);
            return ['result' => $viewModel];
        }
        elseif (
            is_array($result)
            || $result instanceof \Traversable
        ) {
            $viewModel = $this->viewModelOfScripts();
            $viewModel->setVariables($result);
            // prepare layout and templating
            $viewModel = $this->_preScriptViewModelTemplate($viewModel, $route_match);

            return ['result' => $viewModel];
        }
    }

    /**
     * Decorate Current ViewModel With Layout Template
     *
     * priority -900
     *
     * - only for not final viewModels
     * - achieve default template name (config, helper, etc...)
     * - bind current viewModel To Decorator
     * - replace decorator as new result
     *
     * @param mixed $result Result from dispatch action
     * @param RouterStack $route_match
     *
     * @return array|void
     */
    protected function injectToLayoutDecorator($result = null, $route_match = null)
    {
        $viewModel = $result;
        if (! $viewModel instanceof iViewModel )
            return;

        if ( $viewModel->isFinal() )
            ## no layout decorator; in case of action return viewModel instance directly.
            return;



        // ...

        $viewAsTemplate = $this->viewModelOfLayouts();

        ## default layout if template view has no template
        $layout  = ($viewAsTemplate->getTemplate())
            ? $viewAsTemplate->getTemplate()
            : $this->getDefaultLayout()
        ;

        $viewAsTemplate->setTemplate($layout);
        ## bind current result view model as child
        $viewAsTemplate->bind( new DecorateViewModelFeatures(
            $viewModel
            , function(){}
            , function($resultRender, $parent) {
                /** @var $parent iViewModelPermutation */
                $parent->variables()->set('content', (string) $resultRender);
            }
        ));

        return array('result' => $viewAsTemplate);
    }


    // ..

    /**
     * Set Template For ViewModel With No Template-
     * From MatchedRoute
     *
     * - only for template aware viewModels
     * - achieve template name if not exists
     *   with current route name
     * - then set template for view model
     *
     * ## if template name not include separator, like (about)
     * ## prefixed with route match name
     *
     * @param ViewModelTemplate $result      Result from dispatch action
     * @param iRouterStack      $route_match
     *
     * @return ViewModelTemplate
     */
    protected function _preScriptViewModelTemplate(ViewModelTemplate $result = null, $route_match = null)
    {
        $viewScriptModel = clone $result;

        // Achieve Template Name From Matched Route:
        if (!$route_match)
            ## using default view script template
            return $result;

        $template  = $viewScriptModel->getTemplate();
        $routeName = $route_match->getName();
        if ( $template && (false === strpos($template, RouterStack::SEPARATOR)) )
            ## if template name not include separator, like (about)
            ## prefixed with route match name
            $template = substr($routeName, 0, strrpos($routeName, RouterStack::SEPARATOR)).RouterStack::SEPARATOR.$template;
        elseif (!$template)
            $template = $routeName;

        $viewScriptModel->setTemplate($template);
        return $viewScriptModel;
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
}
