<?php

class FormSave {
    protected $data = [];
    protected $clearErrors = false;

    public function __construct($code = NULL) {
        if (!is_null($code)) {
            $this->load($code);
        }

        $this->clearErrors = true;

        foreach ($_POST as $key => $val) {
            $this->data[$key] = [
                "value" => $val,
                "errors" => []
            ];
        }
    }

    protected function load($code) {
        $db = connect();

        $q = $db->query("SELECT `id`, `json_data` FROM `formsave` WHERE `code` = ".escape($db, $code)." FOR UPDATE") or fail($db->error);
        if ($a = $q->fetch_assoc()) {
            $this->data = json_decode($a["json_data"], true);

            $db->query("DELETE FROM `formsave` WHERE `id` = ".escape($db, $a["id"])) or fail($db->error);
            $db->commit();
        } else {
            if (in_array($_SERVER["REQUEST_METHOD"], ["POST", "PUT", "PATCH"])) {
                $db->rollback();
                throw new AccessDenied("Unknown form session. Action denied.");
            } else {
                $db->commit();
            }
        }
    }

    public function getValues() {
        return array_map(function($item) {
            return $item["value"];
        }, $this->data);
    }

    public function getErrors() {
        return array_map(function($item) {
            return $item["errors"];
        }, $this->data);
    }

    public function getValue($item) {
        return $this->data[$item]["value"] ?? NULL;
    }

    public function isChecked($item) {
        return isset($this->data[$item]) && $this->data[$item]["value"] != NULL;
    }

    public function require($item, $name) {
        if (!isset($this->data[$item]) || empty($this->data[$item]["value"])) {
            $this->addError($item, "Field <strong>".$name."</strong> must be filled in.");
        }
    }

    public function addError($item, $error) {
        if ($this->clearErrors) {
            $this->clearErrors = false;

            foreach ($this->data as &$i) {
                if (!empty($i["errors"])) {
                    $i["errors"] = [];
                }
            }
        }

        if (!isset($this->data[$item])) {
            $this->data[$item] = [
                "value" => NULL,
                "errors" => []
            ];
        }

        $this->data[$item]["errors"][] = $error;
    }

    public function isValid() {
        foreach ($this->data as $item) {
            if (!empty($item["errors"])) {
                return false;
            }
        }

        return true;
    }

    public function save() {
        $code = hash("sha256", ((string)time())."-".((string)mt_rand()));

        $db = connect();
        $db->query("INSERT INTO `formsave` (`code`, `timestamp`, `json_data`) VALUES
            (".escape($db, $code).", NOW(), ".escape($db, json_encode($this->data)).")") or fail($db->error);
        $db->commit();

        return $code;
    }
}