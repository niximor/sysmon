<?php

require_once "controllers/TemplatedController.php";
require_once "controllers/CronInterface.php";

require_once "models/Stamp.php";

require_once "exceptions/EntityNotFound.php";

class StampsController extends TemplatedController implements CronInterface {
    public function index() {
        $db = connect();

        $where = [];

        $query = "SELECT `s`.`id`, `s`.`stamp`, `sv`.`hostname`, `s`.`timestamp`, `s`.`alert_after`, DATE_ADD(`s`.`timestamp`, INTERVAL `s`.`alert_after` SECOND) < NOW() AS `in_alert` FROM `stamps` `s` JOIN `servers` `sv` ON (`s`.`server_id` = `sv`.`id`)";

        if (isset($_REQUEST["host"]) && !empty($_REQUEST["host"])) {
            $where[] = "`sv`.`hostname` LIKE '".$db->real_escape_string(strtr($_REQUEST["host"], array("%" => "%%", "_" => "__", "*" => "%", "?" => "_")))."'";
        }

        if (isset($_REQUEST["stamp"]) && !empty($_REQUEST["stamp"])) {
            $where[] = "`s`.`stamp` LIKE '".$db->real_escape_string(strtr($_REQUEST["stamp"], array("%" => "%%", "_" => "__", "*" => "%", "?" => "_")))."'";
        }

        if (!empty($where)) {
            $query .= " WHERE ".implode(" AND ", $where);
        }

        $query .= " ORDER BY `s`.`stamp` ASC";

        $q = $db->query($query) or fail($db->error);

        $stamps = [];

        while ($a = $q->fetch_array()) {
            $stamps[] = new Stamp($a);
        }

        return $this->renderTemplate("stamps/index.html", ["stamps" => $stamps]);
    }

    public function detail($id) {
        $db = connect();

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            if (isset($_POST["remove"])) {
                $db->query("DELETE FROM `stamps` WHERE `id` = ".escape($db, $id));

                header("Location: ".twig_url_for(["StampsController", "index"]));
                exit;
            }

            $alert_after = $_POST["alert_after"];

            if (preg_match_all("/(([0-9]+)([wdhms]))\s*/", $alert_after, $matches)) {
                $alert_after = 0;
                for ($i = 0; $i < count($matches[1]); ++$i) {
                    $multiply = 0;
                    switch ($matches[3][$i]) {
                        case "w": $multiply = 7*86400; break;
                        case "d": $multiply = 86400; break;
                        case "h": $multiply = 3600; break;
                        case "m": $multiply = 60; break;
                        case "s": $multiply = 1; break;
                    }

                    $alert_after += $matches[2][$i] * $multiply;
                }
            }

            if ($alert_after <= 0 || empty($alert_after)) {
                $alert_after = NULL;
            }

            $db->query("UPDATE `stamps` SET `alert_after` = ".escape($db, $alert_after)." WHERE `id` = ".escape($db, $id)) or fail($db->error);

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

            header("Location: ".twig_url_for(["StampsController", "detail"], ["id" => $id]));
            exit;
        }


        $q = $db->query("SELECT `s`.`id`, `s`.`stamp`, `sv`.`hostname`, `s`.`timestamp`, `s`.`alert_after` FROM `stamps` `s` JOIN `servers` `sv` ON (`s`.`server_id` = `sv`.`id`) ORDER BY `s`.`stamp` ASC") or fail($db->error);
        $a = $q->fetch_array();

        if (!$a) {
            throw new EntityNotFound("Stamp was not found.");
        }

        return $this->renderTemplate("stamps/detail.html", ["stamp" => new Stamp($a)]);
    }

    public function put($hostname, $stamp) {
        $db = connect();

        $q = $db->query("SELECT `id` FROM `servers` WHERE `hostname` = ".escape($db, $hostname)) or fail($db->error);
        $a = $q->fetch_array();

        if (!$a) {
            http_response_code(400);
            echo "Invalid hostname";
        }

        $server_id = $a["id"];

        $db->query("INSERT INTO `stamps` (`stamp`, `server_id`, `timestamp`) VALUES (".escape($db, $stamp).", ".escape($db, $server_id).", NOW()) ON DUPLICATE KEY UPDATE `timestamp` = VALUES(`timestamp`)") or fail($db->error);

        $q = $db->query("SELECT `id`, `data` FROM `alerts` WHERE `server_id` = ".escape($db, $server_id)." AND `active` = 1 AND `type` = 'stamp'") or fail($db->error);
        while ($a = $q->fetch_array()) {
            $data = json_decode($a["data"]);
            if ($data->stamp == $stamp) {
                $db->query("UPDATE `alerts` SET `active` = 0, `sent` = 0, `until` = NOW() WHERE `id` = ".escape($db, $a["id"]));
            }
        }

        $db->commit();

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
        $q = $db->query("SELECT `stamp`, `server_id`, `timestamp` FROM `stamps` WHERE `alert_after` IS NOT NULL AND DATE_ADD(`timestamp`, INTERVAL `alert_after` SECOND) < NOW()") or fail($db->error);
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

                $db->query("INSERT INTO `alerts` (`server_id`, `timestamp`, `type`, `data`, `active`) VALUES (".escape($db, $a["server_id"]).", NOW(), 'stamp', ".escape($db, json_encode($stamp_data)).", 1)") or fail($db->error);
            }
        }

        $db->commit();
    }
}
