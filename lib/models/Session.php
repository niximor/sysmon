<?php

class Session {
    const COOKIE_NAME = "sysmon_sessid";
    const DEFAULT_LIFETIME = 7200;

    public static $instance = NULL;

    protected $id = NULL;
    protected $data = [];
    protected $changed = [];
    protected $lifetime = [];

    protected static function tryInitialize() {
        if (is_null(self::$instance) && isset($_COOKIE[self::COOKIE_NAME])) {
            self::$instance = new self();
        }
    }

    public static function id() {
        self::tryInitialize();

        if (!is_null(self::$instance)) {
            return self::$instance->id;
        } else {
            return self::rand_id();
        }
    }

    public static function set($name, $value, $lifetime = NULL) {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        self::$instance->data[$name] = $value;
        self::$instance->lifetime[$name] = $lifetime;
        self::$instance->changed[$name] = true;
    }

    public static function get($name, $default = NULL) {
        self::tryInitialize();

        if (is_null(self::$instance)) {
            return $default;
        } else {
            return self::$instance->data[$name] ?? $default;
        }
    }

    public static function getLifetime($name) {
        self::tryInitialize();

        if (!is_null(self::$instance)) {
            return self::$instance->lifetime[$name] ?? NULL;
        } else {
            return NULL;
        }
    }

    public static function destroy() {
        if (isset($_COOKIE[self::COOKIE_NAME])) {
            $db = connect();
            $db->query("DELETE FROM `session` WHERE `id` = ".escape($db, $_COOKIE[self::COOKIE_NAME])) or fail($db->error);
            $db->commit();

            setcookie(self::COOKIE_NAME, "");
        }
    }

    protected static function rand_id($length = 32, $alphabet = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789") {
        $out = "";
        for ($i = 0; $i < $length; ++$i) {
            $out .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    protected function __construct() {
        if (!isset($_COOKIE[self::COOKIE_NAME])) {
            $rand = $this->rand_id();
            $_COOKIE[self::COOKIE_NAME] = $rand;

            $this->id = $rand;
        } else {
            $this->id = $_COOKIE[self::COOKIE_NAME];
        }

        $db = connect();
        $q = $db->query("SELECT `name`, `value`, `lifetime` FROM `session` WHERE `id` = ".escape($db, $this->id)." AND `timestamp` > DATE_ADD(NOW(), INTERVAL -COALESCE(`lifetime`, ".self::DEFAULT_LIFETIME.") SECOND)") or fail($db->error);

        // Remember lifetime. If there is session that has lifetime set, extend the cookie.
        $max_lifetime = 0;

        while ($a = $q->fetch_array()) {
            $this->data[$a["name"]] = $a["value"];
            $this->lifetime[$a["name"]] = $a["lifetime"];
            if (!is_null($a["lifetime"]) && $max_lifetime < $a["lifetime"]) {
                $max_lifetime = $a["lifetime"];
            }
        }

        $host = $_SERVER["HTTP_HOST"];
        if (isset($_SERVER["HTTP_X_FORWARDED_HOST"])) {
            $host = $_SERVER["HTTP_X_FORWARDED_HOST"];
        }

        setcookie(self::COOKIE_NAME, $this->id, (($max_lifetime > 0)?(time() + $max_lifetime):0), "/", $host, isset($_SERVER["HTTPS"]));

        register_shutdown_function(array($this, "save"));
    }

    public function save() {
        if (empty($this->changed)) {
            return;
        }

        $db = connect();

        $to_insert = [];

        foreach ($this->changed as $key => $dummy) {
            $to_insert[] = "(".escape($db, $this->id).", ".escape($db, $key).", ".escape($db, $this->data[$key]).", NOW(), ".escape($db, $this->lifetime[$key] ?? NULL).")";
        }

        $db->query("INSERT INTO `session` (`id`, `name`, `value`, `timestamp`, `lifetime`) VALUES ".implode(",", $to_insert)." ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `lifetime` = COALESCE(VALUES(`lifetime`), `lifetime`)") or fail($db->error);
        $db->query("UPDATE `session` SET `timestamp` = NOW() WHERE `id` = ".escape($db, $this->id)) or fail($db->error);
        $db->commit();
    }

    public static function cleanup() {
        $db = connect();
        $db->query("DELETE FROM `session` WHERE `timestamp` < DATE_ADD(NOW(), INTERVAL -COALESCE(`lifetime`, ".self::DEFAULT_LIFETIME.") SECOND)") or fail($db->error);
        $db->commit();
    }
}
