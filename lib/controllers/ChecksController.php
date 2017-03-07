<?php

require_once "controllers/TemplatedController.php";

require_once "models/Message.php";

class ChecksController extends TemplatedController {
    public function index() {
        $db = connect();

        $order = "name";
        $direction = "ASC";

        if (isset($_REQUEST["order"]) && in_array($_REQUEST["order"], ["name", "hostname", "type", "interval"])) {
            $order = $_REQUEST["order"];
        }

        if (isset($_REQUEST["direction"]) && in_array($_REQUEST["direction"], ["ASC", "DESC"])) {
            $direction = $_REQUEST["direction"];
        }

        $q = $db->query("SELECT `ch`.`id`, `ch`.`enabled`, `ch`.`name`, `ch`.`interval`, `s`.`hostname`, `t`.`name` AS `type`, COUNT(`a`.`id`) AS `alerts` FROM `checks` `ch` JOIN `servers` `s` ON (`s`.`id` = `ch`.`server_id`) JOIN `check_types` `t` ON (`ch`.`type_id` = `t`.`id`) LEFT JOIN `alerts` `a` ON (`a`.`check_id` = `ch`.`id` AND `a`.`active` = 1) GROUP BY `ch`.`id` ORDER BY `".$order."` ".$direction.", `ch`.`name` ".$direction.", `s`.`hostname` ".$direction) or fail($db->error);

        $checks = [];

        while ($a = $q->fetch_array()) {
            $checks[] = [
                "id" => $a["id"],
                "enabled" => $a["enabled"],
                "name" => $a["name"],
                "type" => $a["type"],
                "interval" => $a["interval"],
                "hostname" => $a["hostname"],
                "alerts" => $a["alerts"],
            ];
        }

        return $this->renderTemplate("checks/index.html", [
            "checks" => $checks
        ]);
    }

    public function detail($id) {
        $db = connect();

        $q = $db->query("SELECT `ch`.`id`, `ch`.`enabled`, `ch`.`name`, `ch`.`interval`, `ch`.`last_check`, `s`.`hostname`, `t`.`name` AS `type`, `t`.`id` AS `type_id` FROM `checks` `ch` JOIN `servers` `s` ON (`s`.`id` = `ch`.`server_id`) JOIN `check_types` `t` ON (`ch`.`type_id` = `t`.`id`) WHERE `ch`.`id` = ".escape($db, $id)) or fail($db->error);
        $check = $q->fetch_array();

        if (!$check) {
            throw new EntityNotFound("Check was not found.");
        }

        $granularity = "daily";
        if (isset($_REQUEST["granularity"]) && in_array($_REQUEST["granularity"], ["daily", "weekly", "monthly", "yearly"])) {
            $granularity = $_REQUEST["granularity"];
        }

        list($chart, $readings) = $this->selectChart($db, $check["type_id"]);

        $series = [];

        $q = $db->query("SELECT `r`.`name`, UNIX_TIMESTAMP(`v`.`datetime`) AS `timestamp`, `v`.`value` FROM `readings_".$granularity."` `v` JOIN `readings` `r` ON (`v`.`reading_id` = `r`.`id`)  WHERE `v`.`check_id` = ".escape($db, $check["id"])." AND `v`.`reading_id` IN (".implode(",", $readings).") AND `v`.`datetime` BETWEEN DATE_ADD(NOW(), INTERVAL -1 DAY) AND NOW() ORDER BY `datetime` ASC");
        while ($a = $q->fetch_array()) {
            if (!isset($series[$a["name"]])) {
                $series[$a["name"]] = [];
            }

            $series[$a["name"]][] = [$a["timestamp"], $a["value"]];
        }

        return $this->renderTemplate("checks/detail.html", [
            "check" => $check,
            "alerts" => Alert::loadLatest($db, NULL, $check["id"]),
            "series" => $series,
            "chart" => $chart,
            "granularity" => $granularity,
            "charts" => $this->loadAllCharts($db, $check["type_id"])
        ]);
    }

    public function add() {
        $db = connect();

        $params = $this->combineParams();

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $interval = parse_duration($_REQUEST["interval"]);

            $db->query("INSERT INTO `checks` (`server_id`, `interval`, `last_check`, `name`, `type_id`, `params`, `enabled`) VALUES (".escape($db, $_POST["server"]).", ".escape($db, $interval).", NULL, ".escape($db, $_POST["name"]).", ".escape($db, $_POST["type"]).", ".escape($db, json_encode($params)).", 1)") or fail($db->error);
            $db->commit();

            Message::create(Message::SUCCESS, "Check has been created.");

            header("Location: ".twig_url_for(["ChecksController", "index"]));
            exit;
        }

        return $this->renderTemplate("checks/add.html", [
            "servers" => $this->loadServers($db),
            "types" => $this->loadTypes($db)
        ]);
    }

    public function edit($id) {
        $db = connect();

        $q = $db->query("SELECT `id`, `server_id`, `interval`, `last_check`, `name`, `type_id`, `params`, `enabled` FROM `checks` WHERE `id` = ".escape($db, $id)) or fail($db->error);
        $a = $q->fetch_array();

        if (!$a) {
            throw new EntityNotFound("Check was not found.");
        }

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $params = $this->combineParams();
            $interval = parse_duration($_REQUEST["interval"]);

            $db->query("UPDATE `checks` SET `server_id` = ".escape($db, $_POST["server"]).", `interval` = ".escape($db, $interval).", `name` = ".escape($db, $_POST["name"]).", `type_id` = ".escape($db, $_POST["type"]).", `params` = ".escape($db, json_encode($params))." WHERE `id` = ".escape($db, $id));
            $db->commit();

            Message::create(Message::SUCCESS, "Check has been modified.");

            if (($_REQUEST["back"] ?? "") == "detail") {
                header("Location: ".twig_url_for(["ChecksController", "detail"], ["id" => $id]));
            } else {
                header("Location: ".twig_url_for(["ChecksController", "index"]));
            }
            exit;
        }

        $params = json_decode($a["params"]);
        $a["params"] = [];
        foreach ($params as $key=>$val) {
            $a["params"][$key] = $val;
        }

        return $this->renderTemplate("checks/edit.html", [
            "check" => $a,
            "servers" => $this->loadServers($db),
            "types" => $this->loadTypes($db)
        ]);
    }

    public function toggle($id) {
        $db = connect();

        $db->query("UPDATE `checks` SET `enabled` = IF(`enabled`, 0, 1) WHERE `id` = ".escape($db, $id));
        $db->commit();

        if (($_REQUEST["back"] ?? "") == "detail") {
            header("Location: ".twig_url_for(["ChecksController", "detail"], ["id" => $id]));
        } else {
            header("Location: ".twig_url_for(["ChecksController", "index"]));
        }
        exit;
    }

    public function remove($id) {
        $db = connect();

        $db->query("DELETE FROM `checks` WHERE `id` = ".escape($db, $id));
        $db->commit();

        Message::create(Message::SUCCESS, "Check has been removed.");

        if (($_REQUEST["back"] ?? "") == "detail") {
            header("Location: ".twig_url_for(["ChecksController", "detail"], ["id" => $id]));
        } else {
            header("Location: ".twig_url_for(["ChecksController", "index"]));
        }
        exit;
    }

    public function list($hostname) {
        $db = connect();

        $q = $db->query("SELECT `ch`.`id`, `ch`.`name`, `t`.`identifier` AS `type`, `ch`.`params` FROM `checks` `ch` JOIN `servers` `s` ON (`s`.`id` = `ch`.`server_id`) JOIN `check_types` `t` ON (`t`.`id` = `ch`.`type_id`) WHERE `s`.`hostname` = ".escape($db, $hostname)." AND `ch`.`enabled` = 1 AND (`ch`.`last_check` IS NULL OR DATE_ADD(`ch`.`last_check`, INTERVAL (`interval` - 60) SECOND) <= NOW())");

        $out = array();

        while ($a = $q->fetch_array()) {
            $out[] = [
                "id" => $a["id"],
                "name" => $a["name"],
                "type" => $a["type"],
                "params" => json_decode($a["params"]),
            ];
        }

        header("Content-Type: text/json");
        return json_encode($out);
    }

    public function put() {
        $db = connect();

        $reading_mapping = [];
        $q = $db->query("SELECT `id`, `name` FROM `readings`");
        while ($a = $q->fetch_array()) {
            $reading_mapping[$a["name"]] = $a["id"];
        }

        $data = json_decode(file_get_contents("php://input"));
        foreach ($data as $check) {
            $id = $check->id;
            $alerts = $check->alerts;
            $readings = $check->readings ?? new stdClass();

            $q = $db->query("SELECT `server_id` FROM `checks` WHERE `id` = ".escape($db, $id)) or fail($db->error);
            $a = $q->fetch_array();

            if (!$a) {
                continue;
            }

            $server_id = $a["server_id"];

            $q = $db->query("SELECT `id`, `type` FROM `alerts` WHERE `check_id` = ".escape($db, $id)." AND `active` = 1") or fail($db->error);

            $existing_alerts = [];
            $dismiss_alerts = [];
            $new_alerts = [];

            while ($a = $q->fetch_array()) {
                $found = false;
                foreach ($alerts as $num => $alert) {
                    if ($a["type"] == $alert->type) {
                        $found = true;
                        unset($alerts[$num]);
                        break;
                    }
                }

                if (!$found) {
                    $dismiss_alerts[] = $a["id"];
                }
            }

            foreach ($alerts as $alert) {
                $new_alerts[] = "(".escape($db, $server_id).", ".escape($db, $id).", NOW(), ".escape($db, $alert->type).", ".escape($db, json_encode($alert->data)).", 1)";
            }

            if (!empty($new_alerts)) {
                $db->query("INSERT INTO `alerts` (`server_id`, `check_id`, `timestamp`, `type`, `data`, `active`) VALUES ".implode(",", $new_alerts)) or fail($db->error);
            }

            if (!empty($dismiss_alerts)) {
                $db->query("UPDATE `alerts` SET `active` = 0, `sent` = 0, `until` = NOW() WHERE `id` IN (".implode(",", $dismiss_alerts).")") or fail($db->error);
            }

            $db->query("UPDATE `checks` SET `last_check` = NOW() WHERE `id` = ".escape($db, $id)) or fail($db->error);

            // Store readings
            $to_insert = [];
            foreach ($readings as $key=>$val) {
                if (!isset($reading_mapping[$key])) {
                    $db->query("INSERT INTO `readings` (`name`) VALUES (".escape($db, $key).")");
                    $reading_mapping[$key] = $db->insert_id;
                }

                $to_insert[] = "(".escape($db, $id).", ".$reading_mapping[$key].", NOW(), ".escape($db, $val).")";
            }

            if (!empty($to_insert)) {
                $db->query("INSERT INTO `readings_daily` (`check_id`, `reading_id`, `datetime`, `value`) VALUES ".implode(",", $to_insert));
            }
        }

        $db->commit();

        return json_encode(["status" => "OK"]);
    }

    protected function loadServers(mysqli $db) {
        $servers = [];
        $q = $db->query("SELECT `id`, `hostname` FROM `servers` ORDER BY `hostname` ASC");
        while ($a = $q->fetch_array()) {
            $servers[] = [
                "id" => $a["id"],
                "hostname" => $a["hostname"]
            ];
        }

        return $servers;
    }

    protected function loadTypes(mysqli $db) {
        $types = [];
        $q = $db->query("SELECT `id`, `name` FROM `check_types` ORDER BY `name` ASC");
        while ($a = $q->fetch_array()) {
            $types[] = [
                "id" => $a["id"],
                "name" => $a["name"]
            ];
        }

        return $types;
    }

    protected function combineParams() {
        $params = [];

        if (isset($_REQUEST["params"]) && isset($_REQUEST["values"])) {
            for ($i = 0; $i < min(count($_REQUEST["params"]), count($_REQUEST["values"])); ++$i) {
                $params[$_REQUEST["params"][$i]] = $_REQUEST["values"][$i];
            }

            $_REQUEST["params"] = $params;
            unset($_REQUEST["values"]);
        }

        return $params;
    }

    protected function selectChart(mysqli $db, int $check_type_id) {
        $chart = NULL;
        $readings = [];

        if (isset($_REQUEST["chart"])) {
            $q = $db->query("SELECT `id`, `name` FROM `check_charts` WHERE `id` = ".escape($db, $_REQUEST["chart"]));
            if ($a = $q->fetch_array()) {
                $chart = $a;
            }
        }

        if (is_null($chart)) {
            $q = $db->query("SELECT `id`, `name` FROM `check_charts` WHERE `check_type_id` = ".escape($db, $check_type_id)." ORDER BY `name` ASC LIMIT 1");
            if ($a = $q->fetch_array()) {
                $chart = $a;
            }
        }

        // Select readings for selected chart.
        $q = $db->query("SELECT `reading_id` FROM `check_chart_readings` WHERE `chart_id` = ".escape($db, $chart["id"]));
        while ($a = $q->fetch_array()) {
            $readings[] = $a["reading_id"];
        }

        return [$chart, $readings];
    }

    protected function loadAllCharts(mysqli $db, int $check_type_id) {
        $charts = [];

        $q = $db->query("SELECT `id`, `name` FROM `check_charts` WHERE `check_type_id` = ".escape($db, $check_type_id));
        while ($a = $q->fetch_array()) {
            $charts[] = [
                "id" => $a["id"],
                "name" => $a["name"]
            ];
        }

        return $charts;
    }
}
