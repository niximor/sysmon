<?php

require_once "controllers/TemplatedController.php";

require_once "models/Session.php";

class LoginController extends TemplatedController {
    const LOGIN_LIFETIME_LONG = 86400 * 365;
    const LOGIN_LIFETIME_SHORT = NULL;

    public function index(...$args) {
        if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["username"]) && isset($_POST["password"])) {
            $db = connect();
            $q = $db->query("SELECT `id`, `salt`, `password` FROM `users` WHERE `name` = ".escape($db, $_POST["username"]));

            $valid = false;

            if ($a = $q->fetch_array()) {
                if (hash("sha256", $a["salt"].$_POST["password"]) == $a["password"]) {
                    $lifetime = (isset($_POST["remember"]))?self::LOGIN_LIFETIME_LONG:self::LOGIN_LIFETIME_SHORT;
                    Session::set("user_id", $a["id"], $lifetime);
                    $valid = true;
                }
            }

            if (!$valid) {
                Message::create(Message::ERROR, "Unknown username or password.");
            }

            header("Location: /".implode("/", $args));
            exit;
        }

        return $this->renderTemplate("login/index.html");
    }

    public function logout() {
        Session::destroy();

        header("Location: /");
        exit;
    }
}
