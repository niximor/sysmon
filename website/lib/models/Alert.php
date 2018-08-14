<?php

require_once "util/TwigEnv.php";

class Alert {
    public $id;
    public $server_id;
    public $check_id;
    public $stamp_id;
    public $timestamp;
    public $until;
    public $type;
    public $data;
    public $active;
    public $sent;

    // Foreign properties
    public $hostname;
    public $check;
    public $stamp;

    protected static $templates;
    protected static $twig_env;

    public function __construct($data = NULL) {
        if (!is_null($data)) {
            $this->id = $data["id"] ?? NULL;
            $this->server_id = $data["server_id"] ?? NULL;
            $this->check_id = $data["check_id"] ?? NULL;
            $this->stamp_id = $data["stamp_id"] ?? NULL;
            $this->timestamp = (isset($data["timestamp"]))?DateTime::createFromFormat("Y-m-d G:i:s", $data["timestamp"]):NULL;
            $this->until = (isset($data["until"]))?DateTime::createFromFormat("Y-m-d G:i:s", $data["until"]):NULL;
            $this->type = $data["type"] ?? NULL;
            $this->data = (isset($data["data"]))?json_decode($data["data"]):NULL;
            $this->active = $data["active"] ?? NULL;
            $this->sent = $data["sent"] ?? NULL;
            $this->muted = $data["muted"] ?? NULL;

            // Foreign properties
            $this->hostname = $data["hostname"] ?? NULL;
            $this->check = $data["check"] ?? NULL;
            $this->stamp = $data["stamp"] ?? NULL;
        }
    }

    public function getMessage() {
        if (is_null(self::$twig_env)) {
            self::$twig_env = new TwigEnv();
        }

        if (!isset(self::$templates[$this->type])) {
            $db = connect();
            $q = $db->query("SELECT `template` FROM `alert_templates` WHERE `alert_type` = ".escape($db, $this->type));
            if ($a = $q->fetch_array()) {
                self::$templates[$this->type] = self::$twig_env->twig->createTemplate($a["template"]);
            }
        }

        if (isset(self::$templates[$this->type])) {
            return self::$templates[$this->type]->render(["alert" => $this]);
        } else {
            return $this->type;
        }
    }

    public static function loadLatest(mysqli $db, $options = NULL) {
        if (is_null($options)) {
            $options = [];
        }

        $options = array_merge([
            "server_id" => NULL,
            "check_id" => NULL,
            "stamp_id" => NULL,
            "offset" => 0,
            "limit" => 25
        ], $options);

        $where = [];

        $where[] = "((`a`.`active` = 1 AND `a`.`muted` = 0) OR `a`.`timestamp` >= DATE_ADD(NOW(), INTERVAL -7 DAY))";

        if (!is_null($options["server_id"])) {
            $where[] = "`a`.`server_id` = ".escape($db, $options["server_id"]);
        }

        if (!is_null($options["check_id"])) {
            $where[] = "`a`.`check_id` = ".escape($db, $options["check_id"]);
        }

        if (!is_null($options["stamp_id"])) {
            $where[] = "`a`.`stamp_id` = ".escape($db, $options["stamp_id"]);
        }

        // No right to see hosts, so host-only alerts will be hidden.
        if (!CurrentUser::i()->hasAction('hosts_read')) {
            $where[] = "(`a`.`stamp_id` IS NOT NULL OR `a`.`check_id` IS NOT NULL)";
        }

        // No right to see checks, hide alerts for checks.
        if (!CurrentUser::i()->hasAction('checks_read')) {
            $where[] = "`a`.`check_id` IS NULL";
        }

        // No right to see stamps, hide alerts for stamps.
        if (!CurrentUser::i()->hasAction('stamps_read')) {
            $where[] = "`a`.`stamp_id` IS NULL";
        }

        $query = "SELECT
            `s`.`hostname`,
            `a`.`server_id`,
            `a`.`check_id`,
            `a`.`stamp_id`,
            `a`.`id`,
            `a`.`timestamp`,
            `a`.`type`,
            `a`.`data`,
            `a`.`active`,
            `a`.`until`,
            `a`.`muted`,
            `ch`.`name` AS `check`,
            `st`.`stamp` AS `stamp`
            FROM `alerts` `a`
            LEFT JOIN `servers` `s` ON (`a`.`server_id` = `s`.`id`)
            LEFT JOIN `checks` `ch` ON (`ch`.`id` = `a`.`check_id`)
            LEFT JOIN `stamps` `st` ON (`st`.`id` = `a`.`stamp_id`)
            WHERE ".implode(" AND ", $where)."
            ORDER BY `id` DESC LIMIT ".escape($db, $options["offset"]).", ".escape($db, $options["limit"]);
        $q_all = $db->query($query) or fail($db->error);

        $alerts = [];
        $lowest_id = NULL;
        while ($a = $q_all->fetch_array()) {
            $alerts[] = new Alert($a);

            if (is_null($lowest_id) || $lowest_id > $a["id"]) {
                $lowest_id = $a["id"];
            }
        }

        $where[0] = "`a`.`active` = 1 AND `a`.`muted` = 0";

        if (!is_null($lowest_id)) {
            $where[] = "`a`.`id` < ".escape($db, $lowest_id);
        }

        $q_active = $db->query("SELECT
            `s`.`hostname`,
            `s`.`id` AS `server_id`,
            `a`.`stamp_id`,
            `a`.`id`,
            `a`.`timestamp`,
            `a`.`type`,
            `a`.`data`,
            `a`.`active`,
            `a`.`until`,
            `a`.`muted`,
            `ch`.`name` AS `check`,
            `st`.`stamp` AS `stamp`
            FROM `alerts` `a`
            LEFT JOIN `servers` `s` ON (`a`.`server_id` = `s`.`id`)
            LEFT JOIN `checks` `ch` ON (`ch`.`id` = `a`.`check_id`)
            LEFT JOIN `stamps` `st` ON (`st`.`id` = `a`.`stamp_id`)
            WHERE ".implode(" AND ", $where)."
            ORDER BY `id` DESC LIMIT ".escape($db, $options["offset"]).", ".escape($db, $options["limit"])) or fail($db->error);

        $max_to_remove = $q_active->num_rows;
        for ($i = count($alerts) - 1; $i >= 0; --$i) {
            if (!$alerts[$i]->active && $max_to_remove-- > 0) {
                unset($alerts[$i]);
            }
        }

        while ($a = $q_active->fetch_array()) {
            $alerts[] = new Alert($a);
        }

        return $alerts;
    }
}
