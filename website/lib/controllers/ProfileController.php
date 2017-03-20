<?php

require_once "controllers/TemplatedController.php";

require_once "models/Message.php";

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
}
