<?php

require_once "config.php";
require_once "vendor/autoload.php";

set_include_path(implode(PATH_SEPARATOR, [dirname(__FILE__)]));
date_default_timezone_set("Europe/Prague");

function fail($msg) {
    throw new RuntimeException($msg);
}

function connect() {
    global $config, $db;

    if (!isset($db)) {
        $db = new mysqli($config["mysql-host"], $config["mysql-user"], $config["mysql-password"], $config["mysql-database"]);
        if ($db->connect_error) {
            fail($db->connect_error);
        }

        $db->query("SET NAMES utf8") or fail($db->error);
        $db->autocommit(false);
    }

    return $db;
}

function format_duration($seconds, $type = "long") {
    switch ($type) {
        case "long":
            $dict = [
                "d" => [" days", " day"],
                "h" => [" hours", " hour"],
                "m" => [" minutes", " minute"],
                "s" => [" seconds", " second"]
            ];
            $join = ", ";
            $na = "N/A";
            break;

        case "short":
            $dict = [
                "d" => ["d", "d"],
                "h" => ["h", "h"],
                "m" => ["m", "m"],
                "s" => ["s", "s"]
            ];
            $join = "";
            $na = "";
            break;

        default:
            throw new UnexpectedValueException("Duration type can be either 'long' or 'short'.");
    }

    if ($seconds <= 0 || is_null($seconds)) {
        return $na;
    }

    $out = [];

    if ($seconds >= 86400) {
        $days = floor($seconds / 86400);
        if ($days != 1) {
            $out[] = $days.$dict["d"][0];
        } else {
            $out[] = $days.$dict["d"][1];
        }
        $seconds -= $days * 86400;
    }

    if ($seconds >= 3600) {
        $hours = floor($seconds / 3600);
        if ($hours != 1) {
            $out[] = $hours.$dict["h"][0];
        } else {
            $out[] = $hours.$dict["h"][1];
        }
        $seconds -= $hours * 3600;
    }

    if ($seconds >= 60) {
        $minutes = floor($seconds / 60);
        if ($minutes != 1) {
            $out[] = $minutes.$dict["m"][0];
        } else {
            $out[] = $minutes.$dict["m"][1];
        }
        $seconds -= $minutes * 60;
    }

    if ($seconds > 0) {
        if ($seconds != 1) {
            $out[] = $seconds.$dict["s"][0];
        } else {
            $out[] = $seconds.$dict["s"][1];
        }
    }

    return implode($join, $out);
}

function parse_duration($duration) {
    if (preg_match_all("/(([0-9]+)([wdhms]))\s*/", $duration, $matches)) {
        $duration = 0;
        for ($i = 0; $i < count($matches[1]); ++$i) {
            $multiply = 0;
            switch ($matches[3][$i]) {
                case "w": $multiply = 7*86400; break;
                case "d": $multiply = 86400; break;
                case "h": $multiply = 3600; break;
                case "m": $multiply = 60; break;
                case "s": $multiply = 1; break;
            }

            $duration += $matches[2][$i] * $multiply;
        }
    }

    if ($duration <= 0 || empty($duration)) {
        $duration = NULL;
    }

    return $duration;
}

function escape(mysqli $db, $value) {
    return ((is_null($value))?"NULL":"'".$db->real_escape_string($value)."'");
}

function send_alert(mysqli $db, int $server_id, string $type, $data, bool $active) {
    $db->query("INSERT INTO `alerts` (`server_id`, `timestamp`, `type`, `data`, `active`) VALUES ('".$db->real_escape_string($server_id)."', NOW(), '".$db->real_escape_string($type)."', '".$db->real_escape_string(json_encode($data))."', '".$db->real_escape_string($active)."')") or fail($db->error);
}

function multi_changelog(mysqli $db, array $changes) {
    if (empty($changes)) {
        return;
    }

    $query = "INSERT INTO `changelog` (`server_id`, `timestamp`, `component`, `action`, `old_value`, `old_version`, `new_value`, `new_version`) VALUES ";
    $values = [];

    foreach ($changes as $change) {
        $server_id = escape($db, $change["server_id"] ?? NULL);
        $component = escape($db, $change["component"] ?? NULL);
        $action = escape($db, $change["action"] ?? NULL);
        $old_value = escape($db, $change["old_value"] ?? NULL);
        $new_value = escape($db, $change["new_value"] ?? NULL);
        $old_version = escape($db, $change["old_version"] ?? NULL);
        $new_version = escape($db, $change["new_version"] ?? NULL);

        $values[] = "(".$server_id.", NOW(), ".$component.", ".$action.", ".$old_value.", ".$old_version.", ".$new_value.", ".$new_version.")";
    }

    $db->query($query.implode(", ", $values)) or fail($db->error);
}

function changelog(mysqli $db, int $server_id, string $component, string $action, $old_value = NULL, $new_value = NULL, $old_version = NULL, $new_version = NULL) {
    multi_changelog($db, [
        [
            "server_id" => $server_id,
            "component" => $component,
            "action" => $action,
            "old_value" => $old_value,
            "new_value" => $new_value,
            "old_version" => $old_version,
            "new_version" => $new_version,
        ]
    ]);
}

function pagination($rows, $limit, $page, $selfurl) {
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

    return [
        "rows" => $rows,
        "pages" => $pages,
        "display_previous" => $display_previous,
        "previous_url" => $previous_url,
        "display_next" => $display_next,
        "next_url" => $next_url
    ];
}