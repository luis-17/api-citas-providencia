<?php
use \Slim\Middleware\JwtAuthentication;

$app->add(new JwtAuthentication([
    "path" => "/api/platform", /* or ["/api", "/admin"] */
    "attribute" => "decoded_token_data",
    "secret" => "9Q?6p1jqjl050I#f@2L6l6zi",
    "algorithm" => ["HS256"],
    "secure" => false,
    "error" => function ($request,$response,$arguments) {
        // echo $arguments;
        $data["status"] = "error";
        $data["message"] = "Token inválido. Vuelva a iniciar sesión."; // $arguments["message"];
        return $response
            ->withHeader("Content-Type", "application/json")
            ->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
]));

$checkProxyHeaders = true;
// $trustedProxies = [];
$trustedProxies = ['10.0.0.1', '10.0.0.2'];
$app->add(new RKA\Middleware\IpAddress($checkProxyHeaders, $trustedProxies));
