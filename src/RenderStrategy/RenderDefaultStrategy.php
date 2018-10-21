<?php
namespace Module\HttpRenderer\RenderStrategy;

use Module\HttpFoundation\ServiceManager\ServiceRequest;
use Module\HttpRenderer\RenderStrategy\DefaultStrategy\ListenerError;
use Poirot\Application\aSapi;
use Poirot\Application\Sapi\Event\EventHeapOfSapi;

use Poirot\Events\Interfaces\iEvent;

use Poirot\Http\Interfaces\iHttpRequest;
use Poirot\Http\Interfaces\iHttpResponse;
use Poirot\Ioc\instance;
use Poirot\Loader\LoaderNamespaceStack;
use Poirot\Router\Interfaces\iRouterStack;
use Poirot\Router\RouterStack;

use Poirot\Std\Struct\CollectionPriority;
use Poirot\Std\Struct\DataEntity;
use Poirot\View\aViewModel;
use Poirot\View\DecorateViewModel;
use Poirot\View\Interfaces\iViewModel;
use Poirot\View\Interfaces\iViewModelPermutation;
use Poirot\View\ViewModelStatic;
use Poirot\View\ViewModelTemplate;


class RenderDefaultStrategy
    extends aRenderStrategy
{
    const CONF_KEY = 'view_renderer';
    const CONF_ROUTE_PARAMS = 'view_renderer';


    const PRIORITY_CREATE_VIEWMODEL_RESULT    = -10;
    const PRIORITY_PREPARE_VIEWMODEL_TEMPLATE = -100;
    const PRIORITY_DECORATE_VIEWMODEL_LAYOUT  = -900;
    const PRIORITY_FINALIZE                   = -1000;

    protected $themesQueue;

    /** @var iViewModelPermutation View script model */
    protected $scriptView;
    /** @var iViewModelPermutation */
    protected $templateView;

    protected $defaultLayout = 'default';
    protected $themes_loaded = [];


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
            $this->scriptView = $this->sc->fresh('/ViewModel');

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
            $viewAsTemplate = $this->sc->fresh('/ViewModel');
            $viewAsTemplate->setFinal();
            $this->templateView = $viewAsTemplate;
        }

        return $this->templateView;
    }

    /**
     * Give Matched Themes Queue
     *
     * @return CollectionPriority
     */
    protected function themesQueue()
    {
        if (! $this->themesQueue )
            $this->themesQueue = new CollectionPriority;

        return $this->themesQueue;
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
        /** @var aSapi $sapi */
        $sapi = $events->collector()->getSapi();
        /** @var iHttpRequest $httpRequest */
        $httpRequest = $sapi->services()->get(ServiceRequest::NAME);

        if ( $httpRequest->getMethod() == 'HEAD' )
            // Response To HEAD request is not necessary!
            return $this;


        $self = $this;
        $events
            ## give themes and initialize
            ->on(
                EventHeapOfSapi::EVENT_APP_BOOTSTRAP
                , function () use ($self) {
                    $self->giveThemes(false);
//                    $self->_ensureThemes();
                }
                , 1000
            )
            ## give themes and initialize
            ->on(
                EventHeapOfSapi::EVENT_APP_DISPATCH
                , function () use ($self) {
                    $self->giveThemes(true);
                    $self->_ensureThemes();
                }
                , 1000
            )

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
            ## ensure themes viewResolver
            ->on(
                EventHeapOfSapi::EVENT_APP_RENDER
                , function () use ($self) {
                    $self->_ensureThemes();
                }
                , self::PRIORITY_DECORATE_VIEWMODEL_LAYOUT+1
            )

            ## handle error pages
            ->on(
                EventHeapOfSapi::EVENT_APP_ERROR
                , new ListenerError($this, $this->themesQueue)
                , self::APP_ERROR_HANDLE_RENDERER_PRIORITY
            )
        ;

        return $this;
    }

    /**
     * Give Theme To Queue From Conf.
     *
     * ! if not invokeWhen then just give default themes on bootstrap
     *
     * @param boolean $invokeWhen
     */
    protected function giveThemes($invokeWhen)
    {
        $queue = $this->themesQueue();

        foreach ($this->_getConf('themes') as $name => $settings)
        {

            if ( in_array($name, $this->themes_loaded) )
                continue;


            $when = $settings['when'];
            if ( $invokeWhen && is_callable($when) ) {
                $callable = \Poirot\Ioc\newInitIns( new instance($when) );
                $when = (boolean) call_user_func($callable);

                if ($when) {
                    $queue->insert(
                        (object) [ 'name' => $name, 'dir' => $settings['dir'], 'layout' => $settings['layout'] ]
                        , $settings['priority']
                    );

                    $this->themes_loaded[] = $name;
                }
            } elseif (!is_callable($when) && $when) {
                $queue->insert(
                    (object) [ 'name' => $name, 'dir' => $settings['dir'], 'layout' => $settings['layout'] ]
                    , $settings['priority']
                );

                $this->themes_loaded[] = $name;
            }
        }
    }

    protected function _ensureThemes()
    {
        $viewModelResolver = $this->sc->get('/viewModelResolver');

        foreach (clone $this->themesQueue as $theme) {
            ## ViewScripts To View Resolver:
            /** @var LoaderNamespaceStack $resolver */
            $resolver = $viewModelResolver->loader( LoaderNamespaceStack::class );
            $resolver->with([
                '**' => [ $theme->dir ],
            ]);
        }
    }


    /**
     * Create ViewModel From Actions Result
     *
     * priority -10
     *
     * @param mixed        $result      Result from dispatch action
     * @param iRouterStack $route_match
     *
     * @return array|void
     */
    protected function createScriptViewModelFromResult($result = null, $route_match = null)
    {
        if ($result instanceof iHttpResponse)
            // Response Prepared; So Do Nothing.
            return;



        ## Create View Model From Result
        #
        $viewModel = $result;
        if (! $viewModel instanceof iViewModel)
        {
            if (\Poirot\Std\isStringify($result)) {
                ## null, string, objects with __toString
                $viewModel = new ViewModelStatic();
                $viewModel->setContent($result);

            } elseif (
                is_array($result)
                || $result instanceof \Traversable
            ) {
                $viewModel = $this->viewModelOfScripts();
                $viewModel->setVariables($result);
            }
        }


        if (! $viewModel instanceof iViewModel)
            throw new \RuntimeException(sprintf(
                'View Result (%s) Is Not Acceptable.'
                , \Poirot\Std\flatten($viewModel)
            ));

        ## prepare layout and template
        #
        $viewModel = $this->_preScriptViewModelTemplate($viewModel, $route_match);
        return ['result' => $viewModel];
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

        $viewAsTemplate = $viewModel;

        foreach (clone $this->themesQueue as $theme)
        {
            /** @var DecorateViewModel $viewAsTemplate */
            $viewAsTemplate = $this->viewModelOfLayouts();

            ## default layout if template view has no template
            $layout = ( $viewAsTemplate->getTemplate() )
                ? $viewAsTemplate->getTemplate()
                : $theme->layout['default']
            ;

            $viewAsTemplate->setTemplate($layout);
            ##  bind current result view model as child delegate
            ##- with parent when render while put result in $content
            $viewAsTemplate->bind( new DecorateViewModel(
                $viewModel
                , null
                , function($resultRender, $parent) {
                /** @var $parent iViewModelPermutation */
                $parent->variables()->set('content', (string) $resultRender);
            }
            ));

            if ( $viewAsTemplate->isFinal() )
                break;
        }


        return ['result' => $viewAsTemplate];
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
     * @param iViewModel   $result      Result from dispatch action
     * @param iRouterStack $route_match
     *
     * @return iViewModel|ViewModelTemplate
     */
    private function _preScriptViewModelTemplate(iViewModel $result = null, $route_match = null)
    {
        $viewScriptModel = $result;
        $routeParams     = ($route_match) ? $route_match->params()->get(self::CONF_ROUTE_PARAMS) : null;


        ## Achieve Template Name From Matched Route:
        #
        if ( $route_match && ($viewScriptModel instanceof ViewModelTemplate || $viewScriptModel instanceof DecorateViewModel) )
        {
            $routeName = $route_match->getName();
            $template  = (isset($routeParams['template'])) ? $routeParams['template'] : $viewScriptModel->getTemplate();
            if ( $template && (false === strpos($template, RouterStack::SEPARATOR)) )
                ## if template name not include separator, like (about)
                ## prefixed with route match name
                $template = substr($routeName, 0, strrpos($routeName, RouterStack::SEPARATOR)).RouterStack::SEPARATOR.$template;
            elseif (! $template )
                $template = $routeName;


            $viewScriptModel->setTemplate($template);
        }

        ## Final Template Settings:
        #
        if ($route_match && $viewScriptModel instanceof aViewModel)
        {
            (! isset($routeParams['final']) ) ?: $viewScriptModel->setFinal($routeParams['final']);
        }



        return $viewScriptModel;
    }


    /**
     * Get Config Values
     *
     * Argument can passed and map to config if exists [$key][$_][$__] ..
     *
     * @param $key
     * @param null $_
     *
     * @return mixed|null
     * @throws \Exception
     */
    protected function _getConf($key = null, $_ = null)
    {
        // retrieve and cache config
        $services = $this->sc;

        /** @var aSapi $config */
        $config = $services->get('/sapi');
        $orig = $config  = $config->config();
        /** @var DataEntity $config */
        $config = $config->get( self::CONF_KEY, [] );

        foreach (func_get_args() as $key) {
            if (! isset($config[$key]) )
                return null;

            $config = $config[$key];
        }

        return $config;
    }
}
