<?php

namespace nixfw\fastrouter;

require_once "fastrouter/RoutingException.php";
require_once "fastrouter/FastRouterCompiler.php";

/**
 * Fast URL router that does not do any magic, and expects more strict URL scheme.
 */
class FastRouter {
    private $rules = [];
    private $urlCache;

    public function bind($pattern, $callable = NULL, $customName = NULL) {
        $rule = FastRouterCompiler::compileRule($pattern, $callable, $customName);
        $this->rules[] = $rule;

        return $this;
    }

    public function resolve($url) {
        $url = FastRouterCompiler::normalizeUrl($url);

        foreach ($this->rules as $rule) {
            if ($resp = $rule->test($url)) {
                return $resp;
            }
        }

        return false;
    }

    public function urlFor($callback, $params = array()) {
        $instance = NULL;
        $controller = NULL;
        $action = NULL;

        // Decode controller and action from callback.
        if (is_array($callback) && count($callback) == 2) {
            if (is_object($callback[0])) {
                $instance = $callback[0];
            } elseif (is_string($callback[0])) {
                $controller = $callback[0];
            } else {
                throw new RoutingException("Invalid callback specified for urlFor.");
            }

            if (is_string($callback[1])) {
                $action = $callback[1];
            } else {
                throw new RoutingException("Invalid callback specified for urlFor.");
            }
        } elseif (is_string($callback)) {
            list($controller, $action) = explode(".", $callback, 2);
            if (empty($controller) || empty($action)) {
                throw new RoutingException("Invalid callback specified for urlFor.");
            }
        } else {
            throw new RoutingException("Invalid callback specified for urlFor.");
        }

        $obj = (!is_null($instance))?spl_object_hash($instance):$controller;

        $sortedKeys = array_keys($params);
        sort($sortedKeys);

        $cacheKey = sha1($obj.":".$action.":".implode(":", $sortedKeys));

        $args = array();
        $kwargs = array();

        foreach ($params as $key => $val) {
            if (is_numeric($key)) {
                $args[] = rawurlencode($val);
            } else {
                $kwargs[$key] = rawurlencode($val);
            }
        }

        if (isset($this->urlCache[$cacheKey])) {
            return $this->urlCache[$cacheKey]->urlFor($instance, $controller, $action, $args, $kwargs);
        }

        foreach ($this->rules as $rule) {
            if ($rule->hasUrlFor($instance, $controller, $action, $args, $kwargs)) {
                $this->urlCache[$cacheKey] = $rule;
                return $rule->urlFor($instance, $controller, $action, $args, $kwargs);
            }
        }

        throw new RoutingException("Don't have any binding for specified callback. Can't generate url.");
    }
}
