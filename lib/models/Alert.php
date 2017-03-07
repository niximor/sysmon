<?php

require_once "util/TwigEnv.php";

class Alert {
    public $id;
    public $server_id;
    public $check_id;
    public $timestamp;
    public $until;
    public $type;
    public $data;
    public $active;
    public $sent;

    // Foreign properties
    public $hostname;
    public $check;

    protected static $templates;
    protected static $twig_env;

    public function __construct($data = NULL) {
        if (!is_null($data)) {
            $this->id = $data["id"] ?? NULL;
            $this->server_id = $data["server_id"] ?? NULL;
            $this->check_id = $data["check_id"] ?? NULL;
            $this->timestamp = (isset($data["timestamp"]))?DateTime::createFromFormat("Y-m-d G:i:s", $data["timestamp"]):NULL;
            $this->until = (isset($data["until"]))?DateTime::createFromFormat("Y-m-d G:i:s", $data["until"]):NULL;
            $this->type = $data["type"] ?? NULL;
            $this->data = (isset($data["data"]))?json_decode($data["data"]):NULL;
            $this->active = $data["active"] ?? NULL;
            $this->sent = $data["sent"] ?? NULL;

            // Foreign properties
            $this->hostname = $data["hostname"] ?? NULL;
            $this->check = $data["check"] ?? NULL;
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

    public static function loadLatest(mysqli $db, $server_id=NULL, $check_id=NULL, $offset=0, $limit=25) {
        $display = 25;

        $where = [];

        $where[] = "(`a`.`active` = 1 OR `a`.`timestamp` >= DATE_ADD(NOW(), INTERVAL -7 DAY))";

        if (!is_null($server_id)) {
            $where[] = "`a`.`server_id` <=> ".escape($db, $server_id);
        }

        if (!is_null($check_id)) {
            $where[] = "`a`.`check_id` = ".escape($db, $check_id);
        }

        $query = "SELECT
            `s`.`hostname`,
            `a`.`server_id`,
            `a`.`check_id`,
            `a`.`id`,
            `a`.`timestamp`,
            `a`.`type`,
            `a`.`data`,
            `a`.`active`,
            `a`.`until`,
            `ch`.`name` AS `check`
            FROM `alerts` `a`
            JOIN `servers` `s` ON (`a`.`server_id` = `s`.`id`)
            LEFT JOIN `checks` `ch` ON (`ch`.`id` = `a`.`check_id`)
            WHERE ".implode(" AND ", $where)."
            ORDER BY `id` DESC LIMIT ".$offset.", ".$limit;
        $q_all = $db->query($query) or fail($db->error);

        $alerts = [];
        $lowest_id = NULL;
        while ($a = $q_all->fetch_array()) {
            $alerts[] = new Alert($a);

            if (is_null($lowest_id) || $lowest_id > $a["id"]) {
                $lowest_id = $a["id"];
            }
        }

        $where[0] = "`a`.`active` = 1";
        $where[] = "`a`.`id` < ".$lowest_id;

        $q_active = $db->query("SELECT
            `s`.`hostname`,
            `s`.`id` AS `server_id`,
            `a`.`id`,
            `a`.`timestamp`,
            `a`.`type`,
            `a`.`data`,
            `a`.`active`,
            `a`.`until`,
            `ch`.`name` AS `check`
            FROM `alerts` `a`
            JOIN `servers` `s` ON (`a`.`server_id` = `s`.`id`)
            LEFT JOIN `checks` `ch` ON (`ch`.`id` = `a`.`check_id`)
            WHERE ".implode(" AND ", $where)."
            ORDER BY `id` DESC LIMIT ".$offset.", ".$limit) or fail($db->error);

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
