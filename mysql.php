<?php

require_once('config.php');

$app->hook('slim.before.router', function () use ($app) {
    mysqli_report(MYSQLI_REPORT_STRICT);
    try {
         $connection = new mysqli(MYSQL_HOST, MYSQL_USER, MYSQL_PASSWORD, MYSQL_DATABASE);
    } catch (Exception $e ) {
        $app = \Slim\Slim::getInstance();
        $message = 'Could not connect to the database';
        error_log('ERROR: ' . $message . ' // ' . mysqli_connect_error());
        $app->render(500,array(
            'error' => TRUE,
            'message' => $message
        ));
    }
    $app->db = $connection;
});
