<?php

require_once "controllers/TemplatedController.php";

class PackagesController extends TemplatedController {
    public function index() {
        $this->requireAction("packages_read");

        $db = connect();

        $selfurl = twig_url_for(["PackagesController", "index"]);

        $query = "SELECT SQL_CALC_FOUND_ROWS s.hostname, p.package, p.version FROM packages p JOIN servers s ON (p.server_id = s.id)";
        $where = [];
        $page = (int)($_REQUEST["page"] ?? 1) - 1;
        $limit = 25;

        if (isset($_REQUEST["host"]) && !empty($_REQUEST["host"])) {
            $where[] = "s.hostname LIKE '".$db->real_escape_string(strtr($_REQUEST["host"], array("%" => "%%", "_" => "__", "*" => "%", "?" => "_")))."'";
        }

        if (isset($_REQUEST["package"]) && !empty($_REQUEST["package"])) {
            $where[] = "p.package LIKE '".$db->real_escape_string(strtr($_REQUEST["package"], array("%" => "%%", "_" => "__", "*" => "%", "?" => "_")))."'";
        }

        if (isset($_REQUEST["version"]) && !empty($_REQUEST["version"])) {
            if (isset($_REQUEST["version_match"]) && in_array($_REQUEST["version_match"], ["==", "!=", ">", "<"])) {
                $op = $_REQUEST["version_match"];
            } else {
                $op = "=";
            }

            $where[] = "p.version ".$op." ".escape($db, $_REQUEST["version"]);
        }

        if (!empty($where)) {
            $query .= " WHERE ".implode(" AND ", $where);
        }

        $order = "package";
        if (isset($_REQUEST["order"]) && in_array($_REQUEST["order"], ["hostname", "package", "version"])) {
            $order = $_REQUEST["order"];
        }

        $direction = "ASC";
        if (isset($_REQUEST["direction"]) && in_array($_REQUEST["direction"], ["ASC", "DESC"])) {
            $direction = $_REQUEST["direction"];
        }

        $query .= " ORDER BY ".$order." ".$direction.", p.package ASC, s.hostname ASC LIMIT ".($page * $limit).", ".$limit;

        $q = $db->query($query) or fail($db->error);

        $packages = [];
        while ($a = $q->fetch_array()) {
            $packages[] = $a;
        }

        $rows = $db->query("SELECT FOUND_ROWS() AS total")->fetch_array()["total"];

        $pagination = pagination($rows, $limit, $page, $selfurl);

        return $this->renderTemplate("packages/index.html", [
            "packages" => $packages,
            "pagination" => $pagination
        ]);
    }
}
