<?php

require_once "util/TwigEnv.php";

require_once "fastrouter/Controller.php";

require_once "models/Message.php";
require_once "models/CurrentUser.php";

require_once "exceptions/AccessDenied.php";

class TemplatedController extends TwigEnv implements \nixfw\fastrouter\Controller {
    protected function renderTemplate($name, $context = []) {
        $template = $this->twig->load($name);
        return $template->render($context);
    }

    public function countAlerts() {
        $db = connect();
        $q = $db->query("SELECT COUNT(id) AS `count` FROM `alerts` WHERE `active` = 1");
        $db->commit();

        return $q->fetch_array()["count"];
    }

    public function requireAction($action) {
        if (!CurrentUser::i()->hasAction($action)) {
            $db = connect();
            $q = $db->query("SELECT `id`, `name`, `description` FROM `actions` WHERE `name` = ".escape($db, $action));

            if ($a = $q->fetch_assoc()) {
                throw new Accessdenied($a);
            } else {
                throw new AccessDenied(["id" => NULL, "name" => $action, "description" => $action]);
            }
        }
    }
}
