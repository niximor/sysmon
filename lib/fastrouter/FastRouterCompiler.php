<?php

namespace nixfw\fastrouter;

require_once "fastrouter/FastRouterRule.php";
require_once "fastrouter/RoutingException.php";


class FastRouterCompiler {
    const ROUTER_INSTANCE_ONLY_INTERFACE = 'nixfw\\fastrouter\\InstanceOnlyController';
    const ROUTER_INTERFACE = 'nixfw\\fastrouter\\Controller';

    private function __construct() {}

    /**
     * Compile pattern into rule.
     */
    public static function compileRule($pattern, $callable, $customName = NULL) {
        $compiledPattern = self::compilePattern($pattern);
        list($instance, $controller, $action) = self::verifyCallable($callable);

        // Test if callable does not allow overwriting.
        if (!is_null($action) && strpos($pattern, "<action>") !== false) {
            throw new RoutingException("Rule cannot allow overwriting action, when action is bound. ");
        } elseif (is_null($action) && strpos($pattern, "<action>") == false) {
            throw new RoutingException("Rule does not bind action and there is no <action> in URL.");
        }

        if ((!is_null($controller) || !is_null($instance)) && strpos($pattern, "<controller>") !== false) {
            throw new RoutingException("Rule cannot allow overwriting controller, when controller or instance is bound.");
        } elseif (is_null($controller) && is_null($instance) && strpos($pattern, "<controller>") == false) {
            throw new RoutingException("Rule does not bind controller and there is no <controller> in URL.");
        }

        return new FastRouterRule($compiledPattern, $pattern, $instance, $controller, $action, $customName);
    }

    public static function normalizeUrl($pattern) {
        if (substr($pattern, 0, 1) != "/") {
            $pattern = "/".$pattern;
        }

        if (substr($pattern, -1) != "/") {
            $pattern = $pattern."/";
        }

        return preg_replace('|/{2,}|', '/', $pattern);
    }

    private static function compilePattern($pattern) {
        $escaped = preg_quote(self::normalizeUrl($pattern), '#');
        $ruleRe = "#^".preg_replace('#\\\<([a-zA-Z_][a-zA-Z0-9_-]*)\\\>#', '(?P<\\1>.*?)', $escaped)."#";

        return $ruleRe;
    }

    /**
     * Test whether method in given class exists, and if it does, that it can be called from outside environment.
     */
    public static function methodIsAccessible($class, $method) {
        if (!method_exists($class, $method)) {
            return false;
        }

        $ref = new \ReflectionMethod($class, $method);
        return $ref->isPublic() && !$ref->isStatic() && !$ref->isAbstract() && !$ref->isConstructor() && !$ref->isDestructor();
    }

    /**
     * Verify callable.
     * @param mixed $callable Callable
     * @return array $instance, $controller, $action exported from callable.
     */
    protected static function verifyCallable($callable) {
        $instance = NULL;
        $controller = NULL;
        $action = NULL;

        // If callable is instance, bind that instance and require only action (as method).
        if (is_object($callable)) {
            if (is_subclass_of($callable, self::ROUTER_INSTANCE_ONLY_INTERFACE)) {
                $instance = $callable;
            } else {
                throw new RoutingException("Instance of '".get_class($callable)."' must implement '".self::ROUTER_INSTANCE_ONLY_INTERFACE."' interface.");
            }

        // If callable is a PHP-style tuple (class|instance, method)
        } elseif (is_array($callable) && count($callable) == 2) {
            if (is_object($callable[0])) {
                if (is_subclass_of($callable[0], self::ROUTER_INSTANCE_ONLY_INTERFACE)) {
                    $instance = $callable[0];
                } else {
                    throw new RoutingException("Instance of '".get_class($callable[0])."' must implement the '".self::ROUTER_INSTANCE_ONLY_INTERFACE."' interface.");
                }
            } elseif (class_exists($callable[0])) {
                if (is_subclass_of($callable[0], self::ROUTER_INTERFACE)) {
                    $controller = $callable[0];
                } else {
                    throw new RoutingException("Class '".$callable[0]."' must implement the '".self::ROUTER_INTERFACE."' interface.");
                }
            } else {
                throw new RoutingException("Class '".$callable[0]."' does not exists.");
            }

            if (self::methodIsAccessible($callable[0], $callable[1])) {
                $action = $callable[1];
            } else {
                throw new RoutingException("Method '".$callable[1]."' does not exists in class '".((is_object($callable[0]))?get_class($callable[0]):$callable[0])."'");
            }

        // If callable is class, bind that class to the controller.
        } elseif (is_string($callable) && class_exists($callable)) {
            if (is_subclass_of($callable, self::ROUTER_INTERFACE)) {
                $controller = $callable;
            } else {
                throw new RoutingException("Class '".$callable."' must implement the '".self::ROUTER_INTERFACE."' interface.");
            }

        // If callable contains controller.action pair, bind it and does not allow to override.
        } elseif (is_string($callable) && strpos($callable, ".") !== false) {
            list($myController, $myAction) = explode(".", $callable, 2);

            if (!empty($myController)) {
                if (class_exists($myController)) {
                    if (is_subclass_of($myController, self::ROUTER_INTERFACE)) {
                        $controller = $myController;
                    } else {
                        throw new RoutingException("Class '".$myController."' must implement the '".self::ROUTER_INTERFACE."' interface.");
                    }
                } else {
                    throw new RoutingException("Class '".$myController."' does not exists.");
                }
            }

            if (!empty($myAction)) {
                if (is_null($controller) || self::methodIsAccessible($controller, $myAction)) {
                    $action = $myAction;
                } else {
                    throw new RoutingException("Method '".$myAction."' does not exists in class '".$controller."'.");
                }
            }

        // All other nonempty callables
        } elseif (!is_null($callable)) {
            throw new RoutingException("Bad callable format. It's not instance, it's not a valid class and it's not class.method pair.");
        }

        // If callable is empty, it means that the rule itself must define controller and action.
        return array($instance, $controller, $action);
    }
}