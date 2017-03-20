<?php

require_once "models/Session.php";

class Message {
    const ERROR = 1;
    const INFORMATION = 2;
    const WARNING = 3;
    const SUCCESS = 4;

    public $type;
    public $text;

    private function __construct(int $type, string $text) {
        $this->type = $type;
        $this->text = $text;
    }

    public function getType() {
        switch ($this->type) {
            case self::ERROR: return "danger";
            case self::INFORMATION: return "info";
            case self::WARNING: return "warning";
            case self::SUCCESS: return "success";
        }
    }

    public static function create(int $type, string $text) {
        $msg = json_decode(Session::get("messages", "[]"));
        $msg[] = ["type" => $type, "text" => $text];
        Session::set("messages", json_encode($msg));
    }

    public static function get() {
        $msg = json_decode(Session::get("messages", "[]"));

        if (!empty($msg)) {
            Session::set("messages", "[]");
        }

        return array_map(function($item) {
            return new self($item->type ?? self::INFORMATION, $item->text ?? "");
        }, $msg);
    }
}
