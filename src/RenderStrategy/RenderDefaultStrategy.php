<?php
namespace Module\HttpRenderer\RenderStrategy;

use Module\HttpFoundation\Events\Listener\ListenerDispatch;
use Module\HttpFoundation\Events\Listener\ListenerDispatchResult;
use Module\HttpRenderer\Exceptions\ResultNotRenderableError;
use Poirot\Application\Sapi\Event\EventHeapOfSapi;

use Poirot\Events\Interfaces\iEvent;

use Poirot\Http\HttpMessage\Request\Plugin\MethodType;
use Poirot\Http\HttpMessage\Response\Plugin\Status;
use Poirot\Http\HttpResponse;
use Poirot\Http\Interfaces\iHttpRequest;
use Poirot\Http\Interfaces\iHttpResponse;
use Poirot\Ioc\instance;
use Poirot\Loader\Interfaces\iLoader;
use Poirot\Loader\LoaderAggregate;
use Poirot\Loader\LoaderNamespaceStack;
use Poirot\Router\Interfaces\iRouterStack;
use Poirot\Router\RouterStack;

use Poirot\Std\Environment\EnvServerDefault;
use Poirot\Std\Environment\FactoryEnvironment;
use Poirot\Std\Struct\CollectionPriority;
use Poirot\Std\Type\StdArray;
use Poirot\View\aViewModel;
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
    /** @var iHttpResponse */
    protected $response;
    /** @var iViewModelPermutation View script model */
    protected $scriptView;
    /** @var iViewModelPermutation */
    protected $templateView;
    /** @var LoaderAggregate */
    protected $viewResolver;

    protected $defaultLayout = 'default';
    protected $config;
    protected $themesQueue;
    protected $themes_loaded = [];


    /**
     * Construct.
     *
     * @param iHttpRequest       $request      @IoC /httpRequest
     * @param iHttpResponse|null $response     @IoC /httpResponse
     * @param iViewModel|null    $scriptView   @IoC /ViewModel
     * @param iLoader|null       $viewResolver @IoC /ViewModelResolver
     */
    function __construct(
        iHttpRequest $request
        , iHttpResponse $response = null
        , iViewModel $scriptView = null
        , iLoader $viewResolver = null
    ) {
        $this->setRequest($request);

        if (null !== $response)
            $this->setResponse($response);

        if (null !== $scriptView)
            $this->setScriptViewModel($scriptView);

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
            ## give themes and initialize
            ->on(
                EventHeapOfSapi::EVENT_APP_BOOTSTRAP
                , function ()
                {
                    $this->_loadThemesIntoQueue(false);
                }
                , 1000
            )
            ## give themes and initialize
            ->on(
                EventHeapOfSapi::EVENT_APP_DISPATCH
                , function ()
                {
                    ## because we might need to access render from within the actions
                    #
                    $this->_loadThemesIntoQueue(true);
                    $this->_ensureThemesAvailability();
                }
                , 1000
            )

            ## create view model from string result
            ->on(
                EventHeapOfSapi::EVENT_APP_RENDER
                , function ($result = null, $route_match = null)
                {
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
                , function ($result = null)
                {
                    if (! ($this->shouldSkipRenderer() || $this->isRenderable($result)) )
                        return false;

                    // change the "result" param value inside event
                    return [
                        ListenerDispatchResult::RESULT_DISPATCH =>  $this->_injectToLayoutDecorator($result)
                    ];
                }
                , self::PRIORITY_DECORATE_VIEWMODEL_LAYOUT
            )
            ## ensure themes viewResolver
            ->on(
                EventHeapOfSapi::EVENT_APP_RENDER
                , function ()
                {
                    $this->_ensureThemesAvailability();
                }
                , self::PRIORITY_DECORATE_VIEWMODEL_LAYOUT+1
            )

            ## handle error pages
            ->on(
                EventHeapOfSapi::EVENT_APP_ERROR
                , function ($exception = null, $event = null)
                {
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
        # View Script Model Template
        #
        if ( null === $errorTemplate = $this->_attainViewScriptTemplateOfError($exception) )
            throw new \Exception(sprintf(
                'Cant find error template for exception (%s).'
                , get_class($exception)
            ));


        $scriptViewModel = clone $this->getScriptViewModel();
        $scriptViewModel->setTemplate(is_string($errorTemplate) ? $errorTemplate : $errorTemplate[0]);


        # Error Layout Template
        #
        $layoutViewModel = clone $this->getLayoutViewModel();
        $layoutTemplate  = is_string($errorTemplate) ? $this->_attainLayoutTemplate($errorTemplate) : $errorTemplate[1];

        if ($layoutTemplate)
            $layoutViewModel->setTemplate($layoutTemplate);
        else
            ## just render view script and disable layout template
            $scriptViewModel->setFinal();


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


        // TODO Response Aware Exception; map exception code to response code aware and more; include headers
        //      Might be good to attach it to finish event
        $exception_code = $exception->getCode();

        /** @var iHttpResponse $response */
        $response = $this->getResponse();
        if (Status::_($response)->isSuccess()) {
            if (! (is_numeric($exception_code)
                && $exception_code > 100
                && $exception_code <= 600
            ))
                $exception_code = 500;

            $response->setStatusCode(($exception_code) ? $exception_code : 500);
        }


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


    // Implement configurable:

    /**
     * Build Object With Provided Options
     *
     * @param array $options Associated Array
     * @param bool $throwException Throw Exception On Wrong Option
     *
     * @return $this
     * @throws \Exception
     * @throws \InvalidArgumentException
     */
    function with(array $options, $throwException = false)
    {
        if (! $this->config)
            $this->config = new StdArray;

        $this->config = $this->config->withMergeRecursive(new StdArray($options));
        return $this;
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

    /**
     * Set Response
     *
     * @param iHttpResponse $response
     *
     * @return $this
     */
    function setResponse(iHttpResponse $response)
    {
        $this->response = $response;
        return $this;
    }

    /**
     * Get Response Object
     *
     * @return iHttpResponse
     */
    function getResponse()
    {
        if (null === $this->response)
            $this->setResponse(new HttpResponse);

        return $this->response;
    }


    // ..

    /**
     * Give Theme To Queue From Conf.
     *
     * ! if not invokeWhen then just give default themes on bootstrap
     *
     * @param boolean $invokeWhen
     * @throws \Exception
     */
    protected function _loadThemesIntoQueue($invokeWhen)
    {
        $queue  = $this->_getThemesQueue();
        $config = $this->_getConf('themes');

        foreach ($config as $name => $settings)
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

    protected function _ensureThemesAvailability()
    {
        $viewModelResolver = $this->getViewResolver();

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

            } elseif (
                is_array($result)
                || $result instanceof \Traversable
            ) {
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
            $viewModel = $this->_preScriptViewModelTemplate($viewModel, $route_match);

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


        // ...

        $viewAsTemplate = $viewModel;

        foreach (clone $this->themesQueue as $theme)
        {
            /** @var DecorateViewModel $viewAsTemplate */
            $viewAsTemplate = clone $this->getLayoutViewModel();

            ## default layout if template view has no template
            $layout = ( $viewAsTemplate->getTemplate() ) ?: $theme->layout['default'];

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
    protected function _preScriptViewModelTemplate(iViewModel $result = null, $route_match = null)
    {
        $viewScriptModel = $result;
        $routeParams     = ($route_match) ? $route_match->params()->get(self::CONF_ROUTE_PARAMS) : null;


        ## Achieve Template Name From Matched Route:
        #
        if ( $route_match &&
            ($viewScriptModel instanceof ViewModelTemplate || $viewScriptModel instanceof DecorateViewModel)
        ) {
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
        if ($route_match && $viewScriptModel instanceof aViewModel) {
            (! isset($routeParams['final']) ) ?: $viewScriptModel->setFinal($routeParams['final']);
        }

        return $viewScriptModel;
    }

    protected function _attainViewScriptTemplateOfError($exception)
    {
        $exceptionTemplate = null;
        foreach (clone $this->_getThemesQueue() as $theme)
        {
            $templates = @$theme->layout['exception'];
            $exClass = get_class($exception);
            while($exClass) {
                if (isset($templates[$exClass])) {
                    $exceptionTemplate = $templates[$exClass];
                    break;
                }

                $exClass = get_parent_class($exClass);
            }

            if ( isset($exceptionTemplate) )
                break;
        }

        return $exceptionTemplate;
    }

    protected function _attainLayoutTemplate($errTemplate)
    {
        if (!is_string($errTemplate) && isset($errTemplate[1]) )
            // '\Exception' => ['error/error', 'blank'],
            return $errTemplate[1];


        $exceptionTemplate = null;
        foreach (clone $this->_getThemesQueue() as $theme)
        {
            $templates = @$theme->layout;
            if ( isset($templates['default']) )
                // here (blank) is defined as default layout for all error pages
                // 'layout' => [
                //   'default' => 'blank',
                //   ...
                $exceptionTemplate = $templates['default'];
            break;
        }

        return $exceptionTemplate;
    }

    /**
     * Give Matched Themes Queue
     *
     * @return CollectionPriority
     */
    protected function _getThemesQueue()
    {
        if (! $this->themesQueue )
            $this->themesQueue = new CollectionPriority;

        return $this->themesQueue;
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
        $config = $this->config;

        foreach (func_get_args() as $key) {
            if (! isset($config[$key]) )
                return null;

            $config = $config[$key];
        }

        return $config;
    }
}
