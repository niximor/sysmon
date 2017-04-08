<?php

require_once "exceptions/AccessDenied.php";

class ActionAccessDenied extends AccessDenied {
    public $action;

    public function __construct($action) {
        $this->action = $action;
        parent::__construct("Access denied.");
    }
}
