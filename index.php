<?php

ini_set("display_errors", true);
ini_set("display_startup_errors", true);

error_reporting(E_ALL);

require_once "lib/common.php";

require_once "exceptions/EntityNotFound.php";

require_once "controllers/LoginController.php";
require_once "controllers/HostsController.php";
require_once "controllers/OverviewController.php";
require_once "controllers/PackagesController.php";
require_once "controllers/ErrorController.php";
require_once "controllers/StampsController.php";

require_once "fastrouter/FastRouter.php";

require_once "models/Session.php";

$router = new \nixfw\fastrouter\FastRouter();

$router->bind("/stamps/put/<hostname>/<stamp>", array("StampsController", "put"));

if (Session::get("user_id")) {
    $router->bind("/", array("OverviewController", "index"));
    $router->bind("/hosts", array("HostsController", "index"));
    $router->bind("/hosts/<id>/detail", array("HostsController", "detail"));
    $router->bind("/hosts/<id>/history", array("HostsController", "history"));
    $router->bind("/stamps", array("StampsController", "index"));
    $router->bind("/stamps/<id>", array("StampsController", "detail"));
    $router->bind("/packages", array("PackagesController", "index"));
    $router->bind("/logout", array("LoginController", "logout"));
} else {
    $router->bind("/", array("LoginController", "index"));
}

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
    } catch (EntityNotFound $e) {
        $ec = new ErrorController();
        echo $ec->error404();
    } catch (Throwable $t) {
        $ec = new ErrorController();
        echo $ec->error500($t);
    }
} else {
    // No route has been found, that is 404.
    $ec = new ErrorController();
    echo $ec->error404();
}
