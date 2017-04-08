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

    public function requireAction($action) {
        if (!CurrentUser::i()->hasAction($action)) {
            $db = connect();
            $q = $db->query("SELECT `id`, `name`, `description` FROM `actions` WHERE `name` = ".escape($db, $action));

            if ($a = $q->fetch_assoc()) {
                throw new AccessDenied($a);
            } else {
                throw new AccessDenied(["id" => NULL, "name" => $action, "description" => $action]);
            }
        }
    }

    public function requireAnyAction(...$actions) {
        foreach ($actions as $action) {
            if (CurrentUser::i()->hasAction($action)) {
                return;
            }
        }

        $db = connect();
        $q = $db->query("SELECT `id`, `name`, `description` FROM `actions` WHERE `name` = ".escape($db, $actions[0]));

        if ($a = $q->fetch_assoc()) {
            throw new AccessDenied($a);
        } else {
            throw new AccessDenied(["id" => NULL, "name" => $actions[0], "description" => $actions[0]]);
        }
    }
}
