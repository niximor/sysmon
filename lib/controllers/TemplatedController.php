<?php

require_once "fastrouter/Controller.php";

require_once "models/Message.php";
require_once "models/CurrentUser.php";

class TemplatedController implements \nixfw\fastrouter\Controller {
    protected $twig;

    public function __construct() {
        $loader = new Twig_Loader_Filesystem(__DIR__."/../../templ/");
        $this->twig = new Twig_Environment($loader, ["debug" => true]);

        $this->twig->addFilter(new Twig_Filter("datetime", function($datetime, $format=NULL) {
            if (is_null($format)) {
                $format = "Y-m-d G:i:s";
            }

            if (!is_null($datetime)) {
                return $datetime->format($format);
            } else {
                return "N/A";
            }
        }));

        $this->twig->addFilter(new Twig_Filter("duration", "format_duration"));
        $this->twig->addFilter(new Twig_Filter("sorted", array($this, "twig_sorted"), ["is_safe" => ["html"]]));

        $this->twig->addFunction(new Twig_Function("url_for", "twig_url_for"));
    }

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

    public function twig_sorted($val, $key, $default=false, $namespace="") {
        $url = $_SERVER["REQUEST_URI"];
        if ($_SERVER["QUERY_STRING"]) {
            $url = substr($url, 0, -strlen($_SERVER["QUERY_STRING"]) - 1);
        }

        if (!empty($namespace)) {
            $namespace = $namespace.".";
        }

        $direction = "ASC";
        if (($_GET[$namespace."order"] ?? (($default)?$key:"")) == $key && ($_GET[$namespace."direction"] ?? "ASC") == "ASC") {
            $direction = "DESC";
        }

        $url .= "?".http_build_query(array_merge($_GET, [
            $namespace."order" => $key,
            $namespace."direction" => $direction
        ]));

        if (($_REQUEST[$namespace."order"] ?? (($default)?$key:"")) == $key) {
            $direction = (($_GET[$namespace."direction"] ?? "ASC") == "ASC");
            return "<a href=\"".$url."\" class=\"sorted\">".$val." <span class=\"fa ".(($direction)?"fa-sort-asc":"fa-sort-desc")."\"></span></a>";
        } else {
            return "<a href=\"".$url."\" class=\"sorted\">".$val." <span class=\"fa fa-sort\"></span></a>";
        }
    }
}
