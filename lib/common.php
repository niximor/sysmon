<?php

require_once "config.php";
require_once "vendor/autoload.php";

set_include_path(implode(PATH_SEPARATOR, [dirname(__FILE__)]));
date_default_timezone_set("Europe/Prague");

function fail($msg) {
    throw new RuntimeException($msg);
}

function connect() {
    global $config;

    $db = new mysqli($config["mysql-host"], $config["mysql-user"], $config["mysql-password"], $config["mysql-database"]);
    if ($db->connect_error) {
        fail($db->connect_error);
    }

    $db->query("SET NAMES utf8") or fail($db->error);

    return $db;
}

function format_duration($seconds) {
    if ($seconds <= 0 || is_null($seconds)) {
        return "N/A";
    }

    $out = [];

    if ($seconds > 86400) {
        $days = floor($seconds / 86400);
        $out[] = $days." days";
        $seconds -= $days * 86400;
    }

    if ($seconds > 3600 || !empty($out)) {
        $hours = floor($seconds / 3600);
        $out[] = $hours." hours";
        $seconds -= $hours * 3600;
    }

    if ($seconds > 60 || !empty($out)) {
        $minutes = floor($seconds / 60);
        $out[] = $minutes." minutes";
        $seconds -= $minutes * 60;
    }

    $out[] = $seconds." seconds";

    return implode(", ", $out);
}

function escape(mysqli $db, $value) {
    return ((is_null($value))?"NULL":"'".$db->real_escape_string($value)."'");
}

function send_alert(mysqli $db, int $server_id, string $type, $data, bool $active) {
    $db->query("INSERT INTO `alerts` (`server_id`, `timestamp`, `type`, `data`, `active`) VALUES ('".$db->real_escape_string($server_id)."', NOW(), '".$db->real_escape_string($type)."', '".$db->real_escape_string(json_encode($data))."', '".$db->real_escape_string($active)."')") or fail($db->error);
}

function format_alert($type, $data, $until = NULL) {
    if (!is_null($until)) {
        $until = DateTime::createFromFormat("Y-m-d G:i:s", $until);
    } else {
        $until = new DateTime();
    }

    switch ($type) {
        case "dead":
            $since = DateTime::createFromFormat("Y-m-d H:i:s", $data->last_check);
            return "Host is dead since ".$since->format("Y-m-d H:i:s")." (down for ".format_duration($until->getTimestamp() - $since->getTimestamp()).").";

        case "rebooted":
            return "Host has been rebooted. Was up for ".format_duration($data->uptime).".";

        default:
            return $type;
    }
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
