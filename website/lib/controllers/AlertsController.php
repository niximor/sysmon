<?php

use Fabiang\Xmpp\Options;
use Fabiang\Xmpp\Client;
use Fabiang\Xmpp\Protocol\Message;

class AlertMessage {
    const TYPE_ALERT = "ALERT";
    const TYPE_RECOVER = "RECOVER";

    const SOURCE_HOST = "host";
    const SOURCE_CHECK = "check";
    const SOURCE_STAMP = "stamp";

    public $type;

    public $sources = [];

    public $message;

    public function setType($type) {
        $this->type = $type;
    }

    public function addSource($type, $name) {
        $this->sources[$type] = $name;
    }

    public function setMessage($message) {
        $this->message = $message;
    }
}

class AlertsController implements CronInterface {
    protected static $twig_env;
    protected static $template;

    public function __construct() {
    }

    public function send_xmpp($db, $messages) {
        // No messages, do not need to proceed.
        if (empty($messages)) {
            return;
        }

        $recipients = [];

        $q = $db->query("SELECT `params` FROM `notification_settings` WHERE `type` = 'xmpp' AND `enabled` = 1");
        while ($a = $q->fetch_assoc()) {
            $params = json_decode($a["params"]);

            if (is_object($params) && isset($params->jid)) {
                $recipients[] = $params->jid;
            }
        }

        // No recipients, do not need to proceed.
        if (empty($recipients)) {
            return;
        }

        if (is_null(self::$twig_env)) {
            self::$twig_env = new TwigEnv();
        }

        $template = self::$twig_env->twig->createTemplate($GLOBALS["config"]["xmpp-template"]);

        //$logger = new \Monolog\Logger("xmpp");
        //$logger->pushHandler(new \Monolog\Handler\StreamHandler("php://stdout", \Monolog\Logger::DEBUG));

        $options = new Options($GLOBALS["config"]["xmpp-host"]);
        $options->setUsername($GLOBALS["config"]["xmpp-user"])->setPassword($GLOBALS["config"]["xmpp-password"])->setTo($GLOBALS["config"]["xmpp-domain"])
            /*->setLogger($logger)*/;

        $client = new Client($options);
        $client->connect();

        foreach ($messages as $message) {
            $msg = new Message();

            $fmt_message = $template->render(["message" => $message]);
            $msg->setMessage($fmt_message);

            foreach ($recipients as $recipient) {
                $msg->setTo($recipient);

                $client->send($msg);
                usleep(200000); // Seems like XMPP delivers only one message, when multiple are sent during short period. This should fix it.
            }
        }

        $client->disconnect();
    }

    public function send_email($db, $messages) {

    }

    public function cron(mysqli $db) {
        // Send XMPP alerts.
        $q = $db->query("SELECT
            `a`.`id`,
            `a`.`server_id`,
            `a`.`check_id`,
            `a`.`stamp_id`,
            `a`.`timestamp`,
            `a`.`until`,
            `a`.`type`,
            `a`.`data`,
            `a`.`active`,
            `s`.`hostname`,
            `ch`.`name` AS `check`,
            `st`.`stamp` AS `stamp`
            FROM `alerts` `a`
            LEFT JOIN `servers` `s` ON (`a`.`server_id` = `s`.`id`)
            LEFT JOIN `checks` `ch` ON (`ch`.`id` = `a`.`check_id`)
            LEFT JOIN `stamps` `st` ON (`st`.`id` = `a`.`stamp_id`)
            WHERE `sent` = 0 AND `muted` = 0 OR (`resend_interval` IS NOT NULL AND (`last_sent` IS NULL OR DATE_ADD(`last_sent`, INTERVAL `resend_interval` SECOND) <= NOW()))");

        $messages = [];

        $handled = [];

        while ($a = $q->fetch_array()) {
            $alert = new Alert($a);

            $msg = new AlertMessage();

            $type = AlertMessage::TYPE_ALERT;
            if ($alert->active == 0) {
                $type = AlertMessage::TYPE_RECOVER;
            }
            $msg->setType($type);

            if ($alert->stamp) {
                $msg->addSource(AlertMessage::SOURCE_STAMP, $alert->stamp);
            }

            if ($alert->check) {
                $msg->addSource(AlertMessage::SOURCE_CHECK, $alert->check);
            }

            if ($alert->hostname) {
                $msg->addSource(AlertMessage::SOURCE_HOST, $alert->hostname);
            }

            $msg->setMessage($alert->getMessage());

            $messages[] = $msg;

            $handled[] = $a["id"];
        }

        $this->send_xmpp($db, $messages);
        $this->send_email($db, $messages);

        if (!empty($handled)) {
            $db->query("UPDATE `alerts` SET `sent` = 1, `last_sent` = NOW() WHERE `id` IN (".implode(",", $handled).")");
        }

        $db->commit();
    }
}
