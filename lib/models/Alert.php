<?php

class Alert {
    public $id;
    public $server_id;
    public $timestamp;
    public $until;
    public $type;
    public $data;
    public $active;
    public $sent;

    // Foreign properties
    public $hostname;

    public function __construct($data = NULL) {
        if (!is_null($data)) {
            $this->id = $data["id"] ?? NULL;
            $this->server_id = $data["server_id"] ?? NULL;
            $this->timestamp = (isset($data["timestamp"]))?DateTime::createFromFormat("Y-m-d G:i:s", $data["timestamp"]):NULL;
            $this->until = (isset($data["until"]))?DateTime::createFromFormat("Y-m-d G:i:s", $data["until"]):NULL;
            $this->type = $data["type"] ?? NULL;
            $this->data = (isset($data["data"]))?json_decode($data["data"]):NULL;
            $this->active = $data["active"] ?? NULL;
            $this->sent = $data["sent"] ?? NULL;

            // Foreign properties
            $this->hostname = $data["hostname"] ?? NULL;
        }
    }

    public function getMessage() {
        if (is_null($this->until)) {
            $until = new DateTime();
        } else {
            $until = $this->until;
        }

        switch ($this->type) {
            case "dead":
                $since = DateTime::createFromFormat("Y-m-d G:i:s", $this->data->last_check);
                if ($this->active) {
                    return "Host is dead since ".$since->format("Y-m-d H:i:s")." (down for ".format_duration($until->getTimestamp() - $since->getTimestamp()).").";
                } else {
                    return "Host was dead since ".$since->format("Y-m-d H:i:s")." (was down for ".format_duration($until->getTimestamp() - $since->getTimestamp()).").";
                }

            case "rebooted":
                return "Host has been rebooted. Was up for ".format_duration($this->data->uptime).".";

            default:
                return $type;
        }
    }
}
