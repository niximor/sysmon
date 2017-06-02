<?php

$tm_start = microtime(true);

ini_set("display_errors", true);
ini_set("display_startup_errors", true);

error_reporting(E_ALL);

require_once "lib/common.php";

require_once "exceptions/EntityNotFound.php";
require_once "models/Session.php";
require_once "fastrouter/FastRouter.php";

require_once "controllers/ErrorController.php";
require_once "controllers/LoginController.php";

$router = new \nixfw\fastrouter\FastRouter();

// API
require_once "controllers/StampsController.php";
$router->bind("/stamps/put/<hostname>/<stamp>", array("StampsController", "put"));
$router->bind("/stamps/put/<stamp>", array("StampsController", "put_nohost"));

require_once "controllers/ChecksController.php";
$router->bind("/checks/list/<hostname>", array("ChecksController", "list"));
$router->bind("/checks/put", array("ChecksController", "put"));

require_once "controllers/HostsController.php";
$router->bind("/hosts/list/<hostname>", array("HostsController", "list"));

// Website
if (Session::get("user_id")) {
    require_once "controllers/OverviewController.php";
    $router->bind("/", array("OverviewController", "index"));
    $router->bind("/alerts/dismiss/<id>", array("OverviewController", "dismiss"));

    $router->bind("/hosts", array("HostsController", "index"));
    $router->bind("/hosts/add", array("HostsController", "add"));
    $router->bind("/hosts/list/<hostname>", array("HostsController", "list"));
    $router->bind("/hosts/<id>/edit", array("HostsController", "edit"));
    $router->bind("/hosts/<id>/remove", array("HostsController", "remove"));
    $router->bind("/hosts/<id>/detail", array("HostsController", "detail"));
    $router->bind("/hosts/<id>/history", array("HostsController", "history"));
    $router->bind("/hosts/<id>/charts", array("HostsController", "charts"));

    require_once "controllers/StampsController.php";
    $router->bind("/stamps", array("StampsController", "index"));
    $router->bind("/stamps/add", array("StampsController", "add"));
    $router->bind("/stamps/<id>", array("StampsController", "detail"));
    $router->bind("/stamps/<id>/punchcard", array("StampsController", "punchcard"));

    require_once "controllers/PackagesController.php";
    $router->bind("/packages", array("PackagesController", "index"));

    require_once "controllers/ChecksController.php";
    $router->bind("/checks", array("ChecksController", "overview"));
    $router->bind("/checks/list", array("ChecksController", "index"));
    $router->bind("/checks/add", array("ChecksController", "add"));
    $router->bind("/checks/groups/<group_id>", array("ChecksController", "group_detail"));
    $router->bind("/checks/<id>/edit", array("ChecksController", "edit"));
    $router->bind("/checks/<id>/toggle", array("ChecksController", "toggle"));
    $router->bind("/checks/<id>/remove", array("ChecksController", "remove"));
    $router->bind("/checks/<id>/charts", array("ChecksController", "charts"));
    $router->bind("/checks/<id>", array("ChecksController", "detail"));
    $router->bind("/checks/<check_id>/charts/<chart_id>", array("ChecksController", "chart_detail"));
    $router->bind("/checks/<id>/chart-data/<chart_id>", array("ChecksController", "chart_data"));

    require_once "controllers/AlertTemplatesController.php";
    $router->bind("/settings/alert-templates", array("AlertTemplatesController", "index"));
    $router->bind("/settings/alert-templates/add", array("AlertTemplatesController", "add"));
    $router->bind("/settings/alert-templates/edit/<id>", array("AlertTemplatesController", "edit"));
    $router->bind("/settings/alert-templates/remove/<id>", array("AlertTemplatesController", "remove"));

    require_once "controllers/CheckChartsController.php";
    $router->bind("/settings/check-charts", array("CheckChartsController", "index"));
    $router->bind("/settings/check-charts/add", array("CheckChartsController", "add"));
    $router->bind("/settings/check-charts/edit/<id>", array("CheckChartsController", "edit"));
    $router->bind("/settings/check-charts/remove/<id>", array("CheckChartsController", "remove"));

    require_once "controllers/CheckTypesController.php";
    $router->bind("/settings/check-types", array("CheckTypesController", "index"));
    $router->bind("/settings/check-types/add", array("CheckTypesController", "add"));
    $router->bind("/settings/check-types/edit/<id>", array("CheckTypesController", "edit"));
    $router->bind("/settings/check-types/remove/<id>", array("CheckTypesController", "remove"));

    require_once "controllers/UsersController.php";
    $router->bind("/settings/users/", ["UsersController", "index"]);
    $router->bind("/settings/users/add", ["UsersController", "add"]);
    $router->bind("/settings/users/<id>", ["UsersController", "detail"]);
    $router->bind("/settings/users/<id>/edit", ["UsersController", "edit"]);
    $router->bind("/settings/users/<id>/remove", ["UsersController", "remove"]);

    require_once "controllers/ActionsController.php";
    $router->bind("/settings/actions/", ["ActionsController", "index"]);
    $router->bind("/settings/actions/add", ["ActionsController", "add"]);
    $router->bind("/settings/actions/edit/<id>", ["ActionsController", "edit"]);
    $router->bind("/settings/actions/remove/<id>", ["ActionsController", "remove"]);

    require_once "controllers/RolesController.php";
    $router->bind("/settings/roles/", ["RolesController", "index"]);
    $router->bind("/settings/roles/add/", ["RolesController", "add"]);
    $router->bind("/settings/roles/<id>/", ["RolesController", "detail"]);
    $router->bind("/settings/roles/<id>/edit", ["RolesController", "edit"]);
    $router->bind("/settings/roles/<id>/remove", ["RolesController", "remove"]);

    require_once "controllers/HelpController.php";
    $router->bind("/help/", array("HelpController", "index"));
    $router->bind("/help/add-topic", array("HelpController", "add"));
    $router->bind("/help/edit-topic/<id>", array("HelpController", "edit"));
    $router->bind("/help/remove-topic/<id>", array("HelpController", "remove"));
    $router->bind("/help/get/<topic>", array("HelpController", "get"));
    $router->bind("/help/<topic>", array("HelpController", "topic"));

    require_once "controllers/ProfileController.php";
    $router->bind("/profile", array("ProfileController", "index"));
    $router->bind("/profile/revoke/<session>", array("ProfileController", "revoke"));
    $router->bind("/profile/change-password", array("ProfileController", "change_password"));
    $router->bind("/profile/notifications", array("ProfileController", "notifications"));

    require_once "controllers/SettingsController.php";
    $router->bind("/settings/config", array("SettingsController", "config"));

    require_once "controllers/LoginController.php";
    $router->bind("/logout", array("LoginController", "logout"));
} else {
    require_once "controllers/LoginController.php";
    $router->bind("/", array("LoginController", "index"));

    require_once "controllers/HelpController.php";
    $router->bind("/help/", array("HelpController", "index"));
    $router->bind("/help/get/<topic>", array("HelpController", "get"));
    $router->bind("/help/<topic>", array("HelpController", "topic"));
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
        echo $ec->error404($e);
    } catch (AccessDenied $e) {
        $ec = new ErrorController();
        echo $ec->error403($e);
    } catch (Throwable $t) {
        $ec = new ErrorController();
        echo $ec->error500($t);
    }
} else {
    // No route has been found, that is 404.
    $ec = new ErrorController();
    echo $ec->error404();
}