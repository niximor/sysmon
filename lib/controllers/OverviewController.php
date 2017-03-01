<?php

require_once "controllers/TemplatedController.php";
require_once "models/Alert.php";

class OverviewController extends TemplatedController {
    public function index() {
        $db = connect();
        $q = $db->query("SELECT COUNT(`id`) AS `count` FROM `servers`") or fail($db->error);
        $total_count = $q->fetch_array()["count"];

        $q = $db->query("SELECT COUNT(DISTINCT `server_id`) AS `count` FROM `alerts` WHERE `active` = 1");
        $alert_count = $q->fetch_array()["count"];

        $display = 25;

        $q_all = $db->query("SELECT `s`.`hostname`, `s`.`id` AS `server_id`, `a`.`id`, `a`.`timestamp`, `a`.`type`, `a`.`data`, `a`.`active`, `a`.`until` FROM `alerts` `a` JOIN `servers` `s` ON (`a`.`server_id` = `s`.`id`) WHERE `a`.`active` = 1 OR `a`.`timestamp` >= DATE_ADD(NOW(), INTERVAL -7 DAY) ORDER BY `id` DESC LIMIT 0, ".$display) or fail($db->error);

        $alerts = array();
        $lowest_id = NULL;
        while ($a = $q_all->fetch_array()) {
            $alerts[] = new Alert($a);

            if (is_null($lowest_id) || $lowest_id > $a["id"]) {
                $lowest_id = $a["id"];
            }
        }

        $q_active = $db->query("SELECT `s`.`hostname`, `a`.`id`, `a`.`timestamp`, `a`.`type`, `a`.`data`, `a`.`active`, `a`.`until` FROM `alerts` `a` JOIN `servers` `s` ON (`a`.`server_id` = `s`.`id`) WHERE `a`.`active` = 1 AND `a`.`id` < '".$lowest_id."' ORDER BY `id` DESC") or fail($db->error);

        $max_to_remove = $q_active->num_rows;
        for ($i = count($alerts) - 1; $i >= 0; --$i) {
            if (!$alerts[$i]->active && $max_to_remove-- > 0) {
                unset($alerts[$i]);
            }
        }

        while ($a = $q_active->fetch_array()) {
            $alerts[] = new Alert($a);
        }

        return $this->renderTemplate("overview/index.html", [
            "total_count" => $total_count,
            "alert_count" => $alert_count,
            "alerts" => $alerts
        ]);
    }
}
