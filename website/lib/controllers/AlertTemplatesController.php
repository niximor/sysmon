<?php

require_once "controllers/TemplatedController.php";

require_once "models/Message.php";

require_once "exceptions/EntityNotFound.php";

class AlertTemplatesController extends TemplatedController {
    public function index() {
        $db = connect();

        $order = "alert_type";
        if (isset($_REQUEST["order"]) && in_array($_REQUEST["order"], ["alert_type"])) {
            $order = $_REQUEST["order"];
        }

        $direction = "ASC";
        if (isset($_REQUEST["direction"]) && in_array($_REQUEST["direction"], ["ASC", "DESC"])) {
            $direction = $_REQUEST["direction"];
        }

        $q = $db->query("SELECT `id`, `alert_type` FROM `alert_templates` ORDER BY `".$order."` ".$direction) or fail($db->error);

        $templates = [];
        while ($a = $q->fetch_array()) {
            $templates[] = [
                "id" => $a["id"],
                "alert_type" => $a["alert_type"]
            ];
        }


        return $this->renderTemplate("settings/alert_templates/index.html", [
            "templates" => $templates
        ]);
    }

    public function add() {
        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $db = connect();
            $db->query("INSERT INTO `alert_templates` (`alert_type`, `template`) VALUES (".escape($db, $_POST["alert_type"]).", ".escape($db, $_POST["template"]).")") or fail($db->error);
            $db->commit();

            Message::create(Message::SUCCESS, "Alert template has been created.");

            header("Location: ".twig_url_for(['AlertTemplatesController', 'index']));
            exit;
        }

        return $this->renderTemplate("settings/alert_templates/add.html");
    }

    public function edit(int $id) {
        $db = connect();

        $q = $db->query("SELECT `id`, `alert_type`, `template` FROM `alert_templates` WHERE `id` = ".escape($db, $id)) or fail($db->error);
        $a = $q->fetch_array();

        if (!$a) {
            throw new EntityNotFound("Alert template does not exists.");
        }

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $db->query("UPDATE `alert_templates` SET `alert_type` = ".escape($db, $_POST["alert_type"]).", `template` = ".escape($db, $_POST["template"])." WHERE `id` = ".escape($db, $id)) or fail($db->error);
            $db->commit();

            Message::create(Message::SUCCESS, "Alert template has been updated.");

            header("Location: ".twig_url_for(['AlertTemplatesController', 'index']));
            exit;
        }

        return $this->renderTemplate("settings/alert_templates/edit.html", [
            "template" => $a
        ]);
    }

    public function remove(int $id) {
        $db = connect();

        $db->query("DELETE FROM `alert_templates` WHERE `id` = ".escape($db, $id)) or fail($db->error);

        if ($db->affected_rows == 1) {
            Message::create(Message::SUCCESS, "Alert template has been removed.");
        } else {
            throw new EntityNotFound("Alert template does not exists.");
        }

        $db->commit();

        header("Location: ".twig_url_for(['AlertTemplatesController', 'index']));
        exit;
    }
}
