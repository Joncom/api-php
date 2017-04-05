<?php

$app->get('/', function() use ($app) { list_resources('leaderboard'); });
$app->post('/', function() use ($app) { write_resource('insert', 'leaderboard'); });
$app->get('/:uuid', function($uuid) use ($app) { list_resource('leaderboard', 'uuid', $uuid); });
$app->post('/:uuid', function($uuid) use ($app) { write_resource('update', 'leaderboard', 'uuid', $uuid); });

// For some reason browsers send a preflight "OPTIONS" request
// before POST, and if code 200 is not returned then the POST is never sent.
$app->options('/', function() use ($app) {
    $app->render(200,array('message' => 'Preflight approved'));
});

$app->options('/:uuid', function($uuid) use ($app) {
    $app->render(200,array('message' => 'Preflight approved'));
});
