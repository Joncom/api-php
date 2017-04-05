<?php

date_default_timezone_set('UTC');

// HTTP Authentication
putenv("HTTP_AUTH_USERNAME=admin");
putenv("HTTP_AUTH_PASSWORD=password");

// Database
$url = parse_url('mysql://root@localhost/database'); // parse_url(getenv("DATABASE_URL"));
define('MYSQL_HOST', $url["host"]);
define('MYSQL_USER', $url["user"]);
define('MYSQL_PASSWORD', (isset($url["pass"]) ? $url["pass"] : ''));
define('MYSQL_DATABASE', substr($url["path"], 1));
