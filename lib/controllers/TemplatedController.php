<?php

require_once "util/TwigEnv.php";

require_once "fastrouter/Controller.php";

require_once "models/Message.php";
require_once "models/CurrentUser.php";

class TemplatedController extends TwigEnv implements \nixfw\fastrouter\Controller {
    protected function renderTemplate($name, $context = []) {
        $template = $this->twig->load($name);
        return $template->render(array_merge(
            $context, [
                "controller" => get_class($this),
                "request" => $_REQUEST,
                "get" => $_GET,
                "post" => $_POST,
                "total_alerts_count" => $this->countAlerts(),
                "messages" => Message::get(),
                "current_user" => new CurrentUser()
            ]
        ));
    }

    public function countAlerts() {
        $db = connect();
        $q = $db->query("SELECT COUNT(id) AS `count` FROM `alerts` WHERE `active` = 1");
        $db->commit();

        return $q->fetch_array()["count"];

    }
}
