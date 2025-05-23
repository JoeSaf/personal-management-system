<?php

namespace App\Services\Routing;

use App\Action\System\AppAction;
use App\Controller\Core\Application;
use App\Services\Core\Logger;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;

/**
 * This class handles logic for finding matching controllers/methods for given url
 *
 * Class UrlMatcherService
 * @package App\Service\Routing
 */
class UrlMatcherService
{
    const URL_MATCHER_RESULT_CONTROLLER_WITH_METHOD = "_controller";

    const ROUTE_NAME_FILES_OVERVIEW_PAGE            = "modules_my_files";
    const ROUTE_NAME_VIDEOS_OVERVIEW_PAGE           = "modules_my_video";
    const ROUTE_NAME_IMAGES_OVERVIEW_PAGE           = "modules_my_images";

    const ALL_UPLOAD_MODULES_OVERVIEW_PAGE_ROUTES = [
        self::ROUTE_NAME_FILES_OVERVIEW_PAGE,
        self::ROUTE_NAME_VIDEOS_OVERVIEW_PAGE,
        self::ROUTE_NAME_IMAGES_OVERVIEW_PAGE,
    ];

    const ROUTE_PARAM_ENCODED_SUBDIRECTORY_PATH = "encodedSubdirectoryPath";

    const URL_MATCHER_RESULT_ROUTE = "_route";

    /**
     * @var Application $app
     */
    private Application $app;

    /**
     * @var UrlMatcherInterface $urlMatcher
     */
    private UrlMatcherInterface $urlMatcher;

    /**
     * @var UrlGeneratorInterface $urlGenerator
     */
    private UrlGeneratorInterface $urlGenerator;

    public function __construct(
        UrlMatcherInterface $urlMatcher,
        Application $app,
        UrlGeneratorInterface $urlGenerator,
        private readonly Logger $logger
    )
    {
        $this->app          = $app;
        $this->urlGenerator = $urlGenerator;
        $this->urlMatcher   = $urlMatcher;
    }

    /**
     * Will return `class::method` for given url or null if nothing was found
     *
     * @param string $url
     * @return string|null
     */
    public function getClassAndMethodForCalledUrl(string $url): ?string
    {
        try{
            $normalizedUrl = $url;
            // if url contains query params then matcher just crashes
            if (str_contains($url, "?")) {
                $parts = explode("?", $url);
                $normalizedUrl = $parts[0];
            }

            $dataArray = $this->urlMatcher->match($normalizedUrl);
        }catch(Exception $e){
            $this->app->logExceptionWasThrown($e, [
                "No class with method was found for url", [
                    "url" => $url,
                    'normalizedUrl' => $normalizedUrl,
                ]
            ]);
            return null;
        }

        return $dataArray[self::URL_MATCHER_RESULT_CONTROLLER_WITH_METHOD];
    }

    /**
     * Will return `class` separated from `class::method` for given url or null if nothing was found
     *
     * @param string $url
     * @return string|null
     */
    public function getClassForCalledUrl(string $url): ?string
    {
        $classAndMethod = $this->getClassAndMethodForCalledUrl($url);
        if( empty($classAndMethod) ){
            return null;
        }

        $explodedClassAndMethod = explode("::", $classAndMethod);
        $class                  = $explodedClassAndMethod[0];

        return $class;
    }

    /**
     * Will return route name of upload based module overview page for given url
     * Null is returned if given url won't match the upload module
     *
     * @param string $url
     * @return string|null
     * @throws Exception
     */
    public function getRouteForUploadBasedModuleUrlOverviewPage(string $url): ?string
    {
        $matchingModuleMenuNodePartial = null;
        foreach(AppAction::MENU_NODES_UPLOAD_BASED_MODULES_URL_PARTIALS as $uploadBasedModuleMenuNodePartial){
            if( preg_match("#^\/?{$uploadBasedModuleMenuNodePartial}#", $url) ){
                $matchingModuleMenuNodePartial = $uploadBasedModuleMenuNodePartial;
                break;
            }
        }

        if( empty($matchingModuleMenuNodePartial) ){
            return null;
        }

        switch($matchingModuleMenuNodePartial){
            case AppAction::MENU_NODE_NAME_MY_FILES:
            {
                return self::ROUTE_NAME_FILES_OVERVIEW_PAGE;
            }

            case AppAction::MENU_NODE_NAME_MY_VIDEO:
            {
                return self::ROUTE_NAME_VIDEOS_OVERVIEW_PAGE;
            }

            case AppAction::MENU_NODE_NAME_MY_IMAGES:
            {
                return self::ROUTE_NAME_IMAGES_OVERVIEW_PAGE;
            }

            default:
                throw new Exception("This module name is not supported: {$matchingModuleMenuNodePartial}");
        }
    }

    /**
     * Will check if given url is an url of overview page of any of the upload modules
     *
     * @param string $url
     * @param string $currentDirectoryPathInModuleUploadDir
     * @return bool
     */
    public function isUrlEqualToAnyUploadModuleOverviewPage(string $url, string $currentDirectoryPathInModuleUploadDir): bool
    {
        foreach(self::ALL_UPLOAD_MODULES_OVERVIEW_PAGE_ROUTES as $routeName){
            $urlForRoute = $this->urlGenerator->generate($routeName, [
                self::ROUTE_PARAM_ENCODED_SUBDIRECTORY_PATH => urlencode($currentDirectoryPathInModuleUploadDir),
            ]);

            /**
             * Double the decode because
             * - route param encodedSubdirectory is already encoded
             * - symfony takes that param and encodes the % as well etc.
             */
            $decodedUrlForRoute = urldecode(urldecode($urlForRoute));
            if($decodedUrlForRoute === $url){
                return true;
            }
        }

        return false;
    }

    /**
     * Will return matching route for called uri
     *
     * @param string $uri
     * @return string|null
     */
    public function getRouteForCalledUri(string $uri): ?string
    {
        try{
            $uriWithoutQueryParams = preg_replace("#\?.*#", "", $uri);

            $dataArray = $this->urlMatcher->match($uriWithoutQueryParams);
            $route     = $dataArray[self::URL_MATCHER_RESULT_ROUTE];
        } catch (Exception $exc) {
            /**
             * This is added because browsers are doing {@see Request::METHOD_OPTIONS} calls by default,
             * and there is special handling of such requests in this project. Everything works fine but the OPTIONS calls
             * are running in here and are generating a bunch of useless warns.
             */
            $request = Request::createFromGlobals();
            if ($request->getMethod() !== Request::METHOD_OPTIONS) {
                $this->logger->getLogger()->warning("No route found for called uri", [
                    "uri"       => $uri,
                    "request"   => [
                        "method" => $request->getMethod(),
                    ],
                    'exception' => [
                        "message" => $exc->getMessage(),
                        "trace"   => explode("\n", $exc->getTraceAsString()),
                        "class"   => $exc::class,
                    ],

                ]);
            }

            return null;
        }

        return $route;
    }

}