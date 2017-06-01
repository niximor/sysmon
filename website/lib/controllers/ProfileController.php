<?php

require_once "controllers/TemplatedController.php";

require_once "models/Message.php";
require_once "models/FormSave.php";
require_once "models/CurrentUser.php";

require_once "util/Email.php";

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

    public function notifications() {
        $fs = new FormSave($_REQUEST["formsave"] ?? NULL);

        $notifications = [];

        $db = connect();
        $q = $db->query("SELECT `type`, `enabled`, `params` FROM `notification_settings` WHERE `user_id` = ".escape($db, CurrentUser::ID())) or fail($db->error);
        while ($a = $q->fetch_assoc()) {
            $notifications[$a["type"]] = array_merge(["enabled" => (bool)$a["enabled"]], (array)json_decode($a["params"]));
        }

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $this->save_notification_settings($db, $fs->getValue("xmpp"), $fs, "xmpp");
            $this->save_notification_settings($db, $fs->getValue("email"), $fs, "email");

            if ($fs->isValid()) {
                $db->commit();
                Message::create(Message::SUCCESS, "Notification settings were successfully saved.");
                header("Location: ".twig_url_for(['ProfileController', 'notifications']));
            } else {
                header("Location: ".twig_url_for(['ProfileController', 'notifications'])."?formsave=".$fs->save());
                exit;
            }
        }

        return $this->renderTemplate("profile/notifications.html", [
            "form" => $fs->getValues(),
            "formerrors" => $fs->getErrors(),
            "formsave" => $fs->save(),
            "notifications" => $notifications
        ]);
    }

    protected function save_notification_settings($db, $n, $fs, $type) {
        $enabled = $n["enabled"] ?? false;
        $params = [];

        // Validate fields by type.
        switch ($type) {
            case "xmpp":
                if ($enabled) {
                    if (!isset($n["jid"]) || empty($n["jid"])) {
                        $fs->addError($type."[jid]", "Field <strong>JID</strong> must be filled in.");

                    // source: https://stackoverflow.com/questions/1351041/what-is-the-regular-expression-for-validating-jabber-id/1406200
                    } elseif (!preg_match("|^(?:([^@/<>'\"]+)@)?([^@/<>'\"]+)(?:/([^<>'\"]*))?$|", $n["jid"])) {
                        $fs->addError($type."[jid]", "Field <strong>JID</strong> must contain valid JID.");
                    }
                }
                $params["jid"] = $n["jid"];
                break;

            case "email":
                if ($enabled) {
                    if (!isset($n["address"]) || empty($n["address"])) {
                        $fs->addError($type."[address]", "Field <strong>Address</strong> must be filled in.");
                    }

                    try {
                        $n["address"] = Email::validate($n["address"]);
                    } catch (EmailValidationError $e) {
                        $fs->addError($type."[address]", "Field <strong>Address</strong> must contain valid email address.");
                    }
                }

                $params["address"] = $n["address"];
                break;
        }

        if (!$fs->isValid()) {
            return;
        }

        $q = $db->query("SELECT `id` FROM `notification_settings` WHERE `user_id` = ".escape($db, CurrentUser::ID())." AND `type` = ".escape($db, $type)) or fail($db->error);
        if ($a = $q->fetch_assoc()) {
            $db->query("UPDATE `notification_settings`
                SET `enabled` = ".escape($db, $enabled).", `params` = ".escape($db, json_encode($params))."
                WHERE `id` = ".escape($db, $a["id"])) or fail($db->error);
        } else {
            $db->query("INSERT INTO `notification_settings` (`user_id`, `type`, `enabled`, `params`)
                VALUES (".escape($db, CurrentUser::ID()).", ".escape($db, $type).", ".escape($db, $enabled).", ".escape($db, json_encode($params)).")") or fail($db->error);
        }
    }
}
