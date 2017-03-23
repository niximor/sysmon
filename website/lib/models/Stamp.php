<?php

require_once "exceptions/EntityNotFound.php";

class Stamp {
    public $id;
    public $stamp;
    public $timestamp;
    public $ago;
    public $alert_after;
    public $server_id;

    public $hostname;
    public $in_alert;

    public function __construct($data = NULL) {
        if (!is_null($data)) {
            $this->id = $data["id"] ?? NULL;
            $this->stamp = $data["stamp"] ?? NULL;
            $this->server_id = $data["server_id"] ?? NULL;
            $this->timestamp = (isset($data["timestamp"]))?DateTime::createFromFormat("Y-m-d G:i:s", $data["timestamp"]):NULL;
            $this->alert_after = $data["alert_after"] ?? NULL;

            $this->hostname = $data["hostname"] ?? NULL;

            if (isset($data["in_alert"])) {
                $this->in_alert = (bool)$data["in_alert"];
            } elseif (!is_null($this->timestamp) && !is_null($this->alert_after)) {
                $this->in_alert = $this->timestamp->getTimestamp() + $this->alert_after < time();
            } else {
                $this->in_alert = false;
            }

            $now = new DateTime();

            if (!is_null($this->timestamp)) {
                $this->ago = $now->getTimestamp() - $this->timestamp->getTimestamp();
            }

            if (!is_null($this->timestamp) && !is_null($this->alert_after)) {
                $this->time_remaining_percent = ($this->alert_after - $this->ago) * 100.0 / $this->alert_after;
            }
        }
    }

    public static function put($name, $server = NULL) {
        $db = connect();

        if (is_string($server)) {
            $q = $db->query("SELECT `id` FROM `servers` WHERE `hostname` = ".escape($db, $server)) or fail($db->error);
            $a = $q->fetch_array();

            if (!$a) {
                throw new EntityNotFound("Server was not found.");
            }
            $server = $a["id"];
        }

        // Try if stamp exists.
        $q = $db->query("SELECT `id` FROM `stamps` WHERE `stamp` = ".escape($db, $name)." AND (`server_id` = ".escape($db, $server)." OR `server_id` IS NULL)") or fail($db->error);
        if ($a = $q->fetch_array()) {
            $db->query("UPDATE `stamps` SET `timestamp` = NOW() WHERE `id` = ".escape($db, $a["id"])) or fail($db->error);
            $stamp_id = $a["id"];
        } else {
            $db->query("INSERT INTO `stamps` (`stamp`, `server_id`, `timestamp`) VALUES (".escape($db, $name).", ".escape($db, $server).", NOW())") or fail($db->error);
            $stamp_id = $db->insert_id;
        }

        // Put punchcard entry.
        $now = time();
        $db->query("INSERT INTO `stamp_punchcard` (`stamp_id`, `year`, `week`, `day_of_week`, `hour`, `count`)
            VALUES (
                ".escape($db, $stamp_id).",
                ".escape($db, date("Y", $now)).",
                ".escape($db, date("W", $now)).",
                ".escape($db, date("N", $now)).",
                ".escape($db, date("G", $now)).",
                1
            )
            ON DUPLICATE KEY UPDATE
                `count` = `count` + 1");

        // Dismiss alerts
        $db->query("UPDATE `alerts` SET `active` = 0, `sent` = 0, `until` = NOW() WHERE `stamp_id` = ".escape($db, $stamp_id)." AND `active` = 1 AND `type` = 'stamp'") or fail($db->error);
        $db->commit();
    }

    public function __toString() {
        return var_export($this, true);
    }
}
