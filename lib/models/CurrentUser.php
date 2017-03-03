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

            $ip = $_SERVER["REMOTE_ADDR"];
            if (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
                $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
            }

            $lifetime = Session::getLifetime("user_id");

            Session::set("ip", $ip, $lifetime);
            Session::set("ua", $_SERVER["HTTP_USER_AGENT"], $lifetime);
        }
    }
}
