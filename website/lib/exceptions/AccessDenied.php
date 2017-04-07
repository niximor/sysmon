<?php

class AccessDenied extends Exception {
    public $action;

    public function __construct($action) {
        $this->action = $action;
        parent::__construct("Access denied.");
    }
}
