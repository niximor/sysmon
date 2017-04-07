<?php

class TwigEnv {
    public $twig;

    public function __construct() {
        $loader = new Twig_Loader_Filesystem(__DIR__."/../../templ/");
        $this->twig = new Twig_Environment($loader, ["debug" => true]);

        $this->twig->addFilter(new Twig_Filter("datetime", array($this, "twig_filter_datetime")));
        $this->twig->addFilter(new Twig_Filter("timestamp", array($this, "twig_filter_timestamp")));
        $this->twig->addFilter(new Twig_Filter("duration", "format_duration"));
        $this->twig->addFilter(new Twig_Filter("sorted", array($this, "twig_sorted"), ["is_safe" => ["html"]]));

        $this->twig->addFunction(new Twig_Function("url_for", "twig_url_for"));

        $this->twig->addGlobal("current_user", CurrentUser::i());
        $this->twig->addGlobal("controller", get_class($this));
        $this->twig->addGlobal("request", $_REQUEST);
        $this->twig->addGlobal("get", $_GET);
        $this->twig->addGlobal("post", $_POST);
        $this->twig->addGlobal("total_alerts_count", $this->countAlerts());
        $this->twig->addGlobal("messages", Message::get());
    }

    public static function twig_filter_datetime($datetime, $format=NULL) {
        if (is_null($format)) {
            $format = "Y-m-d G:i:s";
        }

        if (!is_null($datetime)) {
            if (!is_object($datetime)) {
                $datetime = DateTime::createFromFormat("Y-m-d G:i:s", $datetime);
            }

            return $datetime->format($format);
        } else {
            return "N/A";
        }
    }

    public static function twig_filter_timestamp($datetime) {
        if (is_null($datetime)) {
            return time();
        }

        if (!is_object($datetime)) {
            $datetime = DateTime::createFromFormat("Y-m-d G:i:s", $datetime);
        }

        return $datetime->getTimestamp();
    }

    public static function twig_sorted($val, $key, $default=false, $namespace="") {
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