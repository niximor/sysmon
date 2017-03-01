<?php

namespace nixfw\fastrouter;

require_once "fastrouter/InstanceOnlyController.php";

/**
 * Controller can be bound to patterns based on class name, but it must
 * define empty constructor.
 */
interface Controller extends InstanceOnlyController {
    public function __construct();
}
