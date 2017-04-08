<?php

require_once "controllers/TemplatedController.php";

require_once "models/Message.php";
require_once "models/FormSave.php";
require_once "models/CurrentUser.php";

class ProfileController extends TemplatedController {
    public function index() {
        $db = connect();

        // List user's sessions.
        $sessions = [];
        $q = $db->query("SELECT `id`, `timestamp` FROM `session` WHERE `name` = 'user_id' AND `value` = ".escape($db, Session::get("user_id")));
        while ($a = $q->fetch_array()) {
            $qs = $db->query("SELECT `name`, `value` FROM `session` WHERE `id` = ".escape($db, $a["id"])." AND `name` IN ('ip', 'ua')");

            $session = [
                "id" => $a["id"],
                "current" => ($a["id"] == Session::id()),
                "last_seen" => DateTime::createFromFormat("Y-m-d G:i:s", $a["timestamp"]),
            ];

            while ($as = $qs->fetch_array()) {
                $session[$as["name"]] = $as["value"];

                if ($as["name"] == "ip") {
                    $session["hostname"] = gethostbyaddr($as["value"]);
                }
            }

            $sessions[] = $session;
        }

        usort($sessions, function($a, $b) {
            if ($a["last_seen"]->getTimestamp() > $b["last_seen"]->getTimestamp()) {
                return -1;
            } else if ($a["last_seen"]->getTimestamp() < $b["last_seen"]->getTimestamp()) {
                return 1;
            } else {
                return 0;
            }
        });

        return $this->renderTemplate("profile/index.html", [
            "sessions" => $sessions
        ]);
    }

    public function revoke($session) {
        $db = connect();

        $q = $db->query("SELECT value FROM `session` WHERE `id` = ".escape($db, $session)." AND `name` = 'user_id'");
        if ($a = $q->fetch_array()) {
            if ($a["value"] == Session::get("user_id")) {
                $db->query("DELETE FROM `session` WHERE `id` = ".escape($db, $session));
                $db->commit();

                Message::create(Message::SUCCESS, "Session has been revoked.");
            } else {
                Message::create(Message::ERROR, "Session does not exists.");
            }
        } else {
            Message::create(Message::ERROR, "Session does not exists.");
        }

        header("Location: ".twig_url_for(["ProfileController", "index"]));
    }

    public function change_password() {
        $fs = new FormSave($_REQUEST["formsave"] ?? NULL);

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $db = connect();

            $fs->require("old_password", "Old password");
            $fs->require("password", "New password");
            $fs->require("password1", "Retype new password");

            if ($fs->getValue("password") && $fs->getValue("password1") && $fs->getValue("password") != $fs->getValue("password1")) {
                $fs->addError("password", "Passwords did not match.");
            }

            if ($fs->getValue("old_password")) {
                $q = $db->query("SELECT `salt`, `password` FROM `users` WHERE `id` = ".escape($db, CurrentUser::ID()));
                $a = $q->fetch_assoc();
                if (hash("sha256", $a["salt"].$fs->getValue("old_password")) != $a["password"]) {
                    $fs->addError("old_password", "Old password is incorrect.");
                }
            }

            if (!$fs->isValid()) {
                header("Location: ".twig_url_for(['ProfileController', 'change_password'])."?formsave=".$fs->save());
                exit;
            }

            $salt = hash("sha256", ((string)time())."-".((string)mt_rand()));
            $password = hash("sha256", $salt.$fs->getValue("password"));

            $db->query("UPDATE `users` SET
                `salt` = ".escape($db, $salt).",
                `password` = ".escape($db, $password)."
                WHERE `id` = ".escape($db, CurrentUser::ID()));

            $db->commit();

            Message::create(Message::SUCCESS, "Password has been changed.");
            header("Location: ".twig_url_for(['ProfileController', 'index']));
        }

        return $this->renderTemplate("profile/change_password.html", [
            "form" => $fs->getValues(),
            "formerrors" => $fs->getErrors(),
            "formsave" => $fs->save()
        ]);
    }
}
