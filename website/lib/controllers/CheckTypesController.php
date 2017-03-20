<?php

require_once "controllers/TemplatedController.php";

require_once "exceptions/EntityNotFound.php";

require_once "models/Message.php";

class CheckTypesController extends TemplatedController {
    public function index() {
        $db = connect();

        $order = "name";
        $direction = "ASC";

        if (isset($_REQUEST["order"]) && in_array($_REQUEST["order"], ["name", "identifier", "usage"])) {
            $order = $_REQUEST["order"];
        }

        if (isset($_REQUEST["direction"]) && in_array($_REQUEST["direction"], ["ASC", "DESC"])) {
            $direction = $_REQUEST["direction"];
        }

        $q = $db->query("SELECT
                `t`.`id`,
                `t`.`name`,
                `t`.`identifier`,
                COUNT(`ch`.`id`) AS `usage`
            FROM `check_types` `t`
            LEFT JOIN `checks` `ch` ON (`ch`.`type_id` = `t`.`id`)
            GROUP BY `t`.`id`
            ORDER BY `".$order."` ".$direction) or fail($db->error);

        $checks = [];
        while ($a = $q->fetch_array()) {
            $checks[] = [
                "id" => $a["id"],
                "name" => $a["name"],
                "identifier" => $a["identifier"],
                "usage" => $a["usage"]
            ];
        }

        $db->commit();

        return $this->renderTemplate("settings/check_types/index.html", [
            "checks" => $checks
        ]);
    }

    public function add() {
        $db = connect();

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $db->query("INSERT INTO `check_types` (`name`, `identifier`)
                VALUES (
                    ".escape($db, $_POST["name"]).",
                    ".escape($db, $_POST["identifier"])."
                )") or fail($db->error);
            $type_id = $db->insert_id;

            $this->updateOptions($db, $type_id, $_POST["options"] ?? []);
            $this->updateReadings($db, $type_id, $_POST["readings"] ?? []);

            Message::create(Message::SUCCESS, "Check type has been created.");

            header("Location: ".twig_url_for(["CheckTypesController", "index"]));
            exit;
        }

        return $this->renderTemplate("settings/check_types/add.html");
    }

    public function edit($id) {
        $db = connect();

        $q = $db->query("SELECT `id`, `name`, `identifier` FROM `check_types`
            WHERE `id` = ".escape($db, $id)) or fail($db->error);
        $check = $q->fetch_array();

        if (!$check) {
            throw new EntityNotFound("Check type was not found.");
        }

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $db->query("UPDATE `check_types`
                SET
                    `name` = ".escape($db, $_POST["name"]).",
                    `identifier` = ".escape($db, $_POST["identifier"])."
                WHERE
                    `id` = ".escape($db, $check["id"])) or fail($db->error);

            $this->updateOptions($db, $id, $_POST["options"] ?? []);
            $this->updateReadings($db, $id, $_POST["readings"] ?? []);

            $db->commit();

            Message::create(Message::SUCCESS, "Check type has been modified.");

            header("Location: ".twig_url_for(["CheckTypesController", "index"]));
            exit;
        }

        $options = [];
        $q = $db->query("SELECT `option_name` FROM `check_type_options` WHERE `check_type_id` = ".escape($db, $id)) or fail($db->error);
        while ($a = $q->fetch_array()) {
            $options[] = $a["option_name"];
        }

        $readings = [];
        $q = $db->query("SELECT `name`, `data_type`, `precision`, `type`, `compute` FROM `readings` WHERE `check_type_id` = ".escape($db, $id)) or fail($db->error);
        while ($a = $q->fetch_array()) {
            $readings[] = [
                "name" => $a["name"],
                "data_type" => $a["data_type"],
                "precision" => $a["precision"],
                "type" => $a["type"],
                "compute" => $a["compute"]
            ];
        }

        return $this->renderTemplate("settings/check_types/edit.html", [
            "check" => $check,
            "options" => $options,
            "readings" => $readings
        ]);
    }

    public function remove($id) {
        $db = connect();

        $db->query("DELETE FROM `check_types` WHERE `id` = ".escape($db, $id));
        if ($db->affected_rows == 0) {
            throw new EntityNotFound("Check type was not found.");
        }

        Message::create(Message::SUCCESS, "Check type has been removed.");

        header("Location: ".twig_url_for(["CheckTypesController", "index"]));
        exit;
    }

    protected function updateOptions(mysqli $db, int $check_id, array $new_options) {
        $options = array();
        $q = $db->query("SELECT `id`, `option_name` FROM `check_type_options`
            WHERE `check_type_id` = ".escape($db, $check_id)) or fail($db->error);
        while ($a = $q->fetch_array()) {
            $options[$a["option_name"]] = [
                "id" => $a["id"],
                "found" => false
            ];
        }

        foreach ($new_options as $option) {
            if (empty($option)) {
                continue;
            }

            if (!isset($options[$option])) {
                $db->query("INSERT INTO `check_type_options` (`check_type_id`, `option_name`)
                    VALUES (
                        ".escape($db, $check_id).",
                        ".escape($db, $option)."
                    )") or fail($db->error);
                $options[$option] = [
                    "id" => $db->insert_id,
                    "found" => true
                ];
            } else {
                $options[$option]["found"] = true;
            }
        }

        foreach ($options as $option) {
            if (!$option["found"]) {
                $db->query("DELETE FROM `check_type_options`
                    WHERE `id` = ".escape($db, $option["id"])) or fail($db->error);
            }
        }
    }

    protected function updateReadings(mysqli $db, int $check_id, array $new_readings) {
        $readings = array();
        $q = $db->query("SELECT `id`, `name` FROM `readings`
            WHERE `check_type_id` = ".escape($db, $check_id));
        while ($a = $q->fetch_array()) {
            $readings[$a["name"]] = [
                "id" => $a["id"],
                "found" => false
            ];
        }

        foreach ($new_readings as $reading) {
            if (!isset($reading["name"]) || empty($reading["name"])) {
                continue;
            }

            if (isset($readings[$reading["name"]])) {
                $db->query("UPDATE `readings`
                    SET
                        `data_type` = ".escape($db, $reading["data_type"] ?? "raw").",
                        `precision` = ".escape($db, $reading["precision"] ?? "0").",
                        `type` = ".escape($db, $reading["type"] ?? "GAUGE").",
                        `compute` = ".escape($db, $reading["compute"] ?? "")."
                    WHERE
                        `id` = ".escape($db, $readings[$reading["name"]]["id"])) or fail($db->error);
                $readings[$reading["name"]]["found"] = true;
            } else {
                $db->query("INSERT INTO `readings` (`name`, `check_type_id`, `data_type`, `precision`, `type`, `compute`)
                            VALUES (
                                ".escape($db, $reading["name"]).",
                                ".escape($db, $check_id).",
                                ".escape($db, $reading["data_type"] ?? "raw").",
                                ".escape($db, $reading["precision"] ?? "0").",
                                ".escape($db, $reading["type"] ?? "GAUGE").",
                                ".escape($db, $reading["compute"] ?? "")."
                            )");
                $readings[$reading["name"]] = [
                    "id" => $db->insert_id,
                    "found" => true
                ];
            }
        }

        foreach ($readings as $reading) {
            if (!$reading["found"]) {
                $db->query("DELETE FROM `readings`
                    WHERE `id` = ".escape($db, $reading["id"]));
            }
        }
    }
}
