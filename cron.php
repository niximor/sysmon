<?php

require_once "lib/common.php";

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use Fabiang\Xmpp\Options;
use Fabiang\Xmpp\Client;
use Fabiang\Xmpp\Protocol\Message;

try {
    $db = connect();

    $q = $db->query("SELECT `s`.`id`, `s`.`last_check` FROM `servers` `s` WHERE `last_check` < DATE_ADD(NOW(), INTERVAL -5 MINUTE)") or fail($db->error);

    $dead_hosts = [];

    while ($a = $q->fetch_array()) {
        // Test if host is not already in failed state.
        $qs = $db->query("SELECT `id` FROM `alerts` WHERE `server_id` = '".$a["id"]."' AND `type` = 'dead' AND `active` = 1") or fail($db->error);
        if (!$qs->fetch_array()) {
            send_alert($db, $a["id"], "dead", ["last_check" => $a["last_check"]], true);
        }

        $dead_hosts[] = $a["id"];
    }

    // Dismiss live hosts dead status.
    $where = ["`type` = 'dead'", "`active` = 1"];
    if (!empty($dead_hosts)) {
        $where[] = "`server_id` NOT IN (".implode(",", $dead_hosts).")";
    }

    $db->query("UPDATE `alerts` SET `active` = 0, `until` = NOW(), `sent` = 0 WHERE ".implode(" AND ", $where)) or fail($db->error);

    $db->commit();

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

        $type = "ALERT";
        if ($a["active"] == 0) {
            $type = "RECOVER";
        }

        $msg = new Message();
        $msg->setMessage(
            "[".$type."]\n".
            "Host: ".$a["hostname"]."\n".
            "Alert: ".format_alert($a["type"], json_decode($a["data"]), $a["until"]));
        $msg->setTo("monitoring@gcm.cz");
        
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
