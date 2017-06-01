<?php

class SettingsController extends TemplatedController {
    public function config() {
        $this->requireAction("system_config_read");

        $db = connect();

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $this->requireAction("system_config_write");

            foreach ($_POST["config"] as $key=>$val) {
                $db->query("UPDATE `config` SET `data` = ".escape($db, $val)." WHERE `name` = ".escape($db, $key)) or fail($db->error);
            }

            $db->commit();

            Message::create(Message::SUCCESS, "Configuration was updated.");
            header("Location: ".twig_url_for(['SettingsController', 'config']));
            exit;
        }

        $q = $db->query("SELECT `name`, `type`, `data` FROM `config` ORDER BY `name` ASC") or fail($db->error);

        $config = [];
        while ($a = $q->fetch_assoc()) {
            $config[] = $a;
        }

        return $this->renderTemplate("settings/config.html", ["config" => $config]);
    }
}
