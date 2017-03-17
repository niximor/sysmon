<?php

ini_set("display_errors", true);
ini_set("display_startup_errors", true);

error_reporting(E_ALL);

require_once "lib/common.php";

require_once "models/Stamp.php";

$db = connect();

$agg_week = 15*60; // Week aggregation is by 15 minutes
$agg_month = 2*60*60; // Month aggregation is by 2 hours
$agg_year = 24*60*60; // Year aggregation us by 1 day

$now = time();

// Week aggregation
$db->query("REPLACE INTO `readings_weekly` (`check_id`, `reading_id`, `datetime`, `value`) SELECT `check_id`, `reading_id`, FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(`datetime`) / ".$agg_week.") * ".$agg_week.") AS `agg_datetime`, AVG(`v`.`value`) AS `value` FROM `readings_daily` `v` WHERE `v`.`datetime` < FROM_UNIXTIME(FLOOR(".$now." / ".$agg_week.") * ".$agg_week.") GROUP BY `check_id`, `reading_id`, `agg_datetime`") or fail($db->error);
$db->commit();

// Month aggregation
$db->query("REPLACE INTO `readings_monthly` (`check_id`, `reading_id`, `datetime`, `value`) SELECT `check_id`, `reading_id`, FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(`datetime`) / ".$agg_month.") * ".$agg_month.") AS `agg_datetime`, AVG(`v`.`value`) AS `value` FROM `readings_weekly` `v` WHERE `v`.`datetime` < FROM_UNIXTIME(FLOOR(".$now." / ".$agg_month.") * ".$agg_month.") GROUP BY `check_id`, `reading_id`, `agg_datetime`") or fail($db->error);
$db->commit();

// Year aggregation
$db->query("REPLACE INTO `readings_yearly` (`check_id`, `reading_id`, `datetime`, `value`) SELECT `check_id`, `reading_id`, FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(`datetime`) / ".$agg_year.") * ".$agg_year.") AS `agg_datetime`, AVG(`v`.`value`) AS `value` FROM `readings_monthly` `v` WHERE `v`.`datetime` < FROM_UNIXTIME(FLOOR(".$now." / ".$agg_year.") * ".$agg_year.") GROUP BY `check_id`, `reading_id`, `agg_datetime`") or fail($db->error);
$db->commit();

$db->query("DELETE FROM `readings_daily` WHERE `datetime` < DATE_ADD(FROM_UNIXTIME(FLOOR(".$now." / ".$agg_week.") * ".$agg_week."), INTERVAL -1 DAY)") or fail($db->error);
$db->query("DELETE FROM `readings_weekly` WHERE `datetime` < DATE_ADD(FROM_UNIXTIME(FLOOR(".$now." / ".$agg_month.") * ".$agg_month."), INTERVAL -1 WEEK)");
$db->query("DELETE FROM `readings_monthly` WHERE `datetime` < DATE_ADD(FROM_UNIXTIME(FLOOR(".$now." / ".$agg_year.") * ".$agg_year."), INTERVAL -1 MONTH)");
$db->query("DELETE FROM `readings_yearly` WHERE `datetime` < DATE_ADD(FROM_UNIXTIME(".$now."), INTERVAL -1 YEAR)");
$db->commit();

Stamp::put("sysmon_aggregation");
