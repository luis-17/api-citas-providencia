<?php
// Application middleware
use \Slim\Middleware\JwtAuthentication;
// e.g: $app->add(new \Slim\Csrf\Guard);
$app->add(new JwtAuthentication([
    "path" => "/api", /* or ["/api", "/admin"] */
    "attribute" => "decoded_token_data",
    "secret" => "9Q?6p1jqjl050I#f@2L6l6zi",
    "algorithm" => ["HS256"],
    "error" => function ($response, $arguments) {
        $data["status"] = "error";
        $data["message"] = $arguments["message"];
        return $response
            ->withHeader("Content-Type", "application/json")
            ->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
]));

$checkProxyHeaders = true;
// $trustedProxies = [];
$trustedProxies = ['10.0.0.1', '10.0.0.2'];
$app->add(new RKA\Middleware\IpAddress($checkProxyHeaders, $trustedProxies));