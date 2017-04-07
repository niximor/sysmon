<?php

require_once "controllers/TemplatedController.php";

class RolesController extends TemplatedController {
    public function index() {
        $this->requireAction("roles_read");

        $db = connect();

        $order = "name";
        if (isset($_REQUEST["order"]) && in_array($_REQUEST["order"], ["name", "users"])) {
            $order = $_REQUEST["order"];
        }

        $order = [
            "name" => "`r`.`name`",
            "users" => `users`
        ][$order];

        $direction = "ASC";
        if (isset($_REQUEST["direction"]) && in_array($_REQUEST["direction"], ["ASC", "DESC"])) {
            $direction = $_REQUEST["direction"];
        }

        $q = $db->query("SELECT `r`.`id`, `r`.`name`, COUNT(`u`.`user_id`) AS `users` FROM `roles` `r`
            LEFT JOIN `user_roles` `u` ON (`u`.`role_id` = `r`.`id`)
            GROUP BY `r`.`id` ORDER BY ".$order." ".$direction);

        $roles = [];
        while ($a = $q->fetch_assoc()) {
            $roles[] = $a;
        }

        return $this->renderTemplate("settings/roles/index.html", [
            "roles" => $roles
        ]);
    }

    public function add() {
        $this->requireAction("roles_write");

        $db = connect();

        $actions = [];
        $q = $db->query("SELECT `id`, `description`, `parent_id`
            FROM `actions`
            ORDER BY `parent_id` ASC, `description` ASC") or fail($db->error);

        while ($a = $q->fetch_assoc()) {
            $a["childs"] = [];
            $a["selected"] = false;
            $actions[$a["id"]] = $a;
        }

        foreach ($actions as $a) {
            if (!is_null($a["parent_id"])) {
                $actions[$a["parent_id"]]["childs"][] = $a;
            }
        }

        $root_actions = [];
        foreach ($actions as $action) {
            if (is_null($action["parent_id"])) {
                $root_actions[] = $action;
            }
        }

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $db->query("INSERT INTO `roles` (`name`) VALUES (".escape($db, $_POST["name"]).")") or fail($db->error);
            $id = $db->insert_id;

            $post_actions = $_POST["actions"] ?? [];

            if (!empty($post_actions)) {
                $db->query("INSERT INTO `role_actions` (`role_id`, `action_id`) VALUES ".implode(",", array_map(function($action_id) use ($db, $id) {
                    return "(".escape($db, $id).", ".escape($db, $action_id).")";
                }, array_keys($post_actions)))) or fail($db->error);
            }

            $db->commit();

            Message::create(Message::SUCCESS, "Role has been created.");
            header("Location: ".twig_url_for(["RolesController", "index"]));
            exit;
        }

        return $this->renderTemplate("settings/roles/add.html", [
            "actions" => $root_actions
        ]);
    }

    public function detail($id) {
        $this->requireAction("roles_read");

        $db = connect();

        $q = $db->query("SELECT `id`, `name` FROM `roles` WHERE `id` = ".escape($db, $id)) or fail($db->error);
        $role = $q->fetch_assoc();

        if (!$role) {
            throw new EntityNotFound("Specified role was not found.");
        }

        $q = $db->query("SELECT `u`.`id`, `u`.`name`
            FROM `user_roles` `r`
            JOIN `users` `u` ON (`r`.`user_id` = `u`.`id`)
            WHERE
                `r`.`role_id` = ".escape($db, $role["id"])."
            ORDER BY `u`.`name` ASC") or fail($db->error);

        $users = [];

        while ($a = $q->fetch_assoc()) {
            $users[] = $a;
        }

        $q = $db->query("SELECT `a`.`id`, `a`.`description`, `a`.`parent_id`, IF(`r`.`role_id` IS NOT NULL, 1, 0) AS `selected`
            FROM `actions` `a`
            LEFT JOIN `role_actions` `r` ON (
                `r`.`role_id` = ".escape($db, $role["id"])." AND
                `r`.`action_id` = `a`.`id`
            )
            ORDER BY `a`.`parent_id` ASC, `a`.`name` ASC") or fail($db->error);

        $actions = [];
        while ($a = $q->fetch_assoc()) {
            $a["childs"] = [];
            $a["selected"] = (bool)$a["selected"];
            $actions[(int)$a["id"]] = $a;
        }

        foreach ($actions as $a) {
            if (!is_null($a["parent_id"])) {
                $actions[(int)$a["parent_id"]]["childs"][] = &$actions[(int)$a["id"]];

                $parent = $a["parent_id"];
                while ($parent) {
                    $actions[$parent]["selected"] = true;
                    $parent = $actions[$parent]["parent_id"];
                }
            }
        }

        $selected_actions = [];
        foreach ($actions as $action) {
            if (is_null($action["parent_id"]) && $action["selected"]) {
                $selected_actions[] = $action;
            }
        }

        return $this->renderTemplate("settings/roles/detail.html", [
            "role" => $role,
            "users" => $users,
            "actions" => $selected_actions,
        ]);
    }

    public function edit($id) {
        $this->requireAction("roles_write");

        $db = connect();

        $q = $db->query("SELECT `id`, `name` FROM `roles` WHERE `id` = ".escape($db, $id)) or fail($db->error);
        $role = $q->fetch_assoc();

        if (!$role) {
            throw new EntityNotFound("Specified role was not found.");
        }

        $actions = [];
        $q = $db->query("SELECT `id`, `description`, `parent_id`
            FROM `actions`
            ORDER BY `parent_id` ASC, `description` ASC") or fail($db->error);

        while ($a = $q->fetch_assoc()) {
            $a["childs"] = [];
            $a["selected"] = false;
            $a["touched"] = false;
            $actions[$a["id"]] = $a;
        }

        foreach ($actions as $a) {
            if (!is_null($a["parent_id"])) {
                $actions[$a["parent_id"]]["childs"][] = &$actions[$a["id"]];
            }
        }

        $q = $db->query("SELECT `action_id` FROM `role_actions`
            WHERE `role_id` = ".escape($db, $role["id"])) or fail($db->error);

        while ($a = $q->fetch_assoc()) {
            $actions[(int)$a["action_id"]]["selected"] = true;
        }

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $db->query("UPDATE `roles`
                SET `name` = ".escape($db, $_POST["name"])."
                WHERE `id` = ".escape($db, $role["id"])) or fail($db->error);

            $to_insert = [];
            $to_delete = [];

            foreach (($_POST["actions"] ?? []) as $id => $dummy) {
                if (isset($actions[(int)$id])) {
                    if (!$actions[(int)$id]["selected"]) {
                        $to_insert[] = (int)$id;
                    }

                    $actions[(int)$id]["touched"] = true;
                }
            }

            foreach ($actions as $id => $action) {
                if (!isset($action["touched"]) || !$action["touched"]) {
                    $to_delete[] = escape($db, $id);
                }
            }

            if (!empty($to_insert)) {
                $db->query("INSERT INTO `role_actions` (`role_id`, `action_id`)
                    VALUES ".implode(",", array_map(function($id) use (&$role, $db) {
                        return "(".escape($db, $role["id"]).", ".escape($db, $id).")";
                    }, $to_insert))) or fail($db->error);
            }

            if (!empty($to_delete)) {
                $db->query("DELETE FROM `role_actions` WHERE
                    `role_id` = ".escape($db, $role["id"])."
                    AND `action_id` IN (".implode(",", $to_delete).")") or fail($db->error);
            }

            $db->commit();

            Message::create(Message::SUCCESS, "Role has been modified.");

            if ($_REQUEST["from"] ?? "detail" == "index") {
                header("Location: ".twig_url_for(["RolesController", "index"]));
            } else {
                header("Location: ".twig_url_for(["RolesController", "detail"], ["id" => $role["id"]]));
            }
            exit;
        }

        $root_actions = [];
        foreach ($actions as $action) {
            if (is_null($action["parent_id"])) {
                $root_actions[] = $action;
            }
        }

        return $this->renderTemplate("settings/roles/edit.html", [
            "role" => $role,
            "actions" => $root_actions
        ]);
    }

    public function remove($id) {
        $this->requireAction("roles_write");

        $db = connect();

        $db->query("DELETE FROM `roles` WHERE `id` = ".escape($db, $id)) or fail($db->error);
        if ($db->affected_rows == 0) {
            throw new EntityNotFound("Specified role was not found.");
        }

        $db->commit();

        Message::create(Message::SUCCESS, "Role has been removed.");

        header("Location: ".twig_url_for(["RolesController", "index"]));
        exit;
    }
}
