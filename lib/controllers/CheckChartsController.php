<?php

require_once "controllers/TemplatedController.php";

require_once "models/Message.php";

require_once "exceptions/EntityNotFound.php";

class CheckChartsController extends TemplatedController {
    public function index() {
        $db = connect();

        $order = "chart";
        if (isset($_REQUEST["order"]) && in_array($_REQUEST["order"], ["chart", "type"])) {
            $order = $_REQUEST["order"];
        }

        $direction = "ASC";
        if (isset($_REQUEST["direction"]) && in_array($_REQUEST["direction"], ["ASC", "DESC"])) {
            $direction = $_REQUEST["direction"];
        }

        $q = $db->query("SELECT `g`.`id`, `g`.`name` AS `chart`, `t`.`name` AS `type` FROM `check_charts` `g` JOIN `check_types` `t` ON (`g`.`check_type_id` = `t`.`id`) ORDER BY `".$order."` ".$direction);

        $charts = [];
        while ($a = $q->fetch_array()) {
            $charts[] = [
                "id" => $a["id"],
                "chart" => $a["chart"],
                "type" => $a["type"]
            ];
        }

        return $this->renderTemplate("settings/check_charts/index.html", [
            "charts" => $charts
        ]);
    }

    public function add() {
        $db = connect();

        $q = $db->query("SELECT `r`.`id`, `r`.`name`, 0 AS `selected` FROM `readings` `r` ORDER BY `r`.`name` ASC") or fail($db->error);

        $all_readings = [];
        while ($a = $q->fetch_array()) {
            $all_readings[] = [
                "id" => $a["id"],
                "name" => $a["name"],
                "selected" => (bool)$a["selected"]
            ];
        }

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $readings = $_REQUEST["readings"];
            if (!empty($readings)) {
                $db->query("INSERT INTO `check_charts` (`check_type_id`, `name`) VALUES (".escape($db, $_REQUEST["type"]).", ".escape($db, $_REQUEST["name"]).")") or fail($db->error);
                $chart_id = $db->insert_id;

                // Create readings
                $db->query("INSERT INTO `check_chart_readings` (`chart_id`, `reading_id`) VALUES ".implode(",", array_map(
                    function($reading_id) use ($db, $chart_id) { return "(".escape($db, $chart_id).", ".escape($db, $reading_id).")"; },
                    $readings))) or fail($db->error);

                $db->commit();

                Message::create(Message::SUCCESS, "Chart has been created.");
            } else {
                Message::create(Message::WARNING, "Chart must contain at least one reading.");
            }

            header("Location: ".twig_url_for(["CheckChartsController", "index"]));
            exit;
        }

        return $this->renderTemplate("settings/check_charts/add.html", [
            "all_readings" => $all_readings,
            "check_types" => $this->loadCheckTypes($db)
        ]);
    }

    public function edit(int $id) {
        $db = connect();

        $q = $db->query("SELECT `g`.`id`, `g`.`name` AS `chart`, `t`.`name` AS `type`, `t`.`id` AS `type_id` FROM `check_charts` `g` JOIN `check_types` `t` ON (`g`.`check_type_id` = `t`.`id`) WHERE `g`.`id` = ".escape($db, $id)) or fail($db->error);
        $chart = $q->fetch_array();

        if (!$chart) {
            throw new EntityNotFound("Chart was not found.");
        }

        $q = $db->query("SELECT `r`.`id`, `r`.`name`, `chr`.`reading_id` IS NOT NULL AS `selected` FROM `readings` `r` LEFT JOIN `check_chart_readings` `chr` ON (`r`.`id` = `chr`.`reading_id` AND `chr`.`chart_id` = ".escape($db, $chart["id"]).") ORDER BY `r`.`name` ASC") or fail($db->error);

        $all_readings = [];
        while ($a = $q->fetch_array()) {
            $all_readings[] = [
                "id" => $a["id"],
                "name" => $a["name"],
                "selected" => (bool)$a["selected"]
            ];
        }

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $readings = $_REQUEST["readings"];

            if (!empty($readings)) {
                $db->query("UPDATE `check_charts` SET `check_type_id` = ".escape($db, $_REQUEST["type"]).", `name` = ".escape($db, $_REQUEST["name"])." WHERE `id` = ".escape($db, $chart["id"])) or fail($db->error);

                // Update readings
                $db->query("DELETE FROM `check_chart_readings` WHERE `chart_id` = ".escape($db, $id)." AND `reading_id` NOT IN (".implode(", ", array_map(
                    function($reading_id) use ($db) { return escape($db, $reading_id); },
                    $readings)).")") or fail($db->error);
                $db->query("INSERT IGNORE INTO `check_chart_readings` (`chart_id`, `reading_id`) VALUES ".implode(",", array_map(
                    function($reading_id) use ($db, $chart) { return "(".escape($db, $chart["id"]).", ".escape($db, $reading_id).")"; },
                    $readings))) or fail($db->error);

                $db->commit();

                Message::create(Message::SUCCESS, "Chart has been updated.");
            } else {
                Message::create(Message::WARNING, "Chart must contain at least one reading.");
            }

            header("Location: ".twig_url_for(["CheckChartsController", "index"]));
            exit;
        }

        return $this->renderTemplate("settings/check_charts/edit.html", [
            "chart" => $chart,
            "all_readings" => $all_readings,
            "check_types" => $this->loadCheckTypes($db)
        ]);
    }

    public function remove(int $id) {
        $db = connect();
        $db->query("DELETE FROM `check_charts` WHERE `id` = ".escape($db, $id)) or fail($db->error);

        if ($db->affected_rows > 0) {
            Message::create(Message::SUCCESS, "Chart has been removed.");
            $db->commit();
        } else {
            throw new EntityNotFound("Chart was not found.");
        }

        header("Location: ".twig_url_for(["CheckChartsController", "index"]));
        exit;
    }

    protected function loadCheckTypes($db) {
        $q = $db->query("SELECT `id`, `name` FROM `check_types` ORDER BY `name` ASC") or fail($db->error);

        $types = [];
        while ($a = $q->fetch_array()) {
            $types[] = [
                "id" => $a["id"],
                "name" => $a["name"]
            ];
        }

        return $types;
    }
}