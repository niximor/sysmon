<?php

require_once "controllers/TemplatedController.php";

require_once "models/Message.php";

class ChecksController extends TemplatedController {
    public function overview() {
        $this->requireAction("checks_read");

        $db = connect();

        $q = $db->query("SELECT
                SUM(`s`.`checks`) AS `checks`,
                SUM(`s`.`alerts`) - SUM(`s`.`disabled`) AS `alerts`,
                SUM(`s`.`disabled`) AS `disabled`,
                SUM(`s`.`checks`) - SUM(`s`.`alerts`) - SUM(`s`.`disabled`) AS `success`
            FROM (SELECT
                    1 AS `checks`,
                    IF(`ch`.`enabled`, 0, 1) AS `disabled`,
                    IF(`a`.`id` IS NOT NULL, 1, 0) AS `alerts`
                FROM `checks` `ch`
                LEFT JOIN `alerts` `a` ON (`a`.`check_id` = `ch`.`id` AND `a`.`active` = 1)
                GROUP BY `ch`.`id`
            ) AS `s`") or fail($db->error);

        $total = $q->fetch_assoc();

        $q = $db->query("SELECT
                `s`.`hostname`,
                SUM(`s`.`checks`) AS `checks`,
                SUM(`s`.`alerts`) - SUM(`s`.`disabled`) AS `alerts`,
                SUM(`s`.`disabled`) AS `disabled`,
                SUM(`s`.`checks`) - SUM(`s`.`alerts`) - SUM(`s`.`disabled`) AS `success`
            FROM (SELECT
                    `s`.`hostname`,
                    `ch`.`server_id`,
                    1 AS `checks`,
                    IF(`ch`.`enabled`, 0, 1) AS `disabled`,
                    IF(`a`.`id` IS NOT NULL, 1, 0) AS `alerts`
                FROM `checks` `ch`
                JOIN `servers` `s` ON (`ch`.`server_id` = `s`.`id`)
                LEFT JOIN `alerts` `a` ON (`a`.`check_id` = `ch`.`id` AND `a`.`active` = 1)
                GROUP BY `ch`.`id`
            ) AS `s`
            GROUP BY `s`.`server_id`
            ORDER BY `s`.`hostname` ASC") or fail($db->error);

        $hosts = [];
        while ($a = $q->fetch_assoc()) {
            $hosts[] = $a;
        }

        $q = $db->query("SELECT
                `s`.`name`,
                `s`.`id`,
                SUM(`s`.`checks`) AS `checks`,
                SUM(`s`.`alerts`) - SUM(`s`.`disabled`) AS `alerts`,
                SUM(`s`.`disabled`) AS `disabled`,
                SUM(`s`.`checks`) - SUM(`s`.`alerts`) - SUM(`s`.`disabled`) AS `success`
            FROM (SELECT
                    `g`.`name`,
                    `g`.`id`,
                    1 AS `checks`,
                    IF(`ch`.`enabled`, 0, 1) AS `disabled`,
                    IF(`a`.`id` IS NOT NULL, 1, 0) AS `alerts`
                FROM `checks` `ch`
                LEFT JOIN `check_groups` `g` ON (`ch`.`group_id` = `g`.`id`)
                LEFT JOIN `alerts` `a` ON (`a`.`check_id` = `ch`.`id` AND `a`.`active` = 1)
                GROUP BY `ch`.`id`
            ) AS `s`
            GROUP BY `s`.`id`
            ORDER BY `s`.`name` ASC") or fail($db->error);

        $groups = [];
        while ($a = $q->fetch_assoc()) {
            $groups[] = $a;
        }

        $q = $db->query("SELECT
                `s`.`name`,
                `s`.`id`,
                SUM(`s`.`checks`) AS `checks`,
                SUM(`s`.`alerts`) - SUM(`s`.`disabled`) AS `alerts`,
                SUM(`s`.`disabled`) AS `disabled`,
                SUM(`s`.`checks`) - SUM(`s`.`alerts`) - SUM(`s`.`disabled`) AS `success`
            FROM (SELECT
                    `t`.`name`,
                    `t`.`id`,
                    1 AS `checks`,
                    IF(`ch`.`enabled`, 0, 1) AS `disabled`,
                    IF(`a`.`id` IS NOT NULL, 1, 0) AS `alerts`
                FROM `checks` `ch`
                JOIN `check_types` `t` ON (`ch`.`type_id` = `t`.`id`)
                LEFT JOIN `alerts` `a` ON (`a`.`check_id` = `ch`.`id` AND `a`.`active` = 1)
                GROUP BY `ch`.`id`
            ) AS `s`
            GROUP BY `s`.`id`
            ORDER BY `s`.`name` ASC") or fail($db->error);

        $types = [];
        while ($a = $q->fetch_assoc()) {
            $types[] = $a;
        }

        return $this->renderTemplate("checks/overview.html", [
            "total" => $total,
            "hosts" => $hosts,
            "groups" => $groups,
            "types" => $types
        ]);
    }

    public function index() {
        $this->requireAction("checks_read");

        $db = connect();

        $order = "name";
        $direction = "ASC";

        if (isset($_REQUEST["order"]) && in_array($_REQUEST["order"], ["name", "hostname", "type", "interval"])) {
            $order = $_REQUEST["order"];
        }

        if (isset($_REQUEST["direction"]) && in_array($_REQUEST["direction"], ["ASC", "DESC"])) {
            $direction = $_REQUEST["direction"];
        }

        $where = [];

        if (!empty($_REQUEST["type"])) {
            $where[] = "`ch`.`type_id` = ".escape($db, $_REQUEST["type"]);
        }

        if (!empty($_REQUEST["name"])) {
            $where[] = "`ch`.`name` LIKE '".$db->real_escape_string(strtr($_REQUEST["name"], array("%" => "%%", "_" => "__", "*" => "%", "?" => "_")))."'";
        }

        if (!empty($_REQUEST["host"])) {
            $where[] = "`s`.`hostname` LIKE '".$db->real_escape_string(strtr($_REQUEST["host"], array("%" => "%%", "_" => "__", "*" => "%", "?" => "_")))."'";
        }

        if (isset($_REQUEST["group"]) && (!empty($_REQUEST["group"]) || $_REQUEST["group"] == "0")) {
            if ($_REQUEST["group"] == "0") {
                $where[] = "`ch`.`group_id` IS NULL";
            } else {
                $where[] = "`ch`.`group_id` = ".escape($db, $_REQUEST["group"]);
            }
        }

        $query = "SELECT
                SQL_CALC_FOUND_ROWS
                `ch`.`id`,
                `ch`.`enabled`,
                `ch`.`name`,
                `ch`.`interval`,
                `s`.`hostname`,
                `t`.`name` AS `type`,
                COUNT(`a`.`id`) AS `alerts`,
                `g`.`name` AS `group`,
                `ch`.`group_id`
            FROM `checks` `ch`
            JOIN `servers` `s` ON (`s`.`id` = `ch`.`server_id`)
            JOIN `check_types` `t` ON (`ch`.`type_id` = `t`.`id`)
            LEFT JOIN `check_groups` `g` ON (`ch`.`group_id` = `g`.`id`)
            LEFT JOIN `alerts` `a` ON (`a`.`check_id` = `ch`.`id` AND `a`.`active` = 1)";

        if (!empty($where)) {
            $query .= " WHERE ".implode(" AND ", $where);
        }

        $query .= "
            GROUP BY `ch`.`id`
            ORDER BY `g`.`name` ".$direction.", `".$order."` ".$direction.", `ch`.`name` ".$direction.", `s`.`hostname` ".$direction;

        $page = (int)($_REQUEST["page"] ?? 1) - 1;
        $limit = 25;

        $query .= " LIMIT ".($page * $limit).", ".$limit;

        $q = $db->query($query) or fail($db->error);

        $checks = [];

        while ($a = $q->fetch_array()) {
            $checks[] = [
                "id" => $a["id"],
                "enabled" => $a["enabled"],
                "name" => $a["name"],
                "type" => $a["type"],
                "interval" => $a["interval"],
                "hostname" => $a["hostname"],
                "alerts" => $a["alerts"],
                "group" => $a["group"],
                "group_id" => $a["group_id"],
            ];
        }

        $selfurl = twig_url_for(['ChecksController', 'index']);
        $rows = $db->query("SELECT FOUND_ROWS() AS `count`")->fetch_assoc()["count"];

        return $this->renderTemplate("checks/index.html", [
            "checks" => $checks,
            "types" => $this->loadTypes($db),
            "groups" => $this->loadGroups($db),
            "pagination" => pagination($rows, $limit, $page, $selfurl)
        ]);
    }

    public function detail($id) {
        $this->requireAction("checks_read");

        $db = connect();

        $q = $db->query("SELECT
                `ch`.`id`,
                `ch`.`enabled`,
                `ch`.`name`,
                `ch`.`interval`,
                `ch`.`last_check`,
                `s`.`hostname`,
                `t`.`name` AS `type`,
                `t`.`id` AS `type_id`,
                `g`.`name` AS `group`
            FROM `checks` `ch`
            JOIN `servers` `s` ON (`s`.`id` = `ch`.`server_id`)
            JOIN `check_types` `t` ON (`ch`.`type_id` = `t`.`id`)
            LEFT JOIN `check_groups` `g` ON (`ch`.`group_id` = `g`.`id`)
            WHERE `ch`.`id` = ".escape($db, $id)) or fail($db->error);
        $check = $q->fetch_array();

        if (!$check) {
            throw new EntityNotFound("Check was not found.");
        }

        $granularity = "daily";
        if (isset($_REQUEST["granularity"]) && in_array($_REQUEST["granularity"], ["daily", "weekly", "monthly", "yearly"])) {
            $granularity = $_REQUEST["granularity"];
        }

        list($chart, $readings) = $this->selectChart($db, $check["type_id"]);

        return $this->renderTemplate("checks/detail.html", [
            "check" => $check,
            "alerts" => Alert::loadLatest($db, ["check_id" => $check["id"]]),
            "chart" => $chart,
            "granularity" => $granularity,
            "charts" => $this->loadAllCharts($db, $check["type_id"])
        ]);
    }

    public function charts($id) {
        $this->requireAction("checks_read");

        $db = connect();
        $q = $db->query("SELECT
            `ch`.`id`,
            `ch`.`name`,
            `ch`.`type_id`,
            `ch`.`server_id`,
            `s`.`hostname`
            FROM `checks` `ch`
            JOIN `servers` `s` ON (`s`.`id` = `ch`.`server_id`)
            WHERE `ch`.`id` = ".escape($db, $id)) or fail($db->error);

        $check = $q->fetch_array();
        if (!$check) {
            throw new EntityNotFound("Check was not found.");
        }

        $charts = $this->loadAllCharts($db, $check["type_id"]);

        return $this->renderTemplate("checks/charts.html", [
            "check" => $check,
            "charts" => $charts,
        ]);
    }

    public function chart_detail($check_id, $chart_id) {
        $this->requireAction("checks_read");

        $db = connect();

        $q = $db->query("SELECT `ch`.`id`, `ch`.`name`, `ch`.`type_id`, `ch`.`server_id`, `s`.`hostname` FROM `checks` `ch` JOIN `servers` `s` ON (`s`.`id` = `ch`.`server_id`) WHERE `ch`.`id` = ".escape($db, $check_id)) or fail($db->error);

        $check = $q->fetch_array();
        if (!$check) {
            throw new EntityNotFound("Check was not found.");
        }

        $q = $db->query("SELECT `ch`.`id`, `ch`.`name` FROM `check_charts` `ch` WHERE `id` = ".escape($db, $chart_id)." AND `check_type_id` = ".escape($db, $check["type_id"])) or fail($db->error);
        $chart = $q->fetch_array();

        if (!$chart) {
            throw new EntityNotFound("Chart was not found.");
        }

        return $this->renderTemplate("checks/chart_detail.html", [
            "check" => $check,
            "chart" => $chart
        ]);
    }

    public function group_detail($group_id) {
        $this->requireAction("checks_read");

        $db = connect();

        $q = $db->query("SELECT `id`, `name` FROM `check_groups` WHERE `id` = ".escape($db, $group_id)) or fail($db->error);
        $group = $q->fetch_array();

        if (!$group) {
            throw new EntityNotFound("Chart group was not found.");
        }

        $query = "SELECT
                `ch`.`id`,
                `ch`.`enabled`,
                `ch`.`name`,
                `ch`.`interval`,
                `s`.`hostname`,
                `t`.`name` AS `type`,
                COUNT(`a`.`id`) AS `alerts`,
                `check_charts`.`id` AS `chart_id`,
                `check_charts`.`name` AS `chart_name`
            FROM `checks` `ch`
            JOIN `servers` `s` ON (`s`.`id` = `ch`.`server_id`)
            JOIN `check_types` `t` ON (`ch`.`type_id` = `t`.`id`)
            LEFT JOIN `check_charts` ON (`check_charts`.`check_type_id` = `ch`.`type_id`)
            LEFT JOIN `alerts` `a` ON (`a`.`check_id` = `ch`.`id` AND `a`.`active` = 1)
            WHERE `ch`.`group_id` = ".escape($db, $group["id"])."
            GROUP BY `ch`.`id`, `check_charts`.`id`
            ORDER BY `ch`.`name` ASC, `s`.`hostname` ASC, `ch`.`name` ASC";
        $q = $db->query($query) or fail($db->error);

        $checks = [];
        while ($a = $q->fetch_array()) {
            $checks[] = [
                "id" => $a["id"],
                "enabled" => $a["enabled"],
                "name" => $a["name"],
                "type" => $a["type"],
                "interval" => $a["interval"],
                "hostname" => $a["hostname"],
                "alerts" => $a["alerts"],
                "chart_name" => $a["chart_name"],
                "chart_id" => $a["chart_id"]
            ];
        }

        return $this->renderTemplate("checks/group_detail.html", [
            "group" => $group,
            "checks" => $checks,
        ]);
    }

    public function chart_data(int $id, int $chart_id) {
        $this->requireAction("checks_read");

        $granularity = "daily";
        if (isset($_REQUEST["granularity"]) && in_array($_REQUEST["granularity"], ["daily", "weekly", "monthly", "yearly"])) {
            $granularity = $_REQUEST["granularity"];
        }

        $interval = NULL;
        switch ($granularity) {
            case "daily":
                $interval = "1 DAY";
                $seconds = 5*60;
                break;

            case "weekly":
                $interval = "1 WEEK";
                $seconds = 15*60;
                break;

            case "monthly":
                $interval = "1 MONTH";
                $seconds = 2*3600;
                break;

            case "yearly":
                $interval = "1 YEAR";
                $seconds = 86400;
                break;
        }

        $db = connect();

        $readings = [];

        $q = $db->query("SELECT `check_type_id` FROM `check_charts` WHERE `id` = ".escape($db, $chart_id)) or fail($db->error);
        $a = $q->fetch_array();
        if (!$a) {
            throw new EntityNotFound("Chart was not found.");
        }

        $type_id = $a["check_type_id"];

        $q = $db->query("SELECT
                    `r`.`id`,
                    `r`.`name`,
                    `r`.`data_type`,
                    `r`.`precision`,
                    `r`.`type`,
                    `r`.`compute`,
                    IF(`cr`.`chart_id` IS NULL, 0, 1) AS `used`,
                    COALESCE(`cr`.`label`, `r`.`label`, `r`.`name`) AS `label`,
                    `cr`.`color` AS `color`,
                    `cr`.`stack` AS `stack`,
                    `cr`.`type` AS `line_type`
                FROM `readings` `r`
                LEFT JOIN `check_chart_readings` `cr` ON (`r`.`id` = `cr`.`reading_id` AND `cr`.`chart_id` = ".escape($db, $chart_id).")
                WHERE `r`.`check_type_id` = ".escape($db, $type_id)) or fail($db->error);

        $used = [];
        while ($a = $q->fetch_array()) {
            $readings[(int)$a["id"]] = [
                "id" => (int)$a["id"],
                "name" => $a["name"],
                "data_type" => $a["data_type"],
                "precision" => (int)$a["precision"],
                "type" => $a["type"],
                "compute" => $a["compute"],
                "used" => $a["used"],
                "label" => $a["label"],
                "color" => $a["color"],
                "stack" => $a["stack"] == "1",
                "line_type" => $a["line_type"]
            ];

            if ($a["used"] == "1") {
                $used[] = (int)$a["id"];
            }
        }

        $series = [];

        $now = ceil(time() / $seconds) * $seconds;

        if (!empty($readings)) {
            $query = "SELECT
                    `v`.`reading_id`,
                    UNIX_TIMESTAMP(`v`.`datetime`) AS `timestamp`,
                    `v`.`value`
                    FROM `readings_".$granularity."` `v`
                    WHERE
                        `v`.`check_id` = ".escape($db, $id)."
                        AND `v`.`reading_id` IN (".implode(",", array_map(function($r) { return $r["id"]; }, $readings)).")
                        AND `v`.`datetime` BETWEEN DATE_ADD(FROM_UNIXTIME(".$now."), INTERVAL -".$interval.") AND FROM_UNIXTIME(".$now.")
                    ORDER BY
                        `check_id` ASC,
                        `reading_id` ASC,
                        `datetime` ASC";
            $q = $db->query($query) or fail($db->error);

            $last_reading_values = [];

            while ($a = $q->fetch_array()) {
                $optimized_timestamp = floor($a["timestamp"] / $seconds) * $seconds;

                if (!isset($series[(int)$optimized_timestamp])) {
                    $series[(int)$optimized_timestamp] = [];
                }

                $value = (float)$a["value"];
                $reading = $readings[$a["reading_id"]];

                if (in_array($reading["type"], ["COUNTER", "DERIVE", "ABSOLUTE"])) {
                    if ($reading["type"] != "ABSOLUTE") {
                        if (!isset($last_reading_values[$a["reading_id"]])) {
                            $last_reading_values[$a["reading_id"]] = $value;
                            continue;
                        }

                        $last = $last_reading_values[$a["reading_id"]];
                        $value = ($value - $last);

                        if ($reading["type"] == "DERIVE") {
                            if ($value < 0) {
                                $value = (float)$a["value"];
                            }
                        }
                    }

                    $value /= $seconds;

                    $last_reading_values[$a["reading_id"]] = (float)$a["value"];;
                }

                if (isset($series[$optimized_timestamp][(int)$a["reading_id"]])) {
                    $series[$optimized_timestamp][(int)$a["reading_id"]] = ($series[$optimized_timestamp][(int)$a["reading_id"]] + $value) / 2;
                } else {
                    $series[$optimized_timestamp][(int)$a["reading_id"]] = $value;
                }
            }

            $q = $db->query("SELECT UNIX_TIMESTAMP(DATE_ADD(FROM_UNIXTIME(".$now."), INTERVAL -".$interval.")) AS `from`") or fail($db->error);
            $a = $q->fetch_array();

            $from = floor($a["from"] / $seconds) * $seconds;

            // Fill in missing readings
            $compute = [];
            foreach ($readings as $reading) {
                for ($t = $from; $t <= $now; $t += $seconds) {
                    if (!isset($series[$t])) {
                        $series[$t] = [];
                    }

                    if (!isset($series[$t][(int)$reading["id"]])) {
                        $series[$t][(int)$reading["id"]] = NULL;
                    }
                }

                if ($reading["type"] == "COMPUTE") {
                    $compute[] = $reading;
                }
            }

            // Count computed readings.
            foreach ($compute as $reading) {
                for ($t = $from; $t <= $now; $t += $seconds) {
                    $variables = [];
                    foreach ($readings as $r) {
                        $variables[$r["name"]] = $series[$t][(int)$r["id"]];
                    }

                    $series[$t][(int)$reading["id"]] = $this->compute($reading["compute"], $variables);
                }
            }

            // Sort series by time (missing times added as NULL values are after existing ones).
            ksort($series);
        }

        $series_out = [];

        $statistics = [];

        foreach ($series as $time => $values) {
            foreach ($values as $reading => $value) {
                if (!isset($statistics[$reading])) {
                    $statistics[$reading] = [
                        "cur" => $value,
                        "min" => $value,
                        "max" => $value,
                        "avg" => (!is_null($value))?$value:0,
                        "cnt" => (!is_null($value))?1:0
                    ];
                } elseif (!is_null($value)) {
                    $statistics[$reading]["cur"] = $value;
                    $statistics[$reading]["min"] = min($statistics[$reading]["min"], $value);
                    $statistics[$reading]["max"] = max($statistics[$reading]["max"], $value);
                    ++$statistics[$reading]["cnt"];
                    $statistics[$reading]["avg"] += $value;
                }

                if (!in_array($reading, $used)) {
                    continue;
                }

                if (!isset($series_out[$reading])) {
                    $series_out[$reading] = [];
                }

                $series_out[$reading][] = [$time, $value];
            }
        }

        foreach ($statistics as &$stat) {
            if ($stat["cnt"] > 0) {
                $stat["avg"] = $stat["avg"] / $stat["cnt"];
            } else {
                $stat["avg"] = NULL;
            }
        }

        return json_encode([
            "readings" => $readings,
            "series" => $series_out,
            "statistics" => $statistics
        ]);
    }

    protected function compute($rpn, $variables) {
        $ops = explode(",", $rpn);
        $stack = [];
        $debug = false;

        if ($debug) {
            echo "Compute ".implode(",", $ops)." with ".var_export($variables, true)."<br />";
        }

        foreach ($ops as $op) {
            $op = trim($op);

            switch ($op) {
                case "LT":
                    $a2 = array_pop($stack);
                    $a1 = array_pop($stack);
                    if (!is_null($a1) && !is_null($a2)) {
                        if ($a1 < $a2) {
                            array_push($stack, 1);
                        } else {
                            array_push($stack, 0);
                        }
                    } else {
                        array_push($stack, NULL);
                    }
                    break;

                case "LE":
                    $a2 = array_pop($stack);
                    $a1 = array_pop($stack);
                    if (!is_null($a1) && !is_null($a2)) {
                        if ($a1 <= $a2) {
                            array_push($stack, 1);
                        } else {
                            array_push($stack, 0);
                        }
                    } else {
                        array_push($stack, NULL);
                    }
                    break;

                case "GT":
                    $a2 = array_pop($stack);
                    $a1 = array_pop($stack);
                    if (!is_null($a1) && !is_null($a2)) {
                        if ($a1 > $a2) {
                            array_push($stack, 1);
                        } else {
                            array_push($stack, 0);
                        }
                    } else {
                        array_push($stack, NULL);
                    }
                    break;

                case "GE":
                    $a2 = array_pop($stack);
                    $a1 = array_pop($stack);
                    if (!is_null($a1) && !is_null($a2)) {
                        if ($a1 >= $a2) {
                            array_push($stack, 1);
                        } else {
                            array_push($stack, 0);
                        }
                    } else {
                        array_push($stack, NULL);
                    }
                    break;

                case "EQ":
                    $a1 = array_pop($stack);
                    $a2 = array_pop($stack);
                    if (!is_null($a1) && !is_null($a2)) {
                        if ($a1 == $a2) {
                            array_push($stack, 1);
                        } else {
                            array_push($stack, 0);
                        }
                    } else {
                        array_push($stack, NULL);
                    }
                    break;

                case "NE":
                    $a1 = array_pop($stack);
                    $a2 = array_pop($stack);
                    if (!is_null($a1) && !is_null($a2)) {
                        if ($a1 != $a2) {
                            array_push($stack, 1);
                        } else {
                            array_push($stack, 0);
                        }
                    } else {
                        array_push($stack, NULL);
                    }
                    break;

                case "UN":
                case "ISINF":
                    $a = array_pop($stack);
                    if (is_null($a)) {
                        array_push($stack, 1);
                    } else {
                        array_push($stack, 0);
                    }
                    break;

                case "IF":
                    $a = array_pop($stack);
                    $b = array_pop($stack);
                    $c = array_pop($stack);

                    if ($c == 0) {
                        array_push($stack, $a);
                    } else {
                        array_push($stack, $b);
                    }
                    break;

                case "MIN":
                    $b = array_pop($stack);
                    $a = array_pop($stack);

                    if (is_null($a) || is_null($b)) {
                        array_push($stack, NULL);
                    } else {
                        array_push($stack, min($a, $b));
                    }
                    break;

                case "MAX":
                    $b = array_pop($stack);
                    $a = array_pop($stack);

                    if (is_null($a) || is_null($b)) {
                        array_push($stack, NULL);
                    } else {
                        array_push($stack, max($a, $b));
                    }
                    break;

                case "MINNAN":
                    $b = array_pop($stack);
                    $a = array_pop($stack);

                    if (is_null($a)) {
                        array_push($stack, $b);
                    } elseif (is_null($b)) {
                        array_push($stack, $a);
                    } else {
                        array_push($stack, min($a, $b));
                    }
                    break;

                case "MAXNAN":
                    $b = array_pop($stack);
                    $a = array_pop($stack);

                    if (is_null($a)) {
                        array_push($stack, $b);
                    } elseif (is_null($b)) {
                        array_push($stack, $a);
                    } else {
                        array_push($stack, max($a, $b));
                    }
                    break;

                case "LIMIT":
                    $max = array_pop($stack);
                    $min = array_pop($stack);
                    $value = array_pop($stack);

                    if ($value >= $min && $value <= $max) {
                        array_push($stack, $value);
                    } else {
                        array_push($stack, NULL);
                    }
                    break;

                case "+":
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    if (is_null($a) || is_null($b)) {
                        array_push($stack, NULL);
                    } else {
                        array_push($stack, $a + $b);
                    }
                    break;

                case "-":
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    if (is_null($a) || is_null($b)) {
                        array_push($stack, NULL);
                    } else {
                        array_push($stack, $a - $b);
                    }
                    break;

                case "*":
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    if (is_null($a) || is_null($b)) {
                        array_push($stack, NULL);
                    } else {
                        array_push($stack, $a * $b);
                    }
                    break;

                case "/":
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    if (is_null($a) || is_null($b)) {
                        array_push($stack, NULL);
                    } else {
                        array_push($stack, $a / $b);
                    }
                    break;

                case "%":
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    if (is_null($a) || is_null($b)) {
                        array_push($stack, NULL);
                    } else {
                        array_push($stack, $a % $b);
                    }
                    break;

                case "ADDNAN":
                    $b = array_pop($stack);
                    $a = array_pop($stack);

                    if (is_null($b)) {
                        $b = 0;
                    }

                    if (is_null($a)) {
                        $a = 0;
                    }

                    array_push($stack, $a + $b);
                    break;

                case "SIN":
                    $a = array_pop($stack);
                    array_push($stack, sin($a));
                    break;

                case "COS":
                    $a = array_pop($stack);
                    array_push($stack, cos($a));
                    break;

                case "LOG":
                    $a = array_pop($stack);
                    array_push($stack, log($a));
                    break;

                case "EXP":
                    $a = array_pop($stack);
                    array_push($stack, exp($a));
                    break;

                case "SQRT":
                    $a = array_pop($stack);
                    array_push($stack, sqrt($a));
                    break;

                case "ATAN":
                    $a = array_pop($stack);
                    array_push($stack, atan($a));
                    break;

                case "ATAN2":
                    $x = array_pop($stack);
                    $y = array_pop($stack);
                    array_push($stack, atan2($x, $y));
                    break;

                case "FLOOR":
                    $a = array_pop($stack);
                    array_push($stack, floor($a));
                    break;

                case "CEIL":
                    $a = array_pop($stack);
                    array_push($stack, ceil($a));
                    break;

                case "DEG2RAD":
                    $a = array_pop($stack);
                    array_push($stack, deg2rad($a));
                    break;

                case "RAD2DEG":
                    $a = array_pop($stack);
                    array_push($stack, rad2deg($a));
                    break;

                case "ABS":
                    $a = array_pop($stack);
                    array_push($stack, abs($a));
                    break;

                case "SORT":
                    $num = array_pop($stack);
                    $arr = [];
                    while ($num > 0) {
                        $a = array_pop($stack);
                        $arr[] = $a;
                        --$num;
                    }

                    sort($arr);

                    foreach ($arr as $a) {
                        array_push($stack, $a);
                    }
                    break;

                case "REV":
                    $num = array_pop($stack);
                    $arr = [];
                    while ($num > 0) {
                        $a = array_pop($stack);
                        $arr[] = $a;
                        --$num;
                    }

                    array_reverse($arr);

                    foreach ($arr as $a) {
                        array_push($stack, $a);
                    }
                    break;

                case "AVG":
                    $num = array_pop($num);

                    $sum = 0;
                    $cnt = 0;

                    while ($num > 0) {
                        $a = array_pop($stack);
                        if (!is_null($a)) {
                            $sum += $a;
                            ++$cnt;
                        }

                        --$num;
                    }

                    if ($cnt > 0) {
                        array_push($stack, $sum / $cnt);
                    } else {
                        array_push($stack, NULL);
                    }
                    break;

                case "MEDIAN":
                    $num = array_pop($num);
                    $arr = [];

                    while ($num > 0) {
                        $a = array_pop($stack);
                        if (!is_null($a)) {
                            $arr[] = $a;
                        }

                        --$num;
                    }

                    array_push($stack, $arr[floor(count($arr) / 2)]);
                    break;

                case "UNKN":
                    array_push($stack, NULL);
                    break;

                case "INF":
                    array_push($stack, inf);
                    break;

                case "NEGINF":
                    array_push($stack, -inf);
                    break;

                case "DUP":
                    $a = array_pop($stack);
                    array_push($stack, $a);
                    array_push($stack, $a);
                    break;

                case "POP":
                    array_pop($stack);
                    break;

                case "EXC":
                    $a = array_pop($stack);
                    $b = array_pop($stack);

                    array_push($stack, $a);
                    array_push($stack, $b);
                    break;

                case "DEPTH":
                    array_push($stack, count($stack));
                    break;

                case "COPY":
                    $num = array_pop($stack);

                    $arr = [];
                    while ($num > 0) {
                        $arr[] = array_pop($stack);
                        --$num;
                    }

                    array_reverse($arr);

                    foreach ($arr as $a) {
                        array_push($stack, $a);
                    }
                    foreach ($arr as $a) {
                        array_push($stack, $a);
                    }
                    break;

                case "INDEX":
                    $index = array_pop($stack);
                    array_push($stack, $stack[$index] ?? NULL);
                    break;

                case "ROLL":
                    $m = array_pop($stack);
                    $n = array_pop($stack);

                    $arr = [];
                    $num = $n;
                    while ($num > 0) {
                        $arr[] = array_pop($stack);
                        --$num;
                    }

                    for ($i = $m; $i < $n + $m; ++$i) {
                        array_push($stack, $arr[$i % count($arr)]);
                    }
                    break;

                default:
                    if (is_numeric($op)) {
                        array_push($stack, $op);
                    } else {
                        array_push($stack, $variables[$op] ?? NULL);
                    }
                    break;
            }

            if ($debug) {
                echo "Stack = ".var_export($stack, true)."<br />";
            }
        }

        $resp = array_pop($stack);

        if ($debug) {
            echo "Result = ".$resp."<br />";
        }

        return $resp;
    }

    public function add() {
        $this->requireAction("checks_write");

        $db = connect();

        $params = $this->combineParams();

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $interval = parse_duration($_REQUEST["interval"]);

            $group_id = $this->resolveGroup($db, $_REQUEST["group"]);

            $db->query("INSERT INTO `checks` (
                    `server_id`,
                    `interval`,
                    `last_check`,
                    `name`,
                    `type_id`,
                    `params`,
                    `enabled`,
                    `group_id`
                ) VALUES (
                    ".escape($db, $_POST["server"]).",
                    ".escape($db, $interval).",
                    NULL,
                    ".escape($db, $_POST["name"]).",
                    ".escape($db, $_POST["type"]).",
                    ".escape($db, json_encode($params)).",
                    1,
                    ".escape($db, $group_id)."
                )") or fail($db->error);
            $db->commit();

            Message::create(Message::SUCCESS, "Check has been created.");

            header("Location: ".twig_url_for(["ChecksController", "index"]));
            exit;
        }

        return $this->renderTemplate("checks/add.html", [
            "servers" => $this->loadServers($db),
            "types" => $this->loadTypes($db),
            "groups" => $this->loadGroups($db),
            "options" => $this->loadOptions($db)
        ]);
    }

    public function edit($id) {
        $this->requireAction("checks_write");

        $db = connect();

        $q = $db->query("SELECT
                `ch`.`id`,
                `ch`.`server_id`,
                `ch`.`interval`,
                `ch`.`last_check`,
                `ch`.`name`,
                `ch`.`type_id`,
                `ch`.`params`,
                `ch`.`enabled`,
                `g`.`name` AS `group`,
                `s`.`hostname`
            FROM `checks` `ch`
            LEFT JOIN `check_groups` `g` ON (`ch`.`group_id` = `g`.`id`)
            JOIN `servers` `s` ON (`ch`.`server_id` = `s`.`id`)
            WHERE
                `ch`.`id` = ".escape($db, $id)) or fail($db->error);
        $a = $q->fetch_array();

        if (!$a) {
            throw new EntityNotFound("Check was not found.");
        }

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $params = $this->combineParams();
            $interval = parse_duration($_REQUEST["interval"]);

            $group_id = $this->resolveGroup($db, $_REQUEST["group"]);

            $db->query("UPDATE `checks`
                SET
                    `server_id` = ".escape($db, $_POST["server"]).",
                    `interval` = ".escape($db, $interval).",
                    `name` = ".escape($db, $_POST["name"]).",
                    `type_id` = ".escape($db, $_POST["type"]).",
                    `params` = ".escape($db, json_encode($params)).",
                    `group_id` = ".escape($db, $group_id)."
                WHERE `id` = ".escape($db, $id));
            $db->commit();

            Message::create(Message::SUCCESS, "Check has been modified.");

            if (($_REQUEST["back"] ?? "") == "detail") {
                header("Location: ".twig_url_for(["ChecksController", "detail"], ["id" => $id]));
            } else {
                header("Location: ".twig_url_for(["ChecksController", "index"]));
            }
            exit;
        }

        $params = json_decode($a["params"]);
        $a["params"] = [];
        foreach ($params as $key=>$val) {
            $a["params"][$key] = $val;
        }

        return $this->renderTemplate("checks/edit.html", [
            "check" => $a,
            "servers" => $this->loadServers($db),
            "types" => $this->loadTypes($db),
            "groups" => $this->loadGroups($db),
            "options" => $this->loadOptions($db)
        ]);
    }

    public function toggle($id) {
        $this->requireAction("checks_suspend");

        $db = connect();

        $db->query("UPDATE `checks` SET `enabled` = IF(`enabled`, 0, 1) WHERE `id` = ".escape($db, $id));
        $db->commit();

        if (($_REQUEST["back"] ?? "") == "detail") {
            header("Location: ".twig_url_for(["ChecksController", "detail"], ["id" => $id]));
        } else {
            header("Location: ".twig_url_for(["ChecksController", "index"]));
        }
        exit;
    }

    public function remove($id) {
        $this->requireAction("checks_write");

        $db = connect();

        $db->query("DELETE FROM `checks` WHERE `id` = ".escape($db, $id));
        $db->commit();

        Message::create(Message::SUCCESS, "Check has been removed.");

        if (($_REQUEST["back"] ?? "") == "detail") {
            header("Location: ".twig_url_for(["ChecksController", "detail"], ["id" => $id]));
        } else {
            header("Location: ".twig_url_for(["ChecksController", "index"]));
        }
        exit;
    }

    public function list($hostname) {
        $db = connect();

        $q = $db->query("SELECT `id` FROM `servers` `s` WHERE `s`.`hostname` = ".escape($db, $hostname)) or fail($db->error);
        $server = $q->fetch_assoc();

        if (!$server) {
            header("Content-Type: text/json");
            return json_encode([]);
        }

        $server_ids = [escape($db, $server["id"])];

        $q = $db->query("SELECT `server_id` FROM `snmp_devices` WHERE `agent_id` = ".escape($db, $server["id"])) or fail($db->error);
        while ($a = $q->fetch_assoc()) {
            $server_ids[] = escape($db, $a["server_id"]);
        }

        // The check is selected when previous listing was before `interval` seconds,
        // and if the results from check has arrived within the given interval.
        // Also, there is a 15 minute safe period, when the check is rescheduled, to avoid
        // stucking check when results did not arrived within that interval.
        // So possible flooding of agent could still occur, but it is much less probable.

        // TODO: When the safe interval is hitted, alert should be raised.
        $q = $db->query("SELECT
                `ch`.`id`,
                `ch`.`server_id`,
                `ch`.`name`,
                `ch`.`last_check`,
                `t`.`identifier` AS `type`,
                `ch`.`params`,
                `d`.`hostname` AS `snmp_hostname`,
                `d`.`port` AS `snmp_port`,
                `d`.`version` AS `snmp_version`,
                `d`.`community` AS `snmp_community`,
                (DATE_ADD(`ch`.`last_listed`, INTERVAL GREATEST(LEAST(900,`interval`), 5*`interval`) SECOND) <= NOW()) AS `is_safe_period`
            FROM `checks` `ch`
            JOIN `servers` `s` ON (`s`.`id` = `ch`.`server_id`)
            LEFT JOIN `snmp_devices` `d` ON (`d`.`server_id` = `s`.`id`)
            JOIN `check_types` `t` ON (`t`.`id` = `ch`.`type_id`)
            WHERE `s`.`id` IN (".implode(",", $server_ids).")
                AND `ch`.`enabled` = 1
                AND (
                    `ch`.`last_listed` IS NULL OR (
                        (
                            `ch`.`last_listed` <= `ch`.`last_check`
                            OR DATE_ADD(`ch`.`last_listed`, INTERVAL GREATEST(LEAST(900, `interval`), 5*`interval`) SECOND) <= NOW()
                        ) AND DATE_ADD(`ch`.`last_listed`, INTERVAL (`interval` - 60) SECOND) <= NOW()
                    )
                )
            ") or fail($db->error);

        $out = array();

        $ids = [];
        while ($a = $q->fetch_assoc()) {
            $check = [
                "id" => $a["id"],
                "name" => $a["name"],
                "type" => $a["type"],
                "params" => json_decode($a["params"]),
            ];

            $ids[] = escape($db, $a["id"]);

            if (!is_null($a["snmp_hostname"])) {
                $check["snmp"] = [
                    "hostname" => $a["snmp_hostname"],
                    "port" => $a["snmp_port"],
                    "version" => $a["snmp_version"],
                    "community" => $a["snmp_community"]
                ];
            }

            if ($a["is_safe_period"]) {
                $q = $db->query("SELECT `id`, `type` FROM `alerts` WHERE `check_id` = ".escape($db, $a["id"])." AND `active` = 1 AND `type` = 'check_stalled'") or fail($db->error);

                if (!$q->fetch_assoc()) {
                    $db->query("INSERT INTO `alerts` (`server_id`, `check_id`, `timestamp`, `type`, `data`, `active`) VALUES (
                        ".escape($db, $a["server_id"]).",
                        ".escape($db, $a["id"]).",
                        NOW(),
                        'check_stalled',
                        ".escape($db, json_encode(["last_check" => $a["last_check"]])).",
                        1
                    )") or fail($db->error);
                }
            } else {
                // Dismiss the alert if check updated in the meantime.
                $db->query("UPDATE `alerts` SET `active` = 0, `sent` = 0, `until` = NOW() WHERE `check_id` = ".escape($db, $a["id"])." AND `active` = 1 AND `type` = 'check_stalled'") or fail($db->error);
            }

            $out[] = $check;
        }

        // Do not list the check multiple times if the check was not completed in the 60s query period. Only list
        // newly expired checks. This allows checks to run more than 60 seconds, without flooding the agent
        // with repeated requests for the same checks.
        if (!empty($ids)) {
            $db->query("UPDATE `checks` SET `last_listed` = NOW() WHERE `id` IN (".implode(",", $ids).")") or fail($db->error);
            $db->commit();
        }

        header("Content-Type: text/json");
        return json_encode($out);
    }

    public function put() {
        $db = connect();

        $reading_mapping = [];

        $q = $db->query("SELECT `id`, `name`, `check_type_id` FROM `readings`");
        while ($a = $q->fetch_array()) {
            if (!isset($reading_mapping[$a["check_type_id"]])) {
                $reading_mapping[$a["check_type_id"]] = [];
            }

            $reading_mapping[$a["check_type_id"]][$a["name"]] = $a["id"];
        }

        $data = json_decode(file_get_contents("php://input"));
        foreach ($data as $check) {
            $id = $check->id;
            $alerts = $check->alerts;
            $readings = $check->readings ?? new stdClass();

            $q = $db->query("SELECT `server_id`, `type_id` FROM `checks` WHERE `id` = ".escape($db, $id)) or fail($db->error);
            $check = $q->fetch_array();

            if (!$check) {
                continue;
            }

            $server_id = $check["server_id"];

            $q = $db->query("SELECT `id`, `type` FROM `alerts` WHERE `check_id` = ".escape($db, $id)." AND `active` = 1") or fail($db->error);

            $existing_alerts = [];
            $dismiss_alerts = [];
            $new_alerts = [];

            while ($a = $q->fetch_array()) {
                $found = false;
                foreach ($alerts as $num => $alert) {
                    if ($a["type"] == $alert->type) {
                        $found = true;
                        unset($alerts[$num]);
                        break;
                    }
                }

                if (!$found) {
                    $dismiss_alerts[] = $a["id"];
                }
            }

            foreach ($alerts as $alert) {
                $new_alerts[] = "(".escape($db, $server_id).", ".escape($db, $id).", NOW(), ".escape($db, $alert->type).", ".escape($db, json_encode($alert->data)).", 1)";
            }

            if (!empty($new_alerts)) {
                $db->query("INSERT INTO `alerts` (`server_id`, `check_id`, `timestamp`, `type`, `data`, `active`) VALUES ".implode(",", $new_alerts)) or fail($db->error);
            }

            if (!empty($dismiss_alerts)) {
                $db->query("UPDATE `alerts` SET `active` = 0, `sent` = 0, `until` = NOW() WHERE `id` IN (".implode(",", $dismiss_alerts).")") or fail($db->error);
            }

            $db->query("UPDATE `checks` SET `last_check` = NOW() WHERE `id` = ".escape($db, $id)) or fail($db->error);

            // Store readings
            $to_insert = [];
            foreach ($readings as $key=>$val) {
                if (!isset($reading_mapping[$check["type_id"]][$key])) {
                    $db->query("INSERT INTO `readings` (`check_type_id`, `name`) VALUES (".escape($db, $check["type_id"]).", ".escape($db, $key).")");
                    $reading_mapping[$check["type_id"]][$key] = $db->insert_id;
                }

                $to_insert[] = "(".escape($db, $id).", ".$reading_mapping[$check["type_id"]][$key].", NOW(), ".escape($db, $val).")";
            }

            if (!empty($to_insert)) {
                $db->query("INSERT INTO `readings_daily` (`check_id`, `reading_id`, `datetime`, `value`) VALUES ".implode(",", $to_insert));
            }
        }

        $db->commit();

        return json_encode(["status" => "OK"]);
    }

    protected function loadServers(mysqli $db) {
        $servers = [];
        $q = $db->query("SELECT `id`, `hostname` FROM `servers` ORDER BY `hostname` ASC");
        while ($a = $q->fetch_array()) {
            $servers[] = [
                "id" => $a["id"],
                "hostname" => $a["hostname"]
            ];
        }

        return $servers;
    }

    protected function loadTypes(mysqli $db) {
        $types = [];
        $q = $db->query("SELECT `id`, `name` FROM `check_types` ORDER BY `name` ASC");
        while ($a = $q->fetch_array()) {
            $types[] = [
                "id" => $a["id"],
                "name" => $a["name"]
            ];
        }

        return $types;
    }

    protected function combineParams() {
        $params = [];

        if (isset($_REQUEST["params"]) && isset($_REQUEST["values"])) {
            for ($i = 0; $i < min(count($_REQUEST["params"]), count($_REQUEST["values"])); ++$i) {
                if (!empty($_REQUEST["params"][$i]) && !empty($_REQUEST["values"][$i])) {
                    $params[$_REQUEST["params"][$i]] = $_REQUEST["values"][$i];
                }
            }

            $_REQUEST["params"] = $params;
            unset($_REQUEST["values"]);
        }

        return $params;
    }

    protected function selectChart(mysqli $db, int $check_type_id) {
        $chart = NULL;
        $readings = [];

        if (isset($_REQUEST["chart"])) {
            $q = $db->query("SELECT `id`, `name` FROM `check_charts` WHERE `id` = ".escape($db, $_REQUEST["chart"]));
            if ($a = $q->fetch_array()) {
                $chart = $a;
            }
        }

        if (is_null($chart)) {
            $q = $db->query("SELECT `id`, `name` FROM `check_charts` WHERE `check_type_id` = ".escape($db, $check_type_id)." ORDER BY `name` ASC LIMIT 1");
            if ($a = $q->fetch_array()) {
                $chart = $a;
            }
        }

        // Select readings for selected chart.
        $q = $db->query("SELECT `reading_id` FROM `check_chart_readings` WHERE `chart_id` = ".escape($db, $chart["id"]));
        while ($a = $q->fetch_array()) {
            $readings[] = $a["reading_id"];
        }

        return [$chart, $readings];
    }

    protected function loadAllCharts(mysqli $db, int $check_type_id) {
        $charts = [];

        $q = $db->query("SELECT `id`, `name` FROM `check_charts` WHERE `check_type_id` = ".escape($db, $check_type_id));
        while ($a = $q->fetch_array()) {
            $charts[] = [
                "id" => $a["id"],
                "name" => $a["name"]
            ];
        }

        return $charts;
    }

    protected function loadGroups(mysqli $db) {
        $q = $db->query("SELECT `id`, `name` FROM `check_groups` ORDER BY `name` ASC");
        $groups = [];
        while ($a = $q->fetch_array()) {
            $groups[] = [
                "id" => $a["id"],
                "name" => $a["name"]
            ];
        }
        return $groups;
    }

    protected function resolveGroup(mysqli $db, $group) {
        if (is_null($group)) {
            return NULL;
        }

        $group = trim($group);
        if (empty($group)) {
            return NULL;
        }

        $q = $db->query("SELECT `id` FROM `check_groups` WHERE `name` = ".escape($db, $group));
        if ($a = $q->fetch_array()) {
            return $a["id"];
        } else {
            $db->query("INSERT INTO `check_groups` (`name`) VALUES (".escape($db, $group).")");
            $id = $db->insert_id;
            $db->commit();

            return $id;
        }
    }

    protected function loadOptions(mysqli $db) {
        $q = $db->query("SELECT `check_type_id`, `option_name` FROM `check_type_options` ORDER BY `check_type_id` ASC, `option_name` ASC");

        $options = [];

        while ($a = $q->fetch_array()) {
            if (!isset($options[$a["check_type_id"]])) {
                $options[$a["check_type_id"]] = [];
            }

            $options[$a["check_type_id"]][] = $a["option_name"];
        }

        return $options;
    }
}
