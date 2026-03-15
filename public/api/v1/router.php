<?php

require_once 'vendor/autoload.php';

use FastRoute\Router;

$router = new Router();

// Customer Management Endpoints
$router->addRoute('POST', '/customers', 'CustomerController::create');
$router->addRoute('GET', '/customers', 'CustomerController::getAll');
$router->addRoute('GET', '/customers/{id}', 'CustomerController::getById');
$router->addRoute('PUT', '/customers/{id}', 'CustomerController::update');
$router->addRoute('DELETE', '/customers/{id}', 'CustomerController::delete');

// Bulk Messaging Endpoints
$router->addRoute('POST', '/messages/bulk', 'MessageController::sendBulkMessages');

// Analytics Endpoints
$router->addRoute('GET', '/analytics/customers', 'AnalyticsController::getCustomerAnalytics');

// Dispatching the routes
$httpMethod = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

$routeInfo = $router->dispatch($httpMethod, $uri);

switch ($routeInfo[0]) {
    case Router::NOT_FOUND:
        header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
        break;
    case Router::METHOD_NOT_ALLOWED:
        header($_SERVER['SERVER_PROTOCOL'] . ' 405 Method Not Allowed');
        break;
    case Router::FOUND:
        $handler = $routeInfo[1];
        $vars = $routeInfo[2];
        // Call the handler with the variables
        // Assuming the handler is a callable in the format ClassName::method
        list($class, $method) = explode('::', $handler);
        if (class_exists($class) && method_exists($class, $method)) {
            call_user_func_array([new $class(), $method], $vars);
        }
}

?>