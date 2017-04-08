<?php

require_once "controllers/TemplatedController.php";
require_once "controllers/CronInterface.php";

require_once "models/Alert.php";

require_once "exceptions/EntityNotFound.php";

class HostsController extends TemplatedController implements CronInterface {
    public function index() {
        $this->requireAction('hosts_read');

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
        $this->requireAction('hosts_read');

        $db = connect();

        $q = $db->query("SELECT `s`.`id`, `s`.`hostname`, `s`.`distribution`, `s`.`version`, `s`.`kernel`, `s`.`ip`, `s`.`last_check`, `s`.`uptime`, `s`.`virtual` FROM `servers` `s`  WHERE `id` = '".$db->real_escape_string($id)."'") or fail($db->error);
        $host = $q->fetch_array();

        if (!$host) {
            throw new EntityNotFound("Host does not exists.");
        }

        $host["last_seen"] = DateTime::createFromFormat("Y-m-d H:i:s", $host["last_check"]);

        return $this->renderTemplate("hosts/detail.html", [
            "host" => $host,
            "alerts" => Alert::loadLatest($db, ["server_id" => $id]),
        ]);
    }

    public function history($id) {
        $this->requireAction('hosts_read');

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

    public function charts($id) {
        $this->requireAction('hosts_read');
        $this->requireAction('charts_read');

        $db = connect();

        $q = $db->query("SELECT `id`, `hostname` FROM `servers` WHERE `id` = ".escape($db, $id));
        $server = $q->fetch_array();

        if (!$server) {
            throw new EntityNotFound("Host does not exists.");
        }

        $q = $db->query("SELECT
            `check_charts`.`id`,
            `check_charts`.`name`,
            `checks`.`name` AS `check`,
            `checks`.`id` AS `check_id`,
            `check_groups`.`name` AS `group`
            FROM `checks`
            JOIN `check_charts` ON (`checks`.`type_id` = `check_charts`.`check_type_id`)
            LEFT JOIN `check_groups` ON (`checks`.`group_id` = `check_groups`.`id`)
            WHERE `checks`.`server_id` = ".escape($db, $server["id"])."
            ORDER BY
                `check_groups`.`name` ASC,
                `checks`.`name` ASC,
                `check_charts`.`name` ASC") or fail($db->error);

        $charts = [];
        while ($a = $q->fetch_array()) {
            $charts[] = [
                "id" => $a["id"],
                "name" => $a["name"],
                "check" => $a["check"],
                "check_id" => $a["check_id"],
                "group" => $a["group"]
            ];
        }

        return $this->renderTemplate("hosts/charts.html", [
            "host" => $server,
            "charts" => $charts,
        ]);
    }

    public function add() {
        $this->requireAction('hosts_write');

        $db = connect();

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $db->query("INSERT INTO `servers` (`hostname`, `last_check`, `distribution`, `version`, `kernel`, `ip`, `virtual`) VALUES (
                    ".escape($db, $_REQUEST["hostname"]).",
                    NOW(),
                    '',
                    '',
                    '',
                    ".escape($db, gethostbyname($_REQUEST["hostname"])).",
                    1
                )") or fail($db->error);
            $server_id = $db->insert_id;

            $poll_interval = parse_duration($_REQUEST["poll_interval"]);

            $db->query("INSERT INTO `snmp_devices` (`server_id`, `agent_id`, `hostname`, `port`, `version`, `community`, `poll_interval`) VALUES (
                    ".escape($db, $server_id).",
                    ".escape($db, $_REQUEST["agent"]).",
                    ".escape($db, $_REQUEST["hostname"]).",
                    ".escape($db, $_REQUEST["port"]).",
                    ".escape($db, $_REQUEST["version"]).",
                    ".escape($db, $_REQUEST["community"]).",
                    ".escape($db, $poll_interval)."
                )") or fail($db->error);

            $db->commit();

            Message::create(Message::SUCCESS, "Device has been successfully created.");
            header("Location: ".twig_url_for(['HostsController', 'index']));
            exit;
        }

        return $this->renderTemplate("hosts/add.html", [
            "servers" => $this->loadServers($db)
        ]);
    }

    public function edit($id) {
        $this->requireAction('hosts_write');

        $db = connect();

        $q = $db->query("SELECT
                `d`.`server_id`,
                `d`.`agent_id`,
                `d`.`hostname`,
                `d`.`port`,
                `d`.`version`,
                `d`.`community`,
                `d`.`poll_interval`,
                `s`.`hostname` AS `server_hostname`
            FROM `snmp_devices` `d`
            JOIN `servers` `s` ON (`d`.`server_id` = `s`.`id`)
            WHERE `d`.`server_id` = ".escape($db, $id)) or fail($db->error);

        $device = $q->fetch_assoc();

        if (!$device) {
            throw new EntityNotFound("Device does not exists.");
        }

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $poll_interval = parse_duration($_REQUEST["poll_interval"]);

            $db->query("UPDATE `snmp_devices`
                SET
                    `agent_id` = ".escape($db, $_REQUEST["agent"]).",
                    `hostname` = ".escape($db, $_REQUEST["hostname"]).",
                    `port` = ".escape($db, $_REQUEST["port"]).",
                    `version` = ".escape($db, $_REQUEST["version"]).",
                    `community` = ".escape($db, $_REQUEST["community"]).",
                    `poll_interval` = ".escape($db, $poll_interval)."
                WHERE `server_id` = ".escape($db, $device["server_id"])) or fail($db->error);

            $db->commit();

            Message::create(Message::SUCCESS, "Device has been modified.");
            header("Location: ".twig_url_for(['HostsController', 'detail'], ["id" => $device["server_id"]]));
            exit;
        }

        return $this->renderTemplate("hosts/edit.html", [
            "device" => $device,
            "servers" => $this->loadServers($db)
        ]);
    }

    public function remove($id) {
        $this->requireAction('hosts_write');

        $db = connect();

        $db->query("DELETE FROM `servers` WHERE `id` = ".escape($db, $id)." AND `virtual` = 1") or fail($db->error);

        if ($db->affected_rows == 0) {
            throw new EntityNotFound("Device does not exists.");
        }

        $db->commit();

        Message::create(Message::SUCCESS, "Device has been removed.");

        header("Location: ".twig_url_for(["HostsController", "index"]));
        exit;
    }

    public function list($hostname) {
        // List devices that should be queried over SNMP from specific agent.
        $db = connect();

        $q = $db->query("SELECT `id` FROM `servers` `s` WHERE `s`.`hostname` = ".escape($db, $hostname)." AND `s`.`virtual` = 0");
        $a = $q->fetch_assoc();

        if (!$a) {
            throw new EntityNotFound("Host was not found.");
        }

        $q = $db->query("SELECT `d`.`server_id`, `d`.`hostname`, `d`.`port`, `d`.`version`, `d`.`community`
            FROM `snmp_devices` `d`
            JOIN `servers` `s` ON (`s`.`id` = `d`.`server_id`)
            WHERE `d`.`agent_id` = ".escape($db, $a["id"])." AND DATE_ADD(`s`.`last_check`, INTERVAL `d`.`poll_interval` - 60 SECOND) < NOW()");

        $devices = [];

        while ($a = $q->fetch_assoc()) {
            $devices[] = [
                "id" => $a["server_id"],
                "hostname" => $a["hostname"],
                "port" => $a["port"],
                "version" => $a["version"],
                "community" => $a["community"]
            ];
        }

        return json_encode($devices);
    }

    protected function loadServers(mysqli $db) {
        $q = $db->query("SELECT `id`, `hostname` FROM `servers` WHERE `virtual` = 0 ORDER BY `hostname` ASC");

        $servers = [];
        while ($server = $q->fetch_assoc()) {
            $servers[] = $server;
        }

        return $servers;
    }

    public function cron(mysqli $db) {
        $db = connect();

        // For SNMP devices, the dead interval is 3*poll interval.
        // For physical devices, it is 300 seconds (5 minutes).
        $q = $db->query("SELECT `s`.`id`, `s`.`last_check` FROM `servers` `s` LEFT JOIN `snmp_devices` `d` ON (`d`.`server_id` = `s`.`id`) WHERE `s`.`last_check` < DATE_ADD(NOW(), INTERVAL -3*COALESCE(`d`.`poll_interval`, 100) SECOND)") or fail($db->error);

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
