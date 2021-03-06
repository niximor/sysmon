<?php

require_once "controllers/TemplatedController.php";
require_once "controllers/CronInterface.php";

require_once "models/Stamp.php";
require_once "models/Alert.php";

require_once "exceptions/EntityNotFound.php";

require_once "models/Message.php";

class StampsController extends TemplatedController implements CronInterface {
    public function index() {
        $this->requireAction("stamps_read");

        $db = connect();

        $where = [];

        $query = "SELECT `s`.`id`, `s`.`stamp`, `sv`.`hostname`, `s`.`timestamp`, `s`.`alert_after`, (DATE_ADD(`s`.`timestamp`, INTERVAL `s`.`alert_after` SECOND) < NOW() AND `s`.`status_id` = 1) AS `in_alert`, `s`.`status_id` FROM `stamps` `s` LEFT JOIN `servers` `sv` ON (`s`.`server_id` = `sv`.`id`)";

        if (isset($_REQUEST["host"]) && !empty($_REQUEST["host"])) {
            $where[] = "`sv`.`hostname` LIKE '".$db->real_escape_string(strtr($_REQUEST["host"], array("%" => "%%", "_" => "__", "*" => "%", "?" => "_")))."'";
        }

        if (isset($_REQUEST["stamp"]) && !empty($_REQUEST["stamp"])) {
            $where[] = "`s`.`stamp` LIKE '".$db->real_escape_string(strtr($_REQUEST["stamp"], array("%" => "%%", "_" => "__", "*" => "%", "?" => "_")))."'";
        }

        if (!empty($where)) {
            $query .= " WHERE ".implode(" AND ", $where);
        }

        $order = "`s`.`stamp`";

        switch ($_REQUEST["order"] ?? "") {
            case "stamp":
                $order = "`s`.`stamp`";
                break;

            case "hostname":
                $order = "`sv`.`hostname`";
                break;

            case "timestamp":
                $order = "`s`.`timestamp`";
                break;

            case "alert_after":
                $order = "`s`.`alert_after`";
                break;
        }

        $direction = "ASC";
        if (isset($_REQUEST["direction"]) && in_array($_REQUEST["direction"], ["ASC", "DESC"])) {
            $direction = $_REQUEST["direction"];
        }

        $query .= " ORDER BY ".$order." ".$direction.", `s`.`stamp` ASC, `sv`.`hostname` ASC";

        $q = $db->query($query) or fail($db->error);

        $stamps = [];

        while ($a = $q->fetch_array()) {
            $stamps[] = new Stamp($a);
        }

        return $this->renderTemplate("stamps/index.html", ["stamps" => $stamps]);
    }

    public function detail($id) {
        $this->requireAction("stamps_read");

        $db = connect();

        $q = $db->query("SELECT `s`.`id`, `s`.`stamp`, `sv`.`hostname`, `s`.`server_id`, `s`.`timestamp`, `s`.`alert_after`, `s`.`status_id` FROM `stamps` `s` LEFT JOIN `servers` `sv` ON (`s`.`server_id` = `sv`.`id`) WHERE `s`.`id` = ".escape($db, $id)." ORDER BY `s`.`stamp` ASC") or fail($db->error);
        $stamp = $q->fetch_array();

        if (!$stamp) {
            throw new EntityNotFound("Stamp was not found.");
        }

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $this->requireAnyAction("stamps_write", "stamps_change_host");

            if (isset($_POST["remove"])) {
                $this->requireAction("stamps_write");

                $db->query("DELETE FROM `stamps` WHERE `id` = ".escape($db, $id));
                $db->commit();

                Message::create(Message::SUCCESS, "Stamp has been removed.");

                header("Location: ".twig_url_for(["StampsController", "index"]));
                exit;
            }

            if (isset($_POST["alert_after"])) {
                $this->requireAction("stamps_write");
                $alert_after = parse_duration($_POST["alert_after"]);
            } else {
                $alert_after = $stamp["alert_after"];
            }

            if (isset($_POST["server"])) {
                $server = $_POST["server"] ?? NULL;
                if (empty($server)) {
                    $server = NULL;
                }
            } else {
                $server = $stamp["server_id"];
            }

            if (isset($_POST["pause"])) {
                $status_id = ($stamp["status_id"] == 1) ? 2 : 1;
            } else {
                $status_id = $stamp["status_id"];
            }

            $db->query("UPDATE `stamps` SET `alert_after` = ".escape($db, $alert_after).", `server_id` = ".escape($db, $server).", `status_id` = ".escape($db, $status_id)." WHERE `id` = ".escape($db, $id)) or fail($db->error);

            // Dismiss alerts if the stamp is now valid.
            $q = $db->query("SELECT `timestamp`, `server_id` FROM `stamps` WHERE `id` = ".escape($db, $id)) or fail($db->error);
            if ($a = $q->fetch_array()) {
                $timestamp = DateTime::createFromFormat("Y-m-d G:i:s", $a["timestamp"]);
                if (is_null($alter_after) || $timestamp->getTimestamp() + $alter_after > time()) {
                    $q = $db->query("SELECT `id`, `data` FROM `alerts` WHERE `server_id` = ".escape($db, $a["server_id"])." AND `active` = 1 AND `type` = 'stamp'") or fail($db->error);
                    while ($a = $q->fetch_array()) {
                        $data = json_decode($a["data"]);
                        $last_run = DateTime::createFromFormat("Y-m-d G:i:s", $data->last_run);
                        if ($last_run->getTimestamp() + $alert_after > time()) {
                            $db->query("UPDATE `alerts` SET `active` = 0, `sent` = 0 WHERE `id`= ".escape($db, $a["id"])) or fail($db->error);
                        }
                    }
                }
            }

            $db->commit();

            Message::create(Message::SUCCESS, "Stamp has been modified.");

            header("Location: ".twig_url_for(["StampsController", "detail"], ["id" => $id]));
            exit;
        }

        return $this->renderTemplate("stamps/detail.html", [
            "stamp" => new Stamp($stamp),
            "servers" => $this->listServers($db),
            "alerts" => Alert::loadLatest($db, ["stamp_id" => $stamp["id"]]),
        ]);
    }

    public function add() {
        $this->requireAction("stamps_write");

        $db = connect();

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $alert_after = parse_duration($_POST["alert_after"]);
            $server = $_POST["server"] ?? NULL;
            if (empty($server)) {
                $server = NULL;
            }

            $db->query("INSERT INTO `stamps` (`stamp`, `server_id`, `timestamp`, `alert_after`)
                VALUES (".escape($db, $_REQUEST["name"]).", ".escape($db, $server).", NOW(), ".escape($db, $alert_after).")") or fail($db->error);
            $db->commit();

            Message::create(Message::SUCCESS, "Stamp has been created.");
            header("Location: ".twig_url_for(["StampsController", "index"]));
            exit;
        }

        return $this->renderTemplate("stamps/add.html", [
            "servers" => $this->listServers($db)
        ]);
    }

    public function punchcard($id) {
        $this->requireAction("stamps_read");

        $db = connect();

        $q = $db->query("SELECT
                `stamps`.`id`,
                `stamps`.`stamp`,
                `stamps`.`server_id`,
                `servers`.`hostname`
            FROM `stamps`
            LEFT JOIN `servers` ON (`stamps`.`server_id` = `servers`.`id`)
            WHERE
                `stamps`.`id` = ".escape($db, $id)) or fail($db->error);
        $stamp = $q->fetch_array();

        $to = time();
        $from = $to - 7*86400;

        $year_from = escape($db, date("Y", $from));
        $week_from = escape($db, date("W", $from));
        $dow_from = escape($db, date("N", $from));
        $hour_from = escape($db, date("G", $from));

        $from = $year_from * 1000000 + $week_from * 10000 + $dow_from * 100 + $hour_from + 1;

        $year_to = escape($db, date("Y", $to));
        $week_to = escape($db, date("W", $to));
        $dow_to = escape($db, date("N", $to));
        $hour_to = escape($db, date("G", $to));

        $to = $year_to * 1000000 + $week_to * 10000 + $dow_to * 100 + $hour_to;

        $query = "SELECT
                SUM(`count`) AS `count`,
                `day_of_week`,
                `hour`
                FROM `stamp_punchcard`
                WHERE
                    `stamp_id` = ".escape($db, $stamp["id"])."
                    AND `year` * 1000000 + `week` * 10000 + `day_of_week` * 100 + `hour` BETWEEN ".$from." AND ".$to."
                GROUP BY `day_of_week`, `hour`";
        $q = $db->query($query) or fail($db->error);

        $max = 0;
        $punchcard = [];

        for ($w = 1; $w <= 7; ++$w) {
            $punchcard[$w] = [];
            for ($h = 0; $h < 24; ++$h) {
                $punchcard[$w][$h] = 0;
            }
        }

        while ($a = $q->fetch_array()) {
            if ($max < $a["count"]) {
                $max = $a["count"];
            }

            $punchcard[(int)$a["day_of_week"]][(int)$a["hour"]] = $a["count"];
        }

        return $this->renderTemplate("stamps/punchcard.html", [
            "stamp" => $stamp,
            "punchcard" => $punchcard,
            "max_punchcard" => $max,
            "days" => [
                [1, "Monday"],
                [2, "Tuesday"],
                [3, "Wednesday"],
                [4, "Thursday"],
                [5, "Friday"],
                [6, "Saturday"],
                [7, "Sunday"]
            ],
            "hours" => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23]
        ]);
    }

    public function put($hostname, $stamp) {
        try {
            Stamp::put($stamp, $hostname);
        } catch (EntityNotFound $e) {
            http_response_code(400);
            echo "Invalid hostname";
        }

        return json_encode(["status" => "OK"]);
    }

    public function put_nohost($stamp) {
        Stamp::put($stamp);

        return json_encode(["status" => "OK"]);
    }

    public function cron(mysqli $db) {
        $db = connect();

        // Select currently active alerts for all stamps.
        $q = $db->query("SELECT `id`, `data`, `server_id` FROM `alerts` WHERE `type` = 'stamp' AND `active` = 1") or fail($db->error);

        $stamps = array();
        while ($a = $q->fetch_array()) {
            $a["data"] = json_decode($a["data"]);
            $stamps[] = $a;
        }

        // Select current stamps that fails.
        $q = $db->query("SELECT `id`, `stamp`, `server_id`, `timestamp` FROM `stamps` WHERE `alert_after` IS NOT NULL AND DATE_ADD(`timestamp`, INTERVAL `alert_after` SECOND) < NOW() AND `status_id` = 1") or fail($db->error);
        while ($a = $q->fetch_array()) {
            // Is the stamp already alerted?
            $found = false;
            foreach ($stamps as $stamp) {
                if ($stamp["server_id"] == $a["server_id"] AND $stamp["data"]->stamp == $a["stamp"]) {
                    $found = true;
                    break;
                }
            }

            // If not already alerted, create new alert.
            if (!$found) {
                $stamp_data = [
                    "stamp" => $a["stamp"],
                    "last_run" => $a["timestamp"]
                ];

                $db->query("INSERT INTO `alerts` (`server_id`, `stamp_id`, `timestamp`, `type`, `data`, `active`) VALUES (".escape($db, $a["server_id"]).", ".escape($db, $a["id"]).", NOW(), 'stamp', ".escape($db, json_encode($stamp_data)).", 1)") or fail($db->error);
            }
        }

        $db->commit();
    }

    protected function listServers(mysqli $db) {
        $q = $db->query("SELECT `id`, `hostname` FROM `servers` WHERE `virtual` = 0 ORDER BY `hostname` ASC") or fail($db->error);
        $servers = [];
        while ($a = $q->fetch_array()) {
            $servers[] = [
                "id" => $a["id"],
                "hostname" => $a["hostname"]
            ];
        }

        return $servers;
    }
}
