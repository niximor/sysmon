<?php

require_once "controllers/TemplatedController.php";
require_once "models/Alert.php";

class OverviewController extends TemplatedController {
    public function index() {
        $db = connect();

        return $this->renderTemplate("overview/index.html", [
            "hosts" => $this->countHosts($db),
            "stamps" => $this->countStamps($db),
            "checks" => $this->countChecks($db),
            "alerts" => $this->listAlerts($db),
        ]);
    }

    public function resolve($id) {
        $db = connect();

        $db->query("UPDATE `alerts` SET `active` = 0, `sent` = 0 WHERE `id` = ".escape($db, $id)." AND `active` > 0");
        if ($db->affected_rows > 0) {
            Message::create(Message::SUCCESS, "Alert has been resolved.");
        }
        $db->commit();

        if (isset($_REQUEST["back"])) {
            header("Location: ".$_REQUEST["back"]);
        } else {
            header("Location: ".twig_url_for(["OverviewController", "index"]));
        }
    }

    public function dismiss($id) {
        $db = connect();

        $db->query("UPDATE `alerts` SET `muted` = 1 WHERE `id` = ".escape($db, $id));
        if ($db->affected_rows > 0) {
            Message::create(Message::SUCCESS, "Alert has been muted.");
        }
        $db->commit();

        if (isset($_REQUEST["back"])) {
            header("Location: ".$_REQUEST["back"]);
        } else {
            header("Location: ".twig_url_for(["OverviewController", "index"]));
        }
    }

    protected function countHosts(mysqli $db) {
        $q = $db->query("SELECT COUNT(`id`) AS `count` FROM `servers`") or fail($db->error);
        $total_count = $q->fetch_array()["count"];

        $q = $db->query("SELECT COUNT(DISTINCT `server_id`) AS `count` FROM `alerts` WHERE `active` = 1 AND `muted` = 0");
        $alert_count = $q->fetch_array()["count"];

        return [
            "total_count" => $total_count,
            "alert_count" => $alert_count
        ];
    }

    protected function countStamps(mysqli $db) {
        $q = $db->query("SELECT COUNT(id) AS `stamp_total_count`, SUM(IF(DATE_ADD(`timestamp`, INTERVAL `alert_after` SECOND) < NOW() AND `status_id` = 1, 1, 0)) AS `stamp_failed` FROM `stamps`") or fail($db->error);
        $a = $q->fetch_array();

        return [
            "total_count" => $a["stamp_total_count"],
            "failed" => $a["stamp_failed"]
        ];
    }

    protected function countChecks(mysqli $db) {
        $q = $db->query("SELECT COUNT(DISTINCT `ch`.`id`) AS `count`, COUNT(DISTINCT `a`.`check_id`) AS `alerts` FROM `checks` `ch` LEFT JOIN `alerts` `a` ON (`a`.`check_id` = `ch`.`id` AND `a`.`active` = 1)") or fail($db->error);
        $a = $q->fetch_array();

        return [
            "total_count" => $a["count"],
            "failed" => $a["alerts"]
        ];
    }

    protected function listAlerts(mysqli $db) {
        return Alert::loadLatest($db);
    }
}
