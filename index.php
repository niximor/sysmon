<?php

ini_set("display_errors", true);
ini_set("display_startup_errors", true);

error_reporting(E_ALL);

require_once "lib/common.php";

require_once "controllers/HostsController.php";
require_once "controllers/OverviewController.php";
require_once "controllers/PackagesController.php";
require_once "controllers/ErrorController.php";

require_once "fastrouter/FastRouter.php";

$router = new \nixfw\fastrouter\FastRouter();
$router->bind("/", array("OverviewController", "index"));
$router->bind("/hosts", array("HostsController", "index"));
$router->bind("/hosts/<id>/detail", array("HostsController", "detail"));
$router->bind("/hosts/<id>/history", array("HostsController", "history"));
$router->bind("/packages", array("PackagesController", "index"));

function twig_url_for(...$args) {
    global $router;

    if (($args[0] ?? NULL) == "static") {
        return "/static/".($args[1] ?? "");
    } else {
        return $router->urlFor(...$args);
    }
}

$url = substr($_SERVER["REQUEST_URI"], strlen(dirname($_SERVER["SCRIPT_NAME"])));
$qsl = strlen($_SERVER["QUERY_STRING"]);
if ($qsl > 0) {
    $url = substr($url, 0, -$qsl - 1);
}

$route = $router->resolve($url);

if ($route) {
    try {
        echo $route->execute();
    } catch (Throwable $t) {
        $ec = new ErrorController();
        echo $ec->error500($t);
    }
} else {
    // No route has been found, that is 404.
    $ec = new ErrorController();
    echo $ec->error404();
}
