<?php
class Template
{
    private $template;
    private $page;
    private $stylesheets;
    private $scripts;
    private $stylesheets_route;
    private $scripts_route;
    private $hooks;
    private $plugins;

    public function __construct($template, $page, $hooks, $plugins, $stylesheets_route, $scripts_route)
    {
        $this->template          = $template;
        $this->page              = $page;
        $this->hooks             = $hooks;
        $this->plugins           = $plugins;
        $this->stylesheets_route = $stylesheets_route;
        $this->scripts_route     = $scripts_route;
    }

    public function addStylesheet($styleArray, $base_path)
    {
        if (is_array($styleArray)) {
            foreach ($styleArray as $style) {
                $this->stylesheets[] = $this->parseStylesheet($style, $base_path);
            }
        } else {
            $this->stylesheets[] = $this->parseStylesheet($styleArray, $base_path);
        }
    }

    public function parseStylesheet($style, $base_path)
    {
        $this->hooks->fire("hook_parseStylesheet", array(&$style, $base_path));

        if (!filter_var($style, FILTER_VALIDATE_URL)) {
            if ($style[0] != "/") {
                $style = strReplaceFirst(ROOT, BASE_URL, $base_path) . "/" . $style;
            }
        }

        return $style;
    }

    public function parseScript($script, $base_path)
    {
        if (!filter_var($script, FILTER_VALIDATE_URL)) {
            if ($script[0] != "/") {
                $script = strReplaceFirst(ROOT, BASE_URL, $base_path) . "/" . $script;
            }
        }
        return $script;
    }

    public function addScript($scriptArray, $base_path)
    {
        if (is_array($scriptArray)) {
            foreach ($scriptArray as $script) {
                $this->scripts[] = $this->parseScript($script, $base_path);
            }
        } else {
            $this->scripts[] = $this->parseScript($scriptArray, $base_path);
        }
    }

    /**
     * Set Variables *
     */
    public function set($name, $value)
    {
        $this->variables[$name] = $value;
    }

    /* get all javascript and css files to be included */
    public function includeScriptsCss()
    {
        foreach ($this->stylesheets_route as $styleArray) {
            $this->addStylesheet($styleArray['value'], $styleArray['path']);
        }
        foreach ($this->scripts_route as $scriptArray) {
            $this->addScript($scriptArray['value'], $scriptArray['path']);
        }
        /* remove duplicates */
        $this->stylesheets = array_unique($this->stylesheets);
        $this->scripts     = array_unique($this->scripts);
    }

    /**
     * Display Template *
     */
    public function render()
    {
        if (!empty($this->variables)) {
            extract($this->variables);
        }

        /* get all javascript and css files to be included */
        $this->includeScriptsCss();

        /* include the start of the html page */
        require_once ROOT . "/core/include/start_page.php";

        chdir($this->page['path']);
        ob_start();
        call_user_func(function () {
            if (!empty($this->variables)) {
                extract($this->variables);
            }
            /* no need to check if file exists, as we already did that in the router */
            require_once ($this->page['value']);
        });
        $page = ob_get_contents();
        ob_end_clean();
        $this->set('page', $page);

        chdir($this->template['path']);
        if (file_exists($this->template['value'])) {
            /* call anonymous function to hide variables */
            call_user_func(function () {
                extract($this->variables);
                require_once ($this->template['value']);
            });
        } else {
            echo $page;
        }
        /* include the end of the html page */
        require_once ROOT . "/core/include/end_page.php";
    }

}
