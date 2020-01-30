<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('/routes', "RouteController@list");
$router->get('/routes/search', "RouteController@searchRoute");
$router->get('/routes/{id}', "RouteController@getRoute");
$router->get('/routes/{id}/next-stops', "RouteController@listStopsByRouteAndTimestamp");
$router->get('/stops/search', "StopController@searchStop");
$router->get('/stops/{id}', "StopController@getStopById");
$router->get('/stops/{id}/next-routes', "StopController@listRoutesByStopIdAndTimestamp");
$router->get("/agencies", "AgencyController@listAgencies");