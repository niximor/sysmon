<?php

namespace nixfw\fastrouter;

class FastRoute {
    private $method;
    private $kwargs;
    private $args;

    public function __construct($method, $args) {
        $this->method = $method;
        $this->args = $args;
    }

    public function getMethodCallable() {
        return $this->method;
    }

    public function getArgs() {
        return $this->args;
    }

    public function execute() {
        $callable = $this->getMethodCallable();

        if (!\is_object($callable[0])) {
            $callable[0] = new $callable[0]();
        }

        return \call_user_func_array($callable, $this->getArgs());
    }
}
