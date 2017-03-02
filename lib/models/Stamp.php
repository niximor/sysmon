<?php

class Stamp {
    public $id;
    public $stamp;
    public $timestamp;
    public $alert_after;

    public $hostname;
    public $in_alert;

    public function __construct($data = NULL) {
        if (!is_null($data)) {
            $this->id = $data["id"] ?? NULL;
            $this->stamp = $data["stamp"] ?? NULL;
            $this->timestamp = (isset($data["timestamp"]))?DateTime::createFromFormat("Y-m-d G:i:s", $data["timestamp"]):NULL;
            $this->alert_after = $data["alert_after"] ?? NULL;

            $this->hostname = $data["hostname"] ?? NULL;

            if (isset($data["in_alert"])) {
                $this->in_alert = (bool)$data["in_alert"];
            } elseif (!is_null($this->timestamp) && !is_null($this->alert_after)) {
                $this->in_alert = $this->timestamp->getTimestamp() + $this->alert_after < time();
            } else {
                $this->in_alert = false;
            }
        }
    }

    public function __toString() {
        return var_export($this, true);
    }
}