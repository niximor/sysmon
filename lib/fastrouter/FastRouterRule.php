<?php

namespace nixfw\fastrouter;

require_once "fastrouter/FastRouterCompiler.php";
require_once "fastrouter/FastRoute.php";

/**
 * Rule for matching.
 */
class FastRouterRule {
    private $pattern;
    private $originalPattern;

    private $controller;
    private $action;
    private $instance;

    public function __construct($pattern, $originalPattern, $instance, $controller, $action, $customName = NULL) {
        $this->pattern = $pattern;
        $this->originalPattern = $originalPattern;

        $this->instance = $instance;
        $this->controller = $controller;
        $this->action = $action;

        $this->customName = $customName;
    }

    public function __toString() {
        $str = "FastRouterRule(".$this->originalPattern." for ";

        if (!is_null($this->instance)) {
            $str .= " instance of ".get_class($this->instance);
        }

        if (!is_null($this->controller)) {
            $str .= " ".$this->controller;
        }

        if (!is_null($this->action)) {
            $str .= ".".$this->action;
        }

        return $str;
    }

    public function test($url) {
        //echo "Test ".$this->originalPattern."<br />";

        $resp = $this->testPattern($url);
        if (!$resp) {
            //echo "fail 1<br />";
            return false;
        }

        list($rule, $params) = $resp;

        // Make copy of variables, that can be altered.
        $instance = $this->instance;
        $controller = $this->controller;
        $action = $this->action;

        if (isset($rule["controller"])) {
            if (class_exists($rule["controller"]) && is_subclass_of($rule["controller"], FastRouterCompiler::ROUTER_INTERFACE)) {
                $controller = $rule["controller"];
            } else {
                // It is not error when specified controller does not exists. It allows to fall down to another
                // rules.
                //echo "fail 2<br />";
                return false;
            }

            unset($rule["controller"]);
        }

        if (isset($rule["action"])) {
            if (!is_null($instance) && FastRouterCompiler::methodIsAccessible($instance, $rule["action"])) {
                $action = $rule["action"];
            } elseif (!is_null($controller) && FastRouterCompiler::methodIsAccessible($controller, $rule["action"])) {
                $action = $rule["action"];
            } else {
                // It is not error when specified method does not exists. It allows to fall down to another rules.
                //echo "fail 3<br />";
                return false;
            }

            unset($rule["action"]);
        }

        // Get number of non-default arguments for a function, and test whether it is possible to call this method.
        $compiledParams = array();
        $paramIndex = 0;

        $refl = new \ReflectionMethod((!is_null($instance))?$instance:$controller, $action);
        foreach ($refl->getParameters() as $param) {
            if (isset($rule[$param->getName()])) {
                $compiledParams[] = $rule[$param->getName()];
            } elseif ($param->isVariadic()) {
                // Variadic parameter eats all.
                while (isset($params[$paramIndex])) {
                    $compiledParams[] = $params[$paramIndex];
                    $paramIndex++;
                }
            } elseif (isset($params[$paramIndex])) {
                $compiledParams[] = $params[$paramIndex];
                ++$paramIndex;
            } elseif (!$param->isOptional()) {
                // No kwarg, no regular parameter, we cannot match this method.
                //echo "fail 4<br />";
                return false;
            } else {
                // Optional param, use it's default value, because we need to
                // provide support for kwargs.
                $compiledParams[] = $param->getDefaultValue();
            }
        }

        // Too many arguments for method.
        if ($paramIndex < count($params)) {
            //echo "fail 5<br />";
            return false;
        }

        return new FastRoute(array((!is_null($instance))?$instance:$controller, $action), $compiledParams);
    }

    /**
     * Test whether the rule matches specified URL.
     */
    protected function testPattern($url) {
        if (preg_match($this->pattern, $url, $matches)) {
            $matched_part = $matches[0];

            $rule = array();

            foreach ($matches as $key => $value) {
                if (is_numeric($key)) continue;

                // If there is an empty match, the URL is not matched at all.
                if (empty($value)) {
                    return false;
                }

                $rule[$key] = $value;
            }

            $rest = substr($url, strlen($matched_part));
            $rest = preg_replace('#^/|/$#', '', $rest);

            if (empty($rest)) {
                $params = array();
            } else {
                $params = preg_split('#[/]+#', $rest);
            }

            return array($rule, $params);
        } else {
            return false;
        }
    }

    /**
     * This method tests, whether this rule can generate URL for given arguments. It does not generate the URL
     * itself, only does all the checks.
     */
    public function hasUrlFor($instance, $controller, $action, $args, $kwargs) {
        // Custom name for controller is substitued with actual controller for this rule.
        if (!is_null($this->customName) && $controller == $this->customName) {
            $controller = NULL;

            if (!is_null($this->controller)) {
                $controller = $this->controller;
            } elseif (!is_null($this->instance)) {
                $instance = $this->instance;
            }
        }

        // Try to match controller part.
        if (!is_null($instance)) {
            if (is_null($this->instance) || $this->instance !== $instance) {
                // Given instance is different.
                return false;
            }

            // For further examination, we need to handle uniformly both instance and controller,
            // so it is safe to overwrite controller here.
            $controller = $instance;
        } elseif (!is_null($controller)) {
            if (is_null($this->controller) && strpos($this->originalPattern, "<controller>") === false) {
                // Controller is not bound and not in URL either.
                return false;
            } elseif (!is_null($this->controller) && $this->controller != $controller) {
                // Controller is bound, but not the one that was requested.
                return false;
            }
        }

        if (!is_null($this->action) && $action != $this->action) {
            // Requested other action than specified in the rule.
            return false;
        }

        // Try to match method part.
        if (!FastRouterCompiler::methodIsAccessible($controller, $action)) {
            // Requested method is not callable.
            return false;
        }

        // Test number of arguments.
        $refl = new \ReflectionMethod($controller, $action);

        $hasVariadicArgs = false;
        $kwArgsForParams = $kwargs;

        reset($args);
        foreach ($refl->getParameters() as $param) {
            if ($param->isVariadic()) {
                $hasVariadicArgs = true;
            }

            if (!isset($kwArgsForParams[$param->getName()]) && !each($args)) {
                if (!$param->isOptional()) {
                    // Requested method has too much parameters.
                    return false;
                }
            } elseif (isset($kwArgsForParams[$param->getName()])) {
                unset($kwArgsForParams[$param->getName()]);
            }
        }

        if (!empty($kwArgsForParams)) {
            // Unknown keyword argument that was not specified for given method.
            return false;
        }

        if (each($args) !== false && !$hasVariadicArgs) {
            // Method does not have enough parameters.
            return false;
        }

        // Here we know, the URL can be generated. So we only need to generate the URL from original pattern.
        return true;
    }

    /**
     * This method only generates URL, and does not do any checking. It is complement to the
     * hasUrlFor method, which only does checking and does not generate anything.
     * The url resolution is splitted to two parts because of possible caching of resolved rules for given arguments.
     */
    public function urlFor($instance, $controller, $action, $args, $kwargs) {
        // We could use strtr here, it would probably be better, but acording to benchmarking,
        // str_replace is faster.
        $url = str_replace("<controller>", (!is_null($instance))?get_class($instance):$controller, $this->originalPattern);
        $url = str_replace("<action>", $action, $url);

        foreach ($kwargs as $key => $val) {
            $url = str_replace("<".$key.">", $val, $url);
        }

        $url .= "/".implode("/", $args);

        return FastRouterCompiler::normalizeUrl($url);
    }

    public function getInstance() {
        return $this->instance;
    }

    public function getController() {
        return $this->controller;
    }

    public function getAction() {
        return $this->action;
    }
}