<?php

ini_set("display_errors", true);
ini_set("display_startup_errors", true);

set_time_limit(0);

require_once "lib/common.php";

require_once "controllers/HostsController.php";
require_once "controllers/StampsController.php";

require_once "models/Session.php";

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use Fabiang\Xmpp\Options;
use Fabiang\Xmpp\Client;
use Fabiang\Xmpp\Protocol\Message;

$modules = [
    "HostsController", "StampsController"
];

$db = connect();

try {
    foreach ($modules as $module) {
        $instance = new $module();
        $instance->cron($db);
    }

    Session::cleanup();

    // Send XMPP alerts.
    $q = $db->query("SELECT `a`.`id`, `a`.`until`, `a`.`type`, `a`.`data`, `s`.`hostname`, `a`.`active` FROM `alerts` `a` JOIN `servers` `s` ON (`a`.`server_id` = `s`.`id`) WHERE `sent` = 0");

    $first = true;
    $client = NULL;

    $handled = [];

    while ($a = $q->fetch_array()) {
        if ($first) {
            $logger = new Logger('xmpp');
            $logger->pushHandler(new StreamHandler('php://output', Logger::DEBUG));

            $options = new Options($config["xmpp-host"]);
            $options->setUsername($config["xmpp-user"])->setPassword($config["xmpp-password"])->setTo($config["xmpp-domain"]);

            $client = new Client($options);
            $client->connect();

            $first = false;
        }

        $alert = new Alert($a);

        $type = "ALERT";
        if ($alert->active == 0) {
            $type = "RECOVER";
        }

        $msg = new Message();
        $msg->setMessage(
            "[".$type."]\n".
            "Host: ".$alert->hostname."\n".
            "Alert: ".strip_tags($alert->getMessage()));
        $msg->setTo($config["xmpp-target"]);

        $client->send($msg);
        usleep(200000); // Seems like XMPP delivers only one message, when multiple are sent during short period. This should fix it.

        $handled[] = $a["id"];
    }

    if (!is_null($client)) {
        $client->disconnect();
    }

    if (!empty($handled)) {
        $db->query("UPDATE `alerts` SET `sent` = 1 WHERE `id` IN (".implode(",", $handled).")");
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();

    if (http_response_code() == 200) {
        http_response_code(500);
    }

    echo $e;
}
