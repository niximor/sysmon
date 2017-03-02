<?php

require_once "models/Session.php";

class CurrentUser {
    public $is_logged_in = false;
    public $username = NULL;
    public $id = NULL;

    public function __construct() {
        if ($user_id = Session::get("user_id")) {
            $db = connect();
            $q = $db->query("SELECT `id`, `name` FROM `users` WHERE `id` = ".escape($db, $user_id));
            if ($a = $q->fetch_array()) {
                $this->is_logged_in = true;
                $this->username = $a["name"];
                $this->id = $a["id"];
            }
        }
    }
}
