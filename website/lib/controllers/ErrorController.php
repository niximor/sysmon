<?php

require_once "controllers/TemplatedController.php";

class ErrorController extends TemplatedController {
    public function error404(...$params) {
        http_response_code(404);
        return $this->renderTemplate("error404.html");
    }

    public function error500(Throwable $t) {
        http_response_code(500);

        $this->twig->addFunction(new Twig_Function("get_class", function($obj) { return get_class($obj); }));

        return $this->renderTemplate("error500.html", ["error" => $t]);
    }
}
