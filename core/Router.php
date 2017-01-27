<?php
namespace Leap\Core;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Class Router
 *
 * @package Leap\Core
 */
class Router
{
    /**
     * @var array
     */
    public $routeCollection;
    /**
     * @var \Leap\Core\PluginManager
     */
    private $pluginManager;
    /**
     * @var array
     */
    private $defaultValues;
    /**
     * @var array
     */
    private $replaceWildcardArgs;

    /**
     * Router constructor.
     */
    public function __construct()
    {
        $this->routeCollection = [];
        $this->defaultValues   = [];
    }

    /**
     * Setter injection for a Leap plugin manager instance
     *
     * @param \Leap\Core\PluginManager $pluginManager
     */
    public function setPluginManager(PluginManager $pluginManager): void
    {
        $this->pluginManager = $pluginManager;
    }

    /**
     * Add a new file with routes
     *
     * @param string $file
     * @param string $pluginForNamespace
     */
    public function addRouteFile(string $file, string $pluginForNamespace): void
    {
        if (file_exists($file)) {
            $routes = require $file;
            $path   = str_replace("\\", "/", dirname($file)) . "/";
            foreach ($routes as $route => $options) {
                // Multi-value keys seperation
                $multi_regex = explode(",", $route);
                foreach ($multi_regex as $sep_route) {
                    $this->addRoute($sep_route, $options, $path, $pluginForNamespace);
                }
            }
        }
    }

    /**
     * Add a new route to the route collection
     *
     * @param      $route
     * @param      $options
     * @param      $pluginForNamespace
     * @param      $path
     */
    public function addRoute(string $route, array $options, string $path = ROOT, string $pluginForNamespace = NULL): void
    {
        $route = trim($route, "/");
        if (isset($this->pluginManager) && isset($options['dependencies'])) {
            $error = "";
            foreach ($options['dependencies'] as $plugin) {
                if (!$this->pluginManager->isEnabled($plugin)) {
                    $error .= "need plugin " . $plugin . " for route \n";
                }
            }
            if ($error != "") {
                return;
            }
        }
        foreach ($options as $option => $value) {
            if ($option == "method") {
                $options[$option] = [];
                /* TODO: change delimiter to | instead of , (problem: parsed as integer) */
                foreach (explode(",", $value) as $method) {
                    $options[$option][] = trim(strtoupper($method));
                }
            }
        }
        if (!isset($options['path'])) {
            $options['path'] = $path;
        }
        if (!isset($options['plugin']) && isset($pluginForNamespace)) {
            $options['plugin'] = $pluginForNamespace;
        }
        if (isset($this->routeCollection[$route])) {
            // Merge previous options with the new options
            $this->routeCollection[$route] = array_replace($this->routeCollection[$route], $options);
        } else {
            // New route: simply add the options
            $this->routeCollection[$route] = $options;
        }
    }

    /**
     * Route a given url based on the added route files
     *
     * @param string $uri
     * @param string $method
     *
     * @return \Leap\Core\Route
     */
    public function matchUri(string $uri, string $method = 'GET'): Route
    {
        $uri = trim($uri, "/");

        // Sort route array
        $this->routeCollection = $this->sortRoutes($this->routeCollection);

        $parsedRoute = new Route();

        // Try to match url to one or multiple routes
        foreach ($this->routeCollection as $pattern => $options) {
            $orginalPattern = $pattern;
            $include_slash  = (isset($options['include_slash']) && $options['include_slash']);

            $wildcard_args = [];
            // Search for wildcard arguments
            if (strpos($pattern, "{") !== false) {
                if (preg_match_all("/{(.*?)}/", $pattern, $matches)) {
                    foreach ($matches[0] as $key => $whole_match) {
                        $wildcard_args['pattern'] = str_replace('\{' . $matches[1][$key] . '\}', "([^/]+)", '#^' . preg_quote(trim($pattern), '#') . '$#i');
                        $pattern                  = str_replace($whole_match, "+", $pattern);
                        $wildcard_args['args'][]  = $matches[1][$key];
                    }
                }
            }
            $pattern = $this->getPregPattern($pattern, $include_slash);
            if (preg_match($pattern, $uri)) {
                if (!isset($options['method']) || in_array($method, $options['method'])) {
                    /* We found at least one valid route */
                    $this->parseRoute($options, $uri, $wildcard_args, $parsedRoute, $orginalPattern);
                }
            }
        }

        return $parsedRoute;
    }

    /**
     * Route a PSR-7 Request based on the added route files
     *
     * @param ServerRequestInterface $request
     *
     * @return Route
     */
    public function match(ServerRequestInterface $request): Route
    {
        return $this->matchUri($request->getUri()->getPath(), $request->getMethod());
    }

    /**
     * Sort route array by weight first, then by length of route (key)
     *
     * @param array $routes
     *
     * @return array
     */
    private function sortRoutes(array $routes): array
    {
        $weight      = [];
        $routeLength = [];
        foreach ($routes as $key => $value) {
            if (isset($value['weight'])) {
                $weight[] = $value['weight'];
            } else {
                $weight[] = 1;
            }
            $routeLength[] = strlen($key);
        }
        /* TODO: check overhead for fix for array_multisort who re-indexes numeric keys */
        $orig_keys = array_keys($routes); // Fix for re-indexing of numeric keys
        array_multisort($weight, SORT_ASC, $routeLength, SORT_ASC, $routes, $orig_keys);
        return array_combine($orig_keys, $routes); // Fix for re-indexing of numeric keys
    }

    /**
     * Get regex pattern for preg* functions based on fnmatch function pattern
     *
     * @param      $pattern
     * @param bool $include_slash
     *
     * @return string
     */
    private function getPregPattern(string $pattern, bool $include_slash = false): string
    {
        $transforms = [
            '\*'   => '[^/]*',
            '\+'   => '[^/]+',
            '\?'   => '.',
            '\[\!' => '[^',
            '\['   => '[',
            '\]'   => ']'
        ];

        // Forward slash in string must be in pattern:
        if ($include_slash) {
            $transforms['\*'] = '.*';
        }

        return '#^' . strtr(preg_quote(trim($pattern), '#'), $transforms) . '$#i';
    }

    /**
     * Parse a route from a route file
     *
     * @param $route
     * @param $url
     * @param $wildcard_args
     */
    private function parseRoute(array $route, string $url, array $wildcard_args, Route $parsedRoute, string $pattern): void
    {
        $parsedRoute->mathedRoutes[$pattern] = $route;
        $parsedRoute->base_path              = $route['path'];
        if (!empty($wildcard_args)) {
            if (preg_match_all($wildcard_args['pattern'], $url, $matches)) {
                $this->replaceWildcardArgs = [];
                global $wildcards_from_url;
                foreach ($matches as $key => $arg) {
                    if (!$key) {
                        continue;
                    }
                    $this->replaceWildcardArgs["{" . $wildcard_args['args'][$key - 1] . "}"] = $arg[0];
                    $wildcards_from_url[$wildcard_args['args'][$key - 1]]                    = $arg[0];
                }
            }
        }

        if (isset($route['clear'])) {
            $parsedRoute->defaultRouteValues($route['clear']);
        }

        if (isset($route['callback'])) {
            if (is_callable($route['callback'])) {
                $parsedRoute->callback = $route['callback'];
            } else {
                $parsedRoute->callback = [];
                $parts                 = explode('@', $route['callback']);

                $parsedRoute->callback['class'] = $this->replaceWildcardArgs($parts[0]);
                $action                         = null;
                if (isset($parts[1])) {
                    $action = $this->replaceWildcardArgs($parts[1]);
                }
                $parsedRoute->callback['action'] = $action;
            }
        }
        if (isset($route['page'])) {
            $parsedRoute->page          = [];
            $parsedRoute->page['value'] = $this->replaceWildcardArgs($route['page']);
            if ($parsedRoute->page['value'][0] == "/") {
                $parsedRoute->page['value'] = substr($parsedRoute->page['value'], 1);
                $parsedRoute->page['path']  = ROOT;
            } else {
                $parsedRoute->page['path'] = $route['path'];
            }
        }
        if (isset($route['template'])) {
            $parsedRoute->template          = [];
            $parsedRoute->template['value'] = $this->replaceWildcardArgs($route['template']);
            if ($parsedRoute->template['value'][0] == "/") {
                $parsedRoute->template['value'] = substr($parsedRoute->template['value'], 1);
                $parsedRoute->template['path']  = ROOT;
            } else {
                $parsedRoute->template['path'] = $route['path'];
            }
        }

        if (isset($route['title'])) {
            $parsedRoute->title = $this->replaceWildcardArgs($route['title']);
        }
        if (isset($route['stylesheets'])) {
            $parsedRoute->stylesheets[] = ["value" => $route['stylesheets'], "path" => $route['path']];
        }
        if (isset($route['scripts'])) {
            $parsedRoute->scripts[] = ["value" => $route['scripts'], "path" => $route['path']];
        }
    }

    /**
     * @param string $string
     *
     * @return string
     */
    private function replaceWildcardArgs(?string $string): ?string
    {
        if (!empty($this->replaceWildcardArgs)) {
            return strtr($string, $this->replaceWildcardArgs);
        } else {
            return $string;
        }
    }
}
