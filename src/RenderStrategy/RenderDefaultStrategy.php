<?php
namespace Module\HttpRenderer\RenderStrategy;

use Module\HttpFoundation\Events\Listener\ListenerDispatch;
use Module\HttpFoundation\Events\Listener\ListenerDispatchResult;
use Module\HttpRenderer\Exceptions\ResultNotRenderableError;
use Poirot\Application\Sapi\Event\EventHeapOfSapi;

use Poirot\Events\Interfaces\iEvent;

use Poirot\Http\HttpMessage\Request\Plugin\MethodType;
use Poirot\Http\Interfaces\iHttpRequest;
use Poirot\Http\Interfaces\iHttpResponse;
use Poirot\Loader\Interfaces\iLoader;
use Poirot\Loader\LoaderAggregate;
use Poirot\Loader\LoaderNamespaceStack;
use Poirot\Router\Interfaces\iRouterStack;
use Poirot\Router\RouterStack;

use Poirot\Std\Environment\EnvServerDefault;
use Poirot\Std\Environment\FactoryEnvironment;
use Poirot\View\DecorateViewModel;
use Poirot\View\Interfaces\iViewModel;
use Poirot\View\Interfaces\iViewModelPermutation;
use Poirot\View\ViewModelStatic;
use Poirot\View\ViewModelTemplate;


class RenderDefaultStrategy
    extends aRenderStrategy
{
    const CONF_ROUTE_PARAMS = 'view_renderer';

    const PRIORITY_CREATE_VIEWMODEL_RESULT    = -10;
    const PRIORITY_PREPARE_VIEWMODEL_TEMPLATE = -100;
    const PRIORITY_DECORATE_VIEWMODEL_LAYOUT  = -900;
    const PRIORITY_FINALIZE                   = -1000;

    /** @var iHttpRequest */
    protected $request;
    /** @var iViewModelPermutation View script model */
    protected $scriptView;
    /** @var iViewModelPermutation */
    protected $templateView;
    /** @var LoaderAggregate */
    protected $viewResolver;

    protected $defaultLayout = 'layout';
    protected $config;


    /**
     * Construct.
     *
     * @param iHttpRequest       $request      @IoC /httpRequest
     * @param iViewModel|null    $viewModel    @IoC /ViewModel
     * @param iLoader|null       $viewResolver @IoC /ViewModelResolver
     */
    function __construct(
        iHttpRequest $request
        , iViewModel $viewModel = null
        , iLoader $viewResolver = null
    ) {
        $this->setRequest($request);

        if (null !== $viewModel)
            $this->setScriptViewModel($viewModel);

        if (null !== $viewResolver)
            $this->setViewResolver($viewResolver);


        $this->__init();
    }

    function __init()
    {
        $viewAsTemplate = clone $this->getScriptViewModel();
        $viewAsTemplate->setFinal();
        $this->templateView = $viewAsTemplate;
    }


    /**
     * Initialize To Events
     *
     * - usually bind listener(s) to events
     *
     * @param EventHeapOfSapi|iEvent $events
     *
     * @return $this
     * @throws \Exception
     */
    function attachToEvent(iEvent $events)
    {
        if ( $this->shouldSkipRenderer() )
            return $this;


        $events
            ## create view model from result
            ->on(
                EventHeapOfSapi::EVENT_APP_RENDER
                , function ($result = null, $route_match = null) {
                    if (! ($this->shouldSkipRenderer() || $this->isRenderable($result)) )
                        return false;

                    // change the "result" param value inside event
                    return [
                        ListenerDispatchResult::RESULT_DISPATCH => $this->_createScriptViewModelFromResult($result, $route_match)
                    ];
                }
                , self::PRIORITY_CREATE_VIEWMODEL_RESULT
            )
            ## template decorator for view model
            ->on(
                EventHeapOfSapi::EVENT_APP_RENDER
                , function ($result = null) {
                    if (! ($this->shouldSkipRenderer() || $this->isRenderable($result)) )
                        return false;

                    // change the "result" param value inside event
                    return [
                        ListenerDispatchResult::RESULT_DISPATCH => $this->_injectToLayoutDecorator($result)
                    ];
                }
                , self::PRIORITY_DECORATE_VIEWMODEL_LAYOUT
            )
            ## handle error pages
            ->on(
                EventHeapOfSapi::EVENT_APP_ERROR
                , function ($exception = null, $event = null) {
                    if ( $this->shouldSkipRenderer() )
                        return false;

                    # disable default throw exception listener at the end
                    $event->collector()->setExceptionShouldThrow(false);

                    // change the "result" param value inside event
                    return [
                        ListenerDispatch::RESULT_DISPATCH => $this->makeErrorResponse($exception),
                    ];
                }
                , self::APP_ERROR_HANDLE_RENDERER_PRIORITY
            )
        ;

        return $this;
    }

    /**
     * Make Response From Given Result
     *
     * @param mixed $result
     * @param iRouterStack $routeMatch
     *
     * @return iHttpResponse|iViewModel|string
     * @throws ResultNotRenderableError
     * @throws \Exception
     */
    function makeResponse($result, $routeMatch = null)
    {
        $viewModel = $this->_createScriptViewModelFromResult($result, $routeMatch);
        $viewModel = $this->_injectToLayoutDecorator($viewModel);

        return $viewModel;
    }

    /**
     * @inheritdoc
     *
     * - change layout to error templates
     * - change result to include exception
     * - let application render the result as data again
     *
     * @throws \Exception
     */
    function makeErrorResponse(\Exception $exception, $_ = null)
    {
        ## Error Script View
        #
        $scriptViewModel = clone $this->getScriptViewModel();

        // exception template
        $isResolved = false;
        $exClass    = $exception;
        do {
            $errorPageScript = new \ReflectionClass($exClass);
            $errorPageScript = $errorPageScript->getShortName();
            $errorPageScript = 'error'.DS.$errorPageScript;

            if ( false === $exClass = get_parent_class($exClass) )
                break;
            // error/RouteNotFoundError
        } while (false === $isResolved = $scriptViewModel->resolver()->resolve($errorPageScript));

        if (! $isResolved )
            // use default template
            $errorPageScript = 'error'.DS.'error';

        $scriptViewModel->setTemplate($errorPageScript);


        # Error Layout Template
        #
        $layoutViewModel = clone $this->getLayoutViewModel();
        $layoutViewModel->setTemplate($this->getDefaultLayout());


        // ...

        $isAllowDisplayExceptions = (FactoryEnvironment::hasCurrentEnvironment()) ?: new EnvServerDefault;
        $isAllowDisplayExceptions = $isAllowDisplayExceptions->getErrorReporting();


        $scriptViewModel->setVariables([
            'exception' => new \Exception(
                'An error occurred during execution; please try again later.'
                , null
                , $exception
            ),
            'display_exceptions' => $isAllowDisplayExceptions
        ]);


        ##  bind current result view model as child delegate
        ##- with parent when render while put result in $content
        /** @var DecorateViewModel $layoutViewModel */
        $layoutViewModel->bind( new DecorateViewModel(
            $scriptViewModel
            , null
            , function($resultRender, $parent) {
                /** @var $parent iViewModelPermutation */
                $parent->variables()->set('content', (string) $resultRender);
            }
        ));


        return $layoutViewModel;
    }

    /**
     * @inheritdoc
     */
    function isRenderable($result)
    {
        $r = parent::isRenderable($result) ||
            ( \Poirot\Std\isStringify($result) || $result instanceof iViewModel );

        return $r;
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

    /**
     * Should this renderer skipped?
     *
     * @return bool
     */
    function shouldSkipRenderer()
    {
        // Response To HEAD request is not necessary!
        return MethodType::_($this->request)->isHead();
    }


    // Options:

    /**
     * Set View Script Model
     *
     * @param iViewModel $viewModel
     *
     * @return $this
     */
    function setScriptViewModel(iViewModel $viewModel)
    {
        $this->scriptView = $viewModel;
        return $this;
    }

    /**
     * Get View Script Model
     *
     * @return ViewModelTemplate|iViewModelPermutation
     */
    function getScriptViewModel()
    {
        if (! $this->scriptView )
            $this->setScriptViewModel(new ViewModelTemplate);

        return $this->scriptView;
    }

    /**
     * Set View Template Decorator Model
     *
     * @param iViewModel $viewModel
     *
     * @return $this
     */
    function setLayoutViewModel(iViewModel $viewModel)
    {
        $this->templateView = $viewModel;
        return $this;
    }

    /**
     * Get View Template Decorator Model
     *
     * @return ViewModelTemplate|iViewModelPermutation
     */
    function getLayoutViewModel()
    {
        return $this->templateView;
    }

    /**
     * View Model Resolver
     *
     * @return LoaderAggregate
     */
    function getViewResolver()
    {
        if (! $this->viewResolver )
            $this->setViewResolver(new LoaderNamespaceStack);

        return $this->viewResolver;
    }

    /**
     * View Model Resolver
     *
     * @param iLoader $viewResolver
     *
     * @return $this
     */
    function setViewResolver(iLoader $viewResolver)
    {
        $this->viewResolver = $viewResolver;
        return $this;
    }

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
     * Set Request Object
     *
     * @param iHttpRequest $request
     *
     * @return $this
     */
    function setRequest(iHttpRequest $request)
    {
        $this->request = $request;
        return $this;
    }

    /**
     * Get Request Object
     *
     * @return iHttpRequest
     */
    function getRequest()
    {
        return $this->request;
    }


    // ..

    /**
     * Create ViewModel Object From Action`s Result
     *
     * - prepare view scriptname based on the matched route and route params
     *
     *
     * @param string|iViewModel|\Traversable $result Result from dispatch action
     * @param iRouterStack $route_match
     *
     * @return iViewModel|null
     * @throws \Exception
     */
    protected function _createScriptViewModelFromResult($result = null, $route_match = null)
    {
        ## Create View Model From Result
        #
        $viewModel = $result;
        if (! $viewModel instanceof iViewModel) {
            if (\Poirot\Std\isStringify($result)) {
                ## null, string, objects with __toString
                $viewModel = new ViewModelStatic;
                $viewModel->setContent($result);

            } elseif ( is_array($result) || $result instanceof \Traversable) {
                $viewModel = clone $this->getScriptViewModel();
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
        if ($route_match)
            $viewModel = $this->_preparedScriptViewModelTemplate($viewModel, $route_match);

        return $viewModel;
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
     *
     * @return DecorateViewModel|iViewModel
     * @throws \Exception
     */
    protected function _injectToLayoutDecorator($result)
    {
        if (! $result instanceof iViewModel )
            return $result;


        $viewModel = $result;
        if ( $viewModel->isFinal() )
            ## no layout decorator; in case of action return viewModel instance directly.
            return $viewModel;


        /** @var DecorateViewModel $viewAsTemplate */
        $viewAsTemplate = clone $this->getLayoutViewModel();
        $viewAsTemplate->setTemplate( $this->getDefaultLayout() );

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


        return $viewAsTemplate;
    }

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
    protected function _preparedScriptViewModelTemplate(iViewModel $result = null, $route_match = null)
    {
        $viewScriptModel = $result;

        if (! $route_match )
            // Nothing to do with viewModel!
            return $viewScriptModel;


        $routeParams = $route_match->params()->get(self::CONF_ROUTE_PARAMS, []);


        ## Achieve Template Name From Matched Route:
        #
        if ( $viewScriptModel instanceof ViewModelTemplate || $viewScriptModel instanceof DecorateViewModel)
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
        if ( isset($routeParams['final']) )
            $viewScriptModel->setFinal($routeParams['final']);


        return $viewScriptModel;
    }
}
