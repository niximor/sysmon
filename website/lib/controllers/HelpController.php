<?php

require_once "controllers/TemplatedController.php";

require_once "exceptions/EntityNotFound.php";

class HelpController extends TemplatedController {
    public function index() {
        $db = connect();

        $topics = [];

        $q = $db->query("SELECT `id`, `topic_name`, `url` FROM `help` ORDER BY `topic_name` ASC") or fail($db->error);
        while ($a = $q->fetch_array()) {
            $topics[] = [
                "url" => $a["url"],
                "name" => $a["topic_name"]
            ];
        }

        return $this->renderTemplate("settings/help/index.html", [
            "topics" => $topics
        ]);
    }

    public function add() {
        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $db = connect();

            $db->query("INSERT INTO `help` (`topic_name`, `url`, `text`) VALUES (".escape($db, $_REQUEST["name"]).", ".escape($db, $_REQUEST["url"]).", ".escape($db, $_REQUEST["text"]).")") or fail($db->error);
            $db->commit();

            Message::create(Message::SUCCESS, "Topic has been created.");

            header("Location: ".twig_url_for(["HelpController", "index"]));
            exit;
        }

        return $this->renderTemplate("settings/help/add.html");
    }

    public function edit($id) {
        $db = connect();

        $q = $db->query("SELECT `id`, `topic_name` AS `name`, `url`, `text` FROM `help` WHERE `id` = ".escape($db, $id));
        $topic = $q->fetch_array();

        if (!$topic) {
            throw new EntityNotFound("Topic does not exists.");
        }

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $db->query("UPDATE `help` SET `topic_name` = ".escape($db, $_REQUEST["name"]).", `url` = ".escape($db, $_REQUEST["url"]).", `text` = ".escape($db, $_REQUEST["text"])." WHERE `id` = ".escape($db, $topic["id"]));
            $db->commit();

            Message::create(Message::SUCCESS, "Topic has been modified.");

            header("Location: ".twig_url_for(["HelpController", "topic"], ["topic" => $_REQUEST["url"]]));
            exit;
        }

        return $this->renderTemplate("settings/help/edit.html", [
            "topic" => $topic
        ]);
    }

    public function remove($id) {
        $db = connect();

        $db->query("DELETE FROM `topics` WHERE `id` = ".escape($db, $id));

        if ($db->affected_rows == 0) {
            throw new EntityNotFOund("Topic does not exists.");
        }

        $db->commit();

        Message::create(Message::SUCCESS, "Topic has been removed.");

        header("Location: ".twig_url_for(["HelpController", "index"]));
        exit;
    }

    public function topic($topic) {
        $db = connect();

        $q = $db->query("SELECT `id`, `topic_name` AS `name`, `url`, `text` FROM `help` WHERE `url` = ".escape($db, $topic)) or fail($db->error);
        $topic = $q->fetch_array();

        if (!$topic) {
            throw new EntityNotFound("Topic does not exists.");
        }

        $topic["text"] = \Michelf\Markdown::defaultTransform($topic["text"]);

        $q = $db->query("SELECT `id`, `topic_name` AS `name`, `url` FROM `help` WHERE `topic_name` < ".escape($db, $topic["name"])." ORDER BY `name` DESC LIMIT 1") or fail($db->error);
        $previous = $q->fetch_assoc();

        $q = $db->query("SELECT `id`, `topic_name` AS `name`, `url` FROM `help` WHERE `topic_name` > ".escape($db, $topic["name"])." ORDER BY `name` ASC LIMIT 1") or fail($db->error);
        $next = $q->fetch_assoc();

        return $this->renderTemplate("settings/help/topic.html", [
            "topic" => $topic,
            "previous" => $previous,
            "next" => $next
        ]);
    }

    public function get($topic) {
        $db = connect();

        $q = $db->query("SELECT `id`, `topic_name` AS `name`, `url`, `text` FROM `help` WHERE `url` = ".escape($db, $topic));
        $topic = $q->fetch_assoc();

        if (!$topic) {
            throw new EntityNotFound("Topic does not exists.");
        }

        $topic["text"] = \Michelf\Markdown::defaultTransform($topic["text"]);

        return json_encode([
            "topic" => $topic
        ]);
    }
}
