<?php

require_once "controllers/TemplatedController.php";

class UsersController extends TemplatedController {
    public function index() {
        $this->requireAction("users_read");

        $db = connect();

        $order = "name";
        $direction = "ASC";

        if (isset($_REQUEST["name"]) && in_array($_REQUEST["name"], ["name", "last_login"])) {
            $order = $_REQUEST["name"];
        }

        if (isset($_REQUEST["direction"]) && in_array($_REQUEST["direction"], ["ASC", "DESC"])) {
            $direction = $_REQUEST["direction"];
        }

        $q = $db->query("SELECT `id`, `name`, `last_login` FROM `users` ORDER BY `".$order."` ".$direction);
        $users = [];
        while ($a = $q->fetch_assoc()) {
            if (!is_null($a["last_login"])) {
                $a["last_login"] = DateTime::createFromFormat("Y-m-d G:i:s", $a["last_login"]);
            }

            $users[] = $a;
        }

        return $this->renderTemplate("settings/users/index.html", [
            "users" => $users
        ]);
    }

    public function add() {
        $this->requireAction("users_write");

        $db = connect();

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $salt = hash("sha256", ((string)time())."-".((string)mt_rand()));
            $password = hash("sha256", $salt.$_POST["password"]);

            $db->query("INSERT INTO `users` (`name`, `salt`, `password`) VALUES
                (".escape($db, $_POST["name"]).", ".escape($db, $salt).", ".escape($db, $password).")") or fail($db->error);
            $user_id = $

            $to_insert = [];
            foreach ($_POST["roles"] as $id => $dummy) {
                $to_insert[] = $id;
            }

            if (!empty($to_insert)) {
                $db->query("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES ".implode(",", array_map(
                    function($role_id) use ($user_id, $db) {
                        return "(".escape($db, $user_id).", ".escape($db, $role_id).")";
                    }))) or fail($db->error);
            }

            $db->commit();

            Message::create(Message::SUCCESS, "User has been created.");

            header("Location: ".twig_url_for(["UsersController", "index"]));
            exit;
        }

        return $this->renderTemplate("settings/users/add.html", [
            "roles" => $this->selectRoles($db)
        ]);
    }

    public function detail($id) {
        $this->requireAction("users_read");

        $db = connect();

        $q = $db->query("SELECT `id`, `name`, `last_login` FROM `users` WHERE `id` = ".escape($db, $id)) or fail($db->error);
        $user = $q->fetch_assoc();

        if (!is_null($user["last_login"])) {
            $user["last_login"] = DateTime::createFromFormat("Y-m-d G:i:s", $user["last_login"]);
        }

        if (!$user) {
            throw new EntityNotFound("Specified user was not found.");
        }

        return $this->renderTemplate("settings/users/detail.html", [
            "roles" => $this->selectRoles($db, $id, true),
            "actions" => $this->selectActions($db, $id),
            "user" => $user,
        ]);
    }

    public function edit($id) {
        $this->requireAction("users_write");

        $db = connect();

        $q = $db->query("SELECT `id`, `name` FROM `users` WHERE `id` = ".escape($db, $id)) or fail($db->error);
        $user = $q->fetch_assoc();

        if (!$user) {
            throw new EntityNotFound("Specified user was not found.");
        }

        $roles = $this->selectRoles($db, $user["id"]);

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $to_update = [];

            $to_update["name"] = $_POST["name"];

            if (!empty($_POST["password"])) {
                $to_update["salt"] = hash("sha256", ((string)time())."-".((string)mt_rand()));
                $to_update["password"] = hash("sha256", $to_update["salt"].$_POST["password"]);
            }

            // Update user information
            if (!empty($to_update)) {
                $db->query("UPDATE `users` SET ".implode(", ", array_map(function($key) use (&$to_update, $db) {
                    return $key." = ".escape($db, $to_update[$key]);
                }, array_keys($to_update)))." WHERE `id` = ".escape($db, $user["id"])) or fail($db->error);
            }

            // Update role information
            $to_insert = [];
            $to_delete = [];
            foreach ($_POST["roles"] as $id => $dummy) {
                $roles[$id]["touched"] = true;

                if (!$roles[$id]["selected"]) {
                    $to_insert[] = $id;
                }
            }

            foreach ($roles as $role) {
                if (!isset($role["touched"]) || !$role["touched"]) {
                    $to_delete[] = escape($db, $role["id"]);
                }
            }

            if (!empty($to_insert)) {
                $db->query("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES ".implode(",", array_map(
                    function($role_id) use (&$user, $db) {
                        return "(".escape($db, $user["id"]).", ".escape($db, $role_id).")";
                    },
                    $to_insert
                ))) or fail($db->error);
            }

            if (!empty($to_delete)) {
                $db->query("DELETE FROM `user_roles`
                    WHERE `user_id` = ".escape($db, $user["id"])."
                    AND `role_id` IN (".implode(",", $to_delete).")");
            }

            $db->commit();

            Message::create(Message::SUCCESS, "User has been modified.");

            if ($_REQUEST["from"] ?? "detail" == "index") {
                header("Location: ".twig_url_for(["UsersController", "index"]));
            } else {
                header("Location: ".twig_url_for(["UsersController", "detail"], ["id" => $user["id"]]));
            }
            exit;
        }

        return $this->renderTemplate("settings/users/edit.html", [
            "user" => $user,
            "roles" => $this->selectRoles($db, $id)
        ]);
    }

    public function remove($id) {
        $this->requireAction("users_write");

        if ($id != Session::get("user_id")) {
            $db = connect();

            $db->query("DELETE FROM `users` WHERE `id` = ".escape($db, $id)) or fail($db->error);

            if ($db->affected_rows == 0) {
                throw new EntityNotFound("Specified user was not found.");
            }

            $db->commit();

            Message::create(Message::SUCCESS, "User has been removed.");
        } else {
            Message::create(Message::ERROR, "You cannot remove yourself.");
        }

        header("Location: ".twig_url_for(["UsersController", "index"]));
        exit;
    }

    protected function selectRoles(mysqli $db, $user_id = NULL, $only_selected = false) {
        $roles = [];
        $q = $db->query("SELECT
                `r`.`id`,
                `r`.`name`,
                IF(`u`.`user_id` IS NOT NULL, 1, 0) AS `selected`
            FROM `roles` `r`
            LEFT JOIN `user_roles` `u` ON (`u`.`role_id` = `r`.`id` AND `u`.`user_id` = ".escape($db, $user_id).")
            ORDER BY `name` ASC") or fail($db->error);

        while ($a = $q->fetch_assoc()) {
            $a["selected"] = (bool)$a["selected"];

            if ($a["selected"] || !$only_selected) {
                $roles[$a["id"]] = $a;
            }
        }

        return $roles;
    }

    protected function selectActions(mysqli $db, $user_id = NULL, $only_selected = false) {
        $q = $db->query("SELECT
                `a`.`id`,
                `a`.`description`,
                `a`.`parent_id`,
                IF(MAX(`ur`.`user_id`) IS NOT NULL, 1, 0) AS `selected`
            FROM `actions` `a`
            LEFT JOIN `role_actions` `ra` ON (`ra`.`action_id` = `a`.`id`)
            LEFT JOIN `user_roles` `ur` ON (`ra`.`role_id` = `ur`.`role_id` AND `ur`.`user_id` = ".escape($db, $user_id).")
            GROUP BY `a`.`id`
            ORDER BY `a`.`parent_id` ASC, `a`.`description` ASC");

        $actions = [];
        while ($a = $q->fetch_assoc()) {
            $a["selected"] = (bool)$a["selected"];
            $a["childs"] = [];
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

            if (!is_null($a["parent_id"])) {
                $actions[$a["parent_id"]]["childs"][] = &$actions[$a["id"]];
            }
        }

        $out = [];
        foreach ($actions as $a) {
            if (is_null($a["parent_id"]) && ($a["selected"] || !$only_selected)) {
                $out[] = $a;
            }
        }

        return $out;
    }
}
