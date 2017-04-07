<?php

require_once "controllers/TemplatedController.php";

class ActionsController extends TemplatedController {
    public function index() {
        $this->requireAction("actions_read");

        $db = connect();

        return $this->renderTemplate("settings/actions/index.html", [
            "actions" => $this->selectActions($db)
        ]);
    }

    public function add() {
        $this->requireAction("actions_write");

        $db = connect();

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $parent = $_POST["parent"];
            if (empty($parent)) {
                $parent = NULL;
            }

            $db->query("INSERT INTO `actions` (`name`, `description`, `parent_id`) VALUES (
                ".escape($db, $_POST["name"]).",
                ".escape($db, $_POST["description"]).",
                ".escape($db, $parent)."
            )") or fail($db->error);

            $db->commit();

            Message::create(Message::SUCCESS, "Action has been created.");

            header("Location: ".twig_url_for(['ActionsController', 'index']));
            exit;
        }

        return $this->renderTemplate("settings/actions/add.html", [
            "actions" => $this->selectActions($db)
        ]);
    }

    public function edit($id) {
        $this->requireAction("actions_write");

        $db = connect();

        $q = $db->query("SELECT `id`, `name`, `description`
            FROM `actions`
            WHERE `id` = ".escape($db, $id)) or fail($db->error);
        $action = $q->fetch_assoc();

        if (!$action) {
            throw new EntityNotFound("Action does not exists.");
        }

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $parent = $_POST["parent"];
            if (empty($parent)) {
                $parent = NULL;
            }

            $db->query("UPDATE `actions` SET
                    `name` = ".escape($db, $_POST["name"]).",
                    `description` = ".escape($db, $_POST["description"]).",
                    `parent_id` = ".escape($db, $parent)."
                WHERE
                    `id` = ".escape($db, $action["id"])) or fail($db->error);

            $db->commit();

            Message::create(Message::SUCCESS, "Action has been modified.");

            header("Location: ".twig_url_for(['ActionsController', 'index']));
            exit;
        }

        return $this->renderTemplate("settings/actions/edit.html", [
            "action" => $action,
            "actions" => $this->selectActions($db)
        ]);
    }

    public function remove($id) {
        $this->requireAction("actions_write");

        $db = connect();

        $db->query("DELETE FROM `actions` WHERE `id` = ".escape($db, $id));
        if ($db->affected_rows == 0) {
            throw new EntityNotFound("Specified action does not exists.");
        }

        $db->commit();

        Message::create(Message::SUCCESS, "Action has been removed.");
        header("Location: ".twig_url_for(['ActionsController', 'index']));
    }

    protected function selectActions($db) {
        $actions = [];

        $q = $db->query("SELECT `id`, `name`, `description`, `parent_id`
            FROM `actions`
            ORDER BY `parent_id` ASC, `description` ASC") or fail($db->error);

        while ($a = $q->fetch_assoc()) {
            $a["childs"] = [];
            $actions[$a["id"]] = $a;
        }

        foreach ($actions as $a) {
            if (!is_null($a["parent_id"])) {
                $actions[$a["parent_id"]]["childs"][] = &$actions[$a["id"]];
            }
        }

        $root_actions = [];
        foreach ($actions as $action) {
            if (is_null($action["parent_id"])) {
                $root_actions[] = $action;
            }
        }

        return $root_actions;
    }
}
