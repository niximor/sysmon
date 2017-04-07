<?php

require_once "models/Session.php";

class CurrentUser {
    public $is_logged_in = false;
    public $username = NULL;
    public $id = NULL;
    public $actions = [];

    private static $instance;

    public static function i() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        self::$instance = $this;

        if ($user_id = Session::get("user_id")) {
            $db = connect();
            $q = $db->query("SELECT `id`, `name` FROM `users` WHERE `id` = ".escape($db, $user_id)) or fail($db->error);
            if ($a = $q->fetch_array()) {
                $this->is_logged_in = true;
                $this->username = $a["name"];
                $this->id = $a["id"];
            }

            $q = $db->query("SELECT
                    `a`.`id`,
                    `a`.`name`,
                    `a`.`parent_id`,
                    IF(MAX(`ur`.`user_id`) IS NOT NULL, 1, 0) AS `selected`
                FROM `actions` `a`
                LEFT JOIN `role_actions` `ra` ON (`ra`.`action_id` = `a`.`id`)
                LEFT JOIN `user_roles` `ur` ON (`ra`.`role_id` = `ur`.`role_id` AND `ur`.`user_id` = ".escape($db, $this->id).")
                GROUP BY `a`.`id`
                ORDER BY `a`.`parent_id` ASC");

            $actions = [];
            while ($a = $q->fetch_assoc()) {
                $a["selected"] = (bool)$a["selected"];
                $actions[$a["id"]] = $a;
            }

            foreach ($actions as $a) {
                if ($a["selected"]) {
                    $parent = $a["parent_id"];
                    while (!is_null($parent)) {
                        $actions[$parent]["selected"] = true;
                        $parent = $actions[$parent]["parent_id"];
                    }
                }
            }

            foreach ($actions as $a) {
                if ($a["selected"]) {
                    $this->actions[] = $a["name"];
                }
            }

            $ip = $_SERVER["REMOTE_ADDR"];
            if (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
                $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
            }

            $lifetime = Session::getLifetime("user_id");

            Session::set("ip", $ip, $lifetime);
            Session::set("ua", $_SERVER["HTTP_USER_AGENT"], $lifetime);

            $db->query("UPDATE `users` SET `last_login` = NOW() WHERE `id` = ".escape($db, $user_id)) or fail($db->error);
            $db->commit();
        }
    }

    public function hasAction($name) {
        return in_array($name, $this->actions);
    }

    public function hasAnyAction(...$actions) {
        foreach ($actions as $action) {
            if (in_array($action, $this->actions)) {
                return true;
            }
        }

        return false;
    }
}
