<?php

ini_set("display_errors", true);
ini_set("display_startup_errors", true);

set_time_limit(0);

require_once "lib/common.php";

require_once "controllers/HostsController.php";
require_once "controllers/StampsController.php";
require_once "controllers/AlertsController.php";

require_once "models/Session.php";

$modules = [
    "HostsController", "StampsController", "AlertsController"
];

$db = connect();

try {
    foreach ($modules as $module) {
        $instance = new $module();
        $instance->cron($db);
    }

    Session::cleanup();
    Stamp::put("sysmon_cron");
} catch (Throwable $e) {
    $db->rollback();

    if (http_response_code() == 200) {
        http_response_code(500);
    }

    echo $e;
}
