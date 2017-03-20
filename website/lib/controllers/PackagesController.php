<?php

require_once "controllers/TemplatedController.php";

class PackagesController extends TemplatedController {
    public function index() {
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

        $display_previous = $page > 0;
        $previous_url = $selfurl."?".http_build_query(array_merge($_GET, array("page" => $page)));

        $from = max(0, ($page - 5) * $limit);
        $to = min($rows, ($page + 5) * $limit);
        $p = floor($from / $limit) + 1;

        $pages = [];

        if ($from > 0) {
            $pages[] = [
                "num" => 1,
                "url" => $selfurl."?".http_build_query(array_merge($_GET, array("page" => 1))),
                "active" => false
            ];

            if ($from > $limit) {
                $pages[] = [
                    "num" => "...",
                    "url" => false,
                    "active" => false
                ];
            }
        }

        for ($i = $from; $i < $to; $i += $limit) {
            $pages[] = [
                "num" => $p,
                "url" => $selfurl."?".http_build_query(array_merge($_GET, array("page" => $p))),
                "active" => $p == $page + 1
            ];
            ++$p;
        }

        if ($to < $rows) {
            $maxpage = floor($rows / $limit) + 1;
            if ($to < ($maxpage - 1) * $limit) {
                $pages[] = [
                    "num" => "...",
                    "url" => false,
                    "active" => false
                ];
            }

            $pages[] = [
                "num" => $maxpage,
                "url" => $selfurl."?".http_build_query(array_merge($_GET, array("page" => $maxpage))),
                "active" => false
            ];
        }

        $display_next = $page + 2 < $p;
        $next_url = $selfurl."?".http_build_query(array_merge($_GET, array("page" => $page + 2)));

        return $this->renderTemplate("packages/index.html", [
            "packages" => $packages,
            "rows" => $rows,
            "pages" => $pages,
            "display_previous" => $display_previous,
            "previous_url" => $previous_url,
            "display_next" => $display_next,
            "next_url" => $next_url
        ]);
    }
}
