<?php

class Session {
    const COOKIE_NAME = "sysmon_sessid";
    const DEFAULT_LIFETIME = 7200;

    public static $instance = NULL;

    protected $id = NULL;
    protected $data = [];
    protected $changed = [];
    protected $lifetime = [];

    public static function set($name, $value, $lifetime = NULL) {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        self::$instance->data[$name] = $value;
        self::$instance->lifetime[$name] = $lifetime;
        self::$instance->changed[$name] = true;
    }

    public static function get($name, $default = NULL) {
        if (is_null(self::$instance) && isset($_COOKIE[self::COOKIE_NAME])) {
            self::$instance = new self();
        }

        if (is_null(self::$instance)) {
            return $default;
        } else {
            return self::$instance->data[$name] ?? $default;
        }
    }

    public static function destroy() {
        if (isset($_COOKIE[self::COOKIE_NAME])) {
            $db = connect();
            $db->query("DELETE FROM `session` WHERE `id` = ".escape($db, $_COOKIE[self::COOKIE_NAME]));
            $db->commit();

            setcookie(self::COOKIE_NAME, "");
        }
    }

    protected function rand_id($length = 32, $alphabet = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789") {
        $out = "";
        for ($i = 0; $i < $length; ++$i) {
            $out .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    protected function __construct() {
        if (!isset($_COOKIE[self::COOKIE_NAME])) {
            $rand = $this->rand_id();

            $host = $_SERVER["HTTP_HOST"];
            if (isset($_SERVER["HTTP_X_FORWARDED_HOST"])) {
                $host = $_SERVER["HTTP_X_FORWARDED_HOST"];
            }

            $_COOKIE[self::COOKIE_NAME] = $rand;
            setcookie(self::COOKIE_NAME, $rand, 0, "/", $host, isset($_SERVER["HTTPS"]));

            $this->id = $rand;
        } else {
            $this->id = $_COOKIE[self::COOKIE_NAME];
        }

        $db = connect();
        $q = $db->query("SELECT `name`, `value` FROM `session` WHERE `id` = ".escape($db, $this->id)." AND `timestamp` > DATE_ADD(NOW(), INTERVAL -`lifetime` SECOND)");

        while ($a = $q->fetch_array()) {
            $this->data[$a["name"]] = $a["value"];
        }

        register_shutdown_function(array($this, "save"));
    }

    public function save() {
        if (empty($this->changed)) {
            return;
        }

        $db = connect();

        $to_insert = [];

        foreach ($this->changed as $key => $dummy) {
            $to_insert[] = "(".escape($db, $this->id).", ".escape($db, $key).", ".escape($db, $this->data[$key]).", NOW(), ".escape($db, $this->lifetime[$key] ?? self::DEFAULT_LIFETIME).")";
        }

        $db->query("INSERT INTO `session` (`id`, `name`, `value`, `timestamp`, `lifetime`) VALUES ".implode(",", $to_insert)." ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)") or fail($db->error);
        $db->query("UPDATE `session` SET `timestamp` = NOW() WHERE `id` = ".escape($db, $this->id)) or fail($db->error);
        $db->commit();
    }

    public static function cleanup() {
        $db = connect();
        $db->query("DELETE FROM `session` WHERE `timestamp` < DATE_ADD(NOW(), INTERVAL -`lifetime` SECOND)");
        $db->commit();
    }
}
