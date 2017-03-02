<?php

require_once "controllers/TemplatedController.php";
require_once "controllers/CronInterface.php";

require_once "models/Alert.php";

require_once "exceptions/EntityNotFound.php";

class HostsController extends TemplatedController implements CronInterface {
    public function index() {
        $db = connect();

        $order = "hostname";
        if (isset($_REQUEST["order"]) && in_array($_REQUEST["order"], ["hostname", "distribution", "version", "kernel", "ip"])) {
            $order = $_REQUEST["order"];
        }

        $direction = "ASC";
        if (isset($_REQUEST["direction"]) && in_array($_REQUEST["direction"], ["ASC", "DESC"])) {
            $direction = $_REQUEST["direction"];
        }

        $q = $db->query("SELECT `s`.`id`, `s`.`hostname`, `s`.`distribution`, `s`.`version`, `s`.`kernel`, `s`.`ip`, `s`.`last_check`, COUNT(`a`.`id`) AS `alerts` FROM `servers` `s` LEFT JOIN `alerts` `a` ON (`a`.`server_id` = `s`.`id` AND `a`.`active`) GROUP BY `s`.`id` ORDER BY `s`.`".$order."` ".$direction) or fail($db->error);

        $now = new DateTime();

        $hosts = [];

        while ($a = $q->fetch_array()) {
            $tm_check = DateTime::createFromFormat("Y-m-d H:i:s", $a["last_check"]);
            $a["diff"] = $now->getTimestamp() - $tm_check->getTimestamp();
            $a["resolved_hostname"] = gethostbyaddr($a["ip"]);

            $hosts[] = $a;
        }

        return $this->renderTemplate("hosts/index.html", [
            "hosts" => $hosts
        ]);
    }

    public function detail($id) {
        $db = connect();

        $q = $db->query("SELECT `s`.`id`, `s`.`hostname`, `s`.`distribution`, `s`.`version`, `s`.`kernel`, `s`.`ip`, `s`.`last_check`, `s`.`uptime` FROM `servers` `s`  WHERE `id` = '".$db->real_escape_string($id)."'") or fail($db->error);
        $host = $q->fetch_array();

        if (!$host) {
            throw new EntityNotFound("Host does not exists.");
        }

        $host["last_seen"] = DateTime::createFromFormat("Y-m-d H:i:s", $host["last_check"]);

        $alerts = [];

        $q = $db->query("SELECT `id`, `timestamp`, `type`, `until`, `data`, `active` FROM `alerts` WHERE `server_id` = '".$db->real_escape_string($id)."' ORDER BY `id` DESC LIMIT 0, 25");

        while ($a = $q->fetch_array()) {
            $alerts[] = new Alert($a);
        }

        return $this->renderTemplate("hosts/detail.html", [
            "host" => $host,
            "alerts" => $alerts
        ]);
    }

    public function history($id) {
        $db = connect();

        $q = $db->query("SELECT `id`, `hostname` FROM `servers` WHERE `id` = ".escape($db, $id)) or fail($db->error);
        $host = $q->fetch_array();

        if (!$host) {
            throw new RuntimeException("Host does not exists.");
        }

        $from = 0;
        $count = 25;

        $q = $db->query("SELECT SQL_CALC_FOUND_ROWS `timestamp`, `component`, `action`, `old_value`, `old_version`, `new_value`, `new_version` FROM `changelog` WHERE `server_id` = ".escape($db, $id)." ORDER BY `id` DESC LIMIT ".$from.", ".$count);

        $history = [];

        while ($a = $q->fetch_array()) {
            $timestamp = DateTime::createFromFormat("Y-m-d G:i:s", $a["timestamp"]);

            $action = NULL;
            $message = NULL;

            if ($a["component"] == "packages") {
                switch ($a["action"]) {
                    case "version":
                        $action = "Package upgrade";
                        $message = htmlspecialchars($a["new_value"])." <span class=\"label label-default\">".htmlspecialchars($a["old_version"])."</span> &raquo; <span class=\"label label-primary\">".htmlspecialchars($a["new_version"])."</span>";
                        break;

                    case "install":
                        $action = "Installed";
                        $message = htmlspecialchars($a["new_value"])." <span class=\"label label-primary\">".htmlspecialchars($a["new_version"])."</span>";
                        break;

                    case "remove":
                        $action = "Removed";
                        $message = htmlspecialchars($a["old_value"])." <span class=\"label label-default\">".htmlspecialchars($a["old_version"])."</span>";
                        break;
                }
            } elseif ($a["action"] == "change") {
                $action = strtoupper(htmlspecialchars($a["component"]));
            }

            if (is_null($action)) {
                $action = htmlspecialchars($a["action"]);
            }

            if (is_null($message)) {
                $message = "<span class=\"label label-default\">".htmlspecialchars($a["old_value"])."</span> &raquo; <span class=\"label label-primary\">".htmlspecialchars($a["new_value"])."</span>";
            }

            $history[] = [
                "timestamp" => $timestamp,
                "action" => $action,
                "message" => $message
            ];
        }

        return $this->renderTemplate("hosts/history.html", [
            "host" => $host,
            "history" => $history
        ]);
    }

    public function cron(mysqli $db) {
        $db = connect();

        $q = $db->query("SELECT `s`.`id`, `s`.`last_check` FROM `servers` `s` WHERE `last_check` < DATE_ADD(NOW(), INTERVAL -5 MINUTE)") or fail($db->error);

        $dead_hosts = [];

        while ($a = $q->fetch_array()) {
            // Test if host is not already in failed state.
            $qs = $db->query("SELECT `id` FROM `alerts` WHERE `server_id` = '".$a["id"]."' AND `type` = 'dead' AND `active` = 1") or fail($db->error);
            if (!$qs->fetch_array()) {
                send_alert($db, $a["id"], "dead", ["last_check" => $a["last_check"]], true);
            }

            $dead_hosts[] = $a["id"];
        }

        // Dismiss live hosts dead status.
        $where = ["`type` = 'dead'", "`active` = 1"];
        if (!empty($dead_hosts)) {
            $where[] = "`server_id` NOT IN (".implode(",", $dead_hosts).")";
        }

        $db->query("UPDATE `alerts` SET `active` = 0, `until` = NOW(), `sent` = 0 WHERE ".implode(" AND ", $where)) or fail($db->error);

        $db->commit();
    }
}
