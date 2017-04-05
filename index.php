<?php

// Version 1.3.0

// FIXME: Invaid route 404 error has "msg" instead of "message" property.

require 'vendor/autoload.php';
require_once 'config.php';
require_once 'my-rules.php';
require_once 'my-exceptions.php';

use Respect\Validation\Exceptions\NestedValidationException;

//ini_set('memory_limit', '1024M');
//ini_set('mysqli.reconnect', 'On');
//ini_set('post_max_size', '1000M');
//ini_set('upload_max_filesize', '900M');

$app = new \Slim\Slim();

$view = new \JsonApiView();
$view->encodingOptions |= JSON_PRETTY_PRINT;

$app->view($view);
$app->add(new \JsonApiMiddleware());

require_once 'mysql.php';

// TODO: Enforce that field names must be lowercase

// Allow full AJAX access for anybody, anywhere
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'resources.php';
require_once 'routes.php';

function generate_uuid() {
    $app = \Slim\Slim::getInstance();
    $result = $app->db->query('SELECT UUID() as uuid');
    if($result === FALSE) {
        $message = 'Failed to generate UUID';
        error_log('ERROR: ' . $message . ' // ' . mysqli_error($app->db));
        $app->render(500,array(
            'error' => TRUE,
            'message' => $message
        ));
    }
    $object = $result->fetch_object();
    $uuid = $object->uuid;
    return $uuid;
}

function generate_empty_array() {
    return array();
}

function generate_uploader_ip() {
    return $_SERVER['REMOTE_ADDR'];
}

function cast_to_int($value) {
    return (int) $value;
}

function string_to_utc_string($string) {
    $ts = strtotime($string);
    $ts_string = gmdate(DateTime::ISO8601, $ts);
    $ts_string = preg_replace('/\+0000$/', 'Z', $ts_string);
    return $ts_string;
}

// Needed to convert UTC time string back to local time
function string_to_local_time($string) {
    $ts = strtotime($string);
    return date("Y-m-d H:i:s", $ts);
}

function cast_to_bool($value) {
    return (bool) $value;
}

// TODO: Allow NULL value to be passed for insert/update?
// FIXME: Specifying a field name that does not exist
// in the database results in a 400 user input error!?

function get_fields($table) { // TODO: Rename to build_columns_sql
    $app = \Slim\Slim::getInstance();
    $fields = get_table_fields($table); // TODO: Rename to $table_fields

    // To be populated either from URL or automatically
    $field_names = array();

    // User requesting specific fields only?
    if(array_key_exists('fields', $_GET)) {

        // Prepare to override fields from user-input
        if(!preg_match('/^[a-zA-Z0-9_]+(,[a-zA-Z0-9_]+)*$/', $_GET['fields'])) {
            $app->render(400,array(
                'error' => TRUE,
                'message' => "The 'fields' value in the URL is malformed.",
            ));
        }

        $field_names = explode(',', $_GET['fields']);
        assert_fields_exist($table, $field_names);

        foreach($field_names as $field_name) {
            $readable = FALSE;
            foreach($fields as $field) {
                if($field->name === $field_name && $field->is_readable()) {
                    $readable = TRUE;
                    break;
                }
            }
            if(!$readable) {
                $app->render(400,array(
                    'error' => TRUE,
                    'message' => "The \"{$field_name}\" field is non-readable."
                ));
            }
        }
    } else {
        // By default, grab all readable fields.
        foreach($fields as $field) {
            if($field->is_readable()) {
                $field_names[] = $field->name;
            }
        }
        assert_fields_exist($table, $field_names);
    }

    // Hande auth gating
    foreach($field_names as $field_name) {
        foreach($fields as $field) {
            if($field->name === $field_name && $field->requires_auth_to_read) {
                if(!isset($_SERVER['PHP_AUTH_USER'])) {
                    header('WWW-Authenticate: Basic realm="My Realm"');
                    header('HTTP/1.0 401 Unauthorized');
                    $app->render(401,array(
                        'error' => TRUE,
                        'message' => "The \"{$field->name}\" field requires that you authenticate."
                    ));
                }
                if(
                    // TODO: Throw a 500 error if either environment variable is undefined
                    $_SERVER['PHP_AUTH_USER'] !== getenv("HTTP_AUTH_USERNAME") ||
                    $_SERVER['PHP_AUTH_PW'] !== getenv("HTTP_AUTH_PASSWORD")
                ) {
                    header('WWW-Authenticate: Basic realm="My Realm"');
                    header('HTTP/1.0 401 Unauthorized');
                    $app->render(401,array(
                        'error' => TRUE,
                        'message' => "The authentication creditials were incorrect."
                    ));
                }
                break;
            }
        }
    }
    
    return implode(', ', $field_names);
}

function get_sort($table) {
    $app = \Slim\Slim::getInstance();

    // TODO: Implment a default sort?

    if(!array_key_exists('sort', $_GET)) {
        return;
    }

    // Prepare to sort from user-input
    if(!preg_match('/^-?[a-zA-Z0-9_]+(,-?[a-zA-Z0-9_]+)*$/', $_GET['sort'])) {
        $app->render(400,array(
            'error' => TRUE,
            'message' => "The 'sort' value in the URL is malformed.",
        ));
    }

    $fields = explode(',', $_GET['sort']);
    for($i=0; $i<count($fields); $i++) {
        if(substr($fields[$i], 0, 1) === '-') {
            $fields[$i] = substr($fields[$i], 1);
        }
    }
    assert_fields_exist($table, $fields);

    $sorts = array();
    $fields = explode(',', $_GET['sort']);
    foreach ($fields as $field) {
        if(substr($field, 0, 1) === '-') {
            $sorts[] = substr($field, 1) . ' DESC';
        } else {
            $sorts[] = $field . ' ASC';
        }
    }

    return implode(', ', $sorts);
}

function get_filter($table) {
    $app = \Slim\Slim::getInstance();
    $default_filter = 'TRUE';

    $url_field_names = array();
    foreach ($_GET as $field => $value) {
        // Skip special keywords
        if($field === 'sort' || $field === 'fields' || $field === 'page' || $field === 'per_page') {
            continue;
        }
        $url_field_names[] = $field;
    }

    // Confirm URL field exists and is readable
    $table_fields = get_table_fields($table);
    foreach($url_field_names as $field_name) {
        $exists = false;
        $readable = false;
        foreach($table_fields as $table_field) {
            if($table_field->name === $field_name) {
                $exists = true;
                if($table_field->is_readable()) {
                    $readable = true;
                }
                break;
            }
        }
        if(!$exists || !$readable) {
            $app->render(400,array(
                'error' => TRUE,
                'message' => "The \"{$field}\" field in the URL is not allowed.",
            ));
        }
    }

    $filters = array();
    foreach ($url_field_names as $field_name) {
        $value = $_GET[$field_name];
        // Handle booleans and NULL
        if(strtoupper($value) === 'TRUE') {
            $value = TRUE;
        } else if(strtoupper($value) === 'FALSE') {
            $value = FALSE;
        } else if(strtoupper($value) === 'NULL') {
            $value = NULL;
        }
        foreach($table_fields as $field) {
            if($field->name === $field_name) {
                if($field->encoder) {
                    $function_name = $field->encoder;
                    if(!function_exists($function_name)) {
                        $message = "The \"{$field_name}\" field input parser could not be found.";
                        error_log('ERROR: ' . $message . ' // ' . mysqli_error($app->db));
                        $app->render(500,array(
                            'error' => TRUE,
                            'message' => $message
                        ));
                    }
                    $value = $function_name($value);
                }
                break;
            }
        }
        // FIXME: Should be using prepared statement ?'s for these!
        if(is_bool($value)) {
            $filters[] = "`{$field_name}` = " . ($value ? 'TRUE' : 'FALSE');
        } else if($value === NULL) {
            $filters[] = "`{$field_name}` IS NULL";
        } else {
            $filters[] = "`{$field_name}` = '{$value}'";
        }
    }

    if(count($filters) === 0) {
        return $default_filter;
    }

    return implode(' AND ', $filters);
}

function assert_fields_exist($table, $fields) {
    $app = \Slim\Slim::getInstance();
    $sql = "SELECT `COLUMN_NAME` as field FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '".MYSQL_DATABASE."' AND TABLE_NAME = '{$table}'";
    $result = $app->db->query($sql);
    if($result === FALSE) {
        $message = "Failed to fetch list of valid fields names for validation.";
        error_log('ERROR: ' . $message . ' // ' . mysqli_error($app->db));
        $app->render(500,array(
            'error' => TRUE,
            'message' => $message
        ));
    }
    $valid_fields = array();
    while($object = $result->fetch_object()) {
        $valid_fields[] = $object->field;
    }
    foreach($fields as $field) {
        if(!in_array($field, $valid_fields)) {
            $app->render(400,array(
                'error' => TRUE,
                'message' => "An invalid field '{$field}' was specified.",
            ));
        }
    }
}

function list_resources($table) {
    $app = \Slim\Slim::getInstance();
    $filter = get_filter($table);
    $sql = "SELECT count(*) AS count FROM `{$table}` WHERE {$filter};";
    $result = $app->db->query($sql);
    if($result === FALSE) {
        $message = 'The resource count needed for pagination could not be retrieved.';
        error_log('ERROR: ' . $message . ' // ' . mysqli_error($app->db));
        $app->render(500,array(
            'error' => TRUE,
            'message' => $message
        ));
    }
    $object = $result->fetch_object();
    $count = (int) $object->count;

    // FIXME: $count can be > 0 even when $page is rediculously high.
    // This results is "resources found" even though none are returned.

    if($count === 0) {
        $app->render(200,array(
            'message' => 'No resources were found.',
            'data' => array()
        ));
    }

    $page = 1;
    if(array_key_exists('page', $_GET)) {
        $page = (int) $_GET['page'];
        $page = max(1, $page);
    }

    $per_page = 100;
    $per_page_min = 1; // THIS VALUE MUST BE >= 1
    $per_page_max = 1000;
    if(array_key_exists('per_page', $_GET)) {
        // TODO: Validate that per_page is an int before casting
        // and throw an error response if not...
        $per_page = (int) $_GET['per_page'];
        $per_page = max($per_page_min, $per_page);
        $per_page = min($per_page_max, $per_page);
    }

    $last_page = (int) ceil($count / $per_page);

    // Build and set link header used for pagination
    $link_filter_pairs = array();
    foreach($_GET as $field => $value) {
        // Skip page because we build it ourselves
        if($field === 'page') {
            continue;
        }
        $link_filter_pairs[] = $field . '=' . $value;
    }
    $link_filters_string = implode('&', $link_filter_pairs);
    if(strlen($link_filters_string) > 0) {
        $link_filters_string .= '&';
    }
    $links = array();
    if($page !== $last_page) {
        $links[] = '<http://localhost/pia/api/?'.$link_filters_string.'page='.($page+1).'>; rel="next"';
        $links[] = '<http://localhost/pia/api/?'.$link_filters_string.'page='.($last_page).'>; rel="last"';
    }
    if($page !== 1) {
        $links[] = '<http://localhost/pia/api/?'.$link_filters_string.'page=1>; rel="first"';
    }
    if($page > 1) {
        $links[] = '<http://localhost/pia/api/?'.$link_filters_string.'page='.($page-1).'>; rel="prev"';
    }
    $app->response->headers->set('Link', implode(', ', $links));

    $fields = get_fields($table);
    $sort = get_sort($table);
    $offset = ($page - 1) * $per_page;
    $order_by = ($sort ? "ORDER BY {$sort}" : '');
    $sql = "SELECT {$fields} FROM `{$table}` WHERE {$filter} {$order_by} LIMIT {$offset},{$per_page};";
    $result = $app->db->query($sql);
    $data = array();
    while ($row = $result->fetch_object()) {
        $data[] = finalize_object($table, $row);
    }
    $app->render(200,array(
        // TODO: Might be cool if message said: "Some {$table} were found."
        // TODO: Might be cool if message included a resource count.
        'message' => 'Resources were found.',
        'data' => $data
    ));
}

function delete_resource($table, $key, $value) {
    $app = \Slim\Slim::getInstance();
    assert_resource_exists($table, $key, $value);
    $stmt = $app->db->prepare("DELETE FROM `{$table}` WHERE {$key} = ?");
    $stmt->bind_param('s', $value);
    if(!$stmt->execute()) {
        $message = 'A problem occurred while deleting the resource.';
        error_log('ERROR: ' . $message . ' // ' . mysqli_error($app->db));
        $app->render(500,array(
            'error' => TRUE,
            'message' => $message
        ));
    }
    $app->render(200,array(
        'message' => 'The resource was deleted.',
    ));
    $stmt->close();
    $app->db->close();
}

function assert_resource_exists($table, $key, $value) {
    $app = \Slim\Slim::getInstance();
    $sql = "SELECT COUNT(*) AS count FROM `{$table}` WHERE {$key} = ?";
    $stmt = $app->db->prepare($sql);
    $stmt->bind_param('s', $value);
    $stmt->execute();
    // FIXME: Throw an error if(!$stmt->execute())

    $stmt->bind_result($count);
    $fetch = $stmt->fetch();

    if($fetch === FALSE) {
        $message = 'The count needed to confirm that the resource exists could not be retrieved.';
        error_log('ERROR: ' . $message . ' // ' . mysqli_error($app->db));
        $app->render(500,array(
            'error' => TRUE,
            'message' => $message
        ));
    }
    if($count === 0) {
        $app->render(404,array(
            'error' => TRUE,
            'message' => 'The resource does not exist.',
        ));
    }
}

function list_resource($table, $key, $value) {
    $app = \Slim\Slim::getInstance();
    assert_resource_exists($table, $key, $value);

    $fields = get_fields($table);
    $stmt = $app->db->prepare("SELECT {$fields} FROM `{$table}` WHERE {$key} = ?");
    $stmt->bind_param('s', $value);
    $stmt->execute();
    // FIXME: Throw an error if(!$stmt->execute())
    
    // TODO: replace "get_result" with "bind_result" and "fetch" so mysqlnd driver is
    // not required, see "assert_resource_exists" for how this can be done
    $result = $stmt->get_result();
    
    $object = $result->fetch_object();
    $object = finalize_object($table, $object);

    $app->render(200,array(
        'message' => 'The resource was found.',
        'data' => $object
    ));

    $stmt->close();
    $app->db->close();
}

function assert_json_content_type() {
    $app = \Slim\Slim::getInstance();
    if(
        array_key_exists('CONTENT_TYPE', $_SERVER) &&
        !preg_match('/^application\/json/', $_SERVER['CONTENT_TYPE'])
    ) {
        $app->render(415,array(
            'error' => TRUE,
            'message' => 'Please send data as JSON'
        ));
    }
}

function assert_user_input_not_blank($input) {
    $app = \Slim\Slim::getInstance();
    if($input === '') {
        $app->render(400,array(
            'error' => TRUE,
            'message' => 'Expected data but received none'
        ));
    }
}

function assert_user_json_decoded_ok($data) {
    $app = \Slim\Slim::getInstance();
    if($data === NULL) {
        $app->render(400,array(
            'error' => TRUE,
            'message' => 'JSON string must be parsable and non-null'
        ));
    }
}

// TODO: Split this into two functions: create_resource / update_resource
function write_resource($action, $table, $key=NULL, $value=NULL) {
    $fields = get_table_fields($table);
    $app = \Slim\Slim::getInstance();

    assert_json_content_type();

    // TODO: Provide useful rate limit information headers.
    // http://www.vinaysahni.com/best-practices-for-a-pragmatic-restful-api#rate-limiting

    if($action === 'insert') {
        $max_per_minute = getenv("INSERTS_PER_IP_PER_MINUTE");
        $max_per_minute = ($max_per_minute !== FALSE) ? (int) $max_per_minute : 60;
        $ip = $_SERVER['REMOTE_ADDR'];
        $sql = "SELECT COUNT(*) AS `count` FROM `{$table}` WHERE `ip_address` = '{$ip}' AND `created_at` >= (NOW() - INTERVAL 1 MINUTE)";
        $result = $app->db->query($sql);
        if($result === FALSE) {
            $message = 'Failed to fetch recent insertion count.';
            error_log('ERROR: ' . $message . ' // ' . mysqli_error($app->db));
            $app->render(500,array(
                'error' => TRUE,
                'message' => $message
            ));
        }
        $object = $result->fetch_object();
        if($object->count >= $max_per_minute) {
            $app->render(400,array(
                'error' => TRUE,
                'message' => 'You are inserting too frequently. Please try again in a few moments.'
            ));
        }
    }

    if($action === 'update' || $action === 'append') {
        assert_resource_exists($table, $key, $value);
    }

    $input = file_get_contents('php://input');
    assert_user_input_not_blank($input);

    $data = json_decode($input);
    assert_user_json_decoded_ok($data); // FIXME: Use validator::json() instead

    assert_data_only_contains_defined_fields($table, $data);

    // TODO: Outright fail if no fields are writable.

    $prepared_statement_types = '';
    $foo_field_names = array(); // FIXME: Give these better names!
    $foo_field_values = array();

    foreach($fields as $field) {

        // Require authentication if necessary
        if(
            property_exists($data, $field->name) &&
            (
                ($action === 'insert' && $field->requires_auth_to_insert) ||
                ($action === 'update' && $field->requires_auth_to_update) ||
                ($action === 'append' && $field->requires_auth_to_append)
            )
        ) {
            if(!isset($_SERVER['PHP_AUTH_USER'])) {
                header('WWW-Authenticate: Basic realm="My Realm"');
                header('HTTP/1.0 401 Unauthorized');
                $app->render(401,array(
                    'error' => TRUE,
                    'message' => "The \"{$field->name}\" field requires that you authenticate."
                ));
            }
            if(
                // TODO: Throw a 500 error if either environment variable is undefined
                $_SERVER['PHP_AUTH_USER'] !== getenv("HTTP_AUTH_USERNAME") ||
                $_SERVER['PHP_AUTH_PW'] !== getenv("HTTP_AUTH_PASSWORD")
            ) {
                header('WWW-Authenticate: Basic realm="My Realm"');
                header('HTTP/1.0 401 Unauthorized');
                $app->render(401,array(
                    'error' => TRUE,
                    'message' => "The authentication creditials were incorrect."
                ));
            }
        }

        // Handle auto-generating of values
        if(
            ($action === 'insert' && $field->generate_on_insert) ||
            ($action === 'update' && $field->generate_on_update) ||
            ($action === 'append' && $field->generate_on_append)
        ) {

            // Ensure user did not provide a field that gets auto-generated
            if(property_exists($data, $field->name)) {
                $app->render(400,array(
                    'error' => TRUE,
                    'message' => "The \"{$field->name}\" field is not allowed."
                ));
            }

            // Ensure generator exists if we're going to use one
            if($field->generator === NULL) {
                $message = "The \"{$field->name}\" field has no generator.";
                error_log('ERROR: ' . $message . ' // ' . mysqli_error($app->db));
                $app->render(500,array(
                    'error' => TRUE,
                    'message' => $message
                ));
            }

            // Generate value
            $function_name = $field->generator;
            $field_name = $field->name; // XXX: Wow, why is reassigning needed!?
            $data->$field_name = $function_name();
        }

        // Check that required field is present
        $field_is_required = (
            ($action === 'insert' && $field->required_on_insert) ||
            ($action === 'update' && $field->required_on_update) ||
            ($action === 'append' && $field->required_on_append)
        );
        if($field_is_required && !property_exists($data, $field->name)) {
            $app->render(400,array(
                'error' => TRUE,
                'message' => "The \"{$field->name}\" field is required."
            ));
        }

        if(property_exists($data, $field->name)) {

            // Check that the field has write persmission
            $field_is_writable = (
                ($action === 'insert' && $field->is_insertable()) ||
                ($action === 'update' && $field->is_updatable()) ||
                ($action === 'append' && $field->is_appendable())
            );
            if(!$field_is_writable) {
                $app->render(400,array(
                    'error' => TRUE,
                    'message' => "The \"{$field->name}\" field is not allowed."
                ));
            }

            // Validate field value
            $field_name = $field->name;
            try {
                $field->validator->assert($data->$field_name);
            } catch(NestedValidationException $exception) {
                $app->render(400,array(
                    'error' => TRUE,
                    'message' => "The \"{$field_name}\" field failed validation. " . implode('. ',$exception->getMessages()) . '.'
                ));
            }

            // If appending, merge existing data from DB with new data
            // TODO: Add support for appending more than just "actions"
            if($action === 'append' && $field_name === 'actions') {

                $sql = "SELECT `actions`, ROUND( TIMESTAMPDIFF( MICROSECOND, `created_at`, CURRENT_TIMESTAMP(6) ) / 1000) as `ms_since_created`, ROUND( TIMESTAMPDIFF( MICROSECOND, `updated_at`, CURRENT_TIMESTAMP(6) ) / 1000) as `ms_since_updated` FROM {$table} WHERE `{$key}` = '{$value}'";
                $result = $app->db->query($sql);
                if($result === FALSE) {
                    $message = 'Failed to fetch resource to append to.';
                    // FIXME: These error_log calls should be moved into
                    // the middleware and written once instead of a
                    // a billion times!
                    error_log('ERROR: ' . $message . ' // ' . mysqli_error($app->db));
                    $app->render(500,array(
                        'error' => TRUE,
                        'message' => $message
                    ));
                }
                $object = $result->fetch_object();

                $latencyGraceInSeconds = 2;
                $expectedSecondsSinceUpdated = 1.5; // game hardcoded upload interval

                // No "minimum" necessary because checking that
                // `actions` has a sane number of values relative
                // to `created_at` should make abusive appending
                // a rather obsure task. Actually, TODO: add a
                // minimum `actions` array length vadidator on
                // append. That should take care of it.
                $maxSecondsSinceUpdated = $expectedSecondsSinceUpdated + $latencyGraceInSeconds;

                $secondsSinceUpdated = $object->ms_since_updated / 1000;
                if($secondsSinceUpdated > $maxSecondsSinceUpdated) {
                    $app->render(400,array(
                        'error' => TRUE,
                        'message' => "It's been {$secondsSinceUpdated} seconds since receiving the last update, but the next update needed to arrive within {$maxSecondsSinceUpdated} seconds."
                    ));
                }

                $old_actions_array = json_decode($object->actions);
                if(!is_array($old_actions_array)) {
                    $message = "The \"{$field_name}\" field could not be appended to because the pre-existing data is not an array.";
                    error_log('ERROR: ' . $message . ' // ' . mysqli_error($app->db));
                    $app->render(500,array(
                        'error' => TRUE,
                        'message' => $message
                    ));
                }

                $data->actions = array_merge($old_actions_array, $data->actions);

                $fps = 60;
                $minAllowableFPS = $fps - 3;
                $maxAllowableFPS = $fps + 3;

                $secondsSinceCreated = $object->ms_since_created / 1000;
                $minFrameCount = round($secondsSinceCreated * $minAllowableFPS);
                $maxFrameCount = round($secondsSinceCreated * $maxAllowableFPS);
                $actualFrameCount = count($data->actions);

                $latencyGraceInFrames = round($latencyGraceInSeconds * $fps);
                $minFrameCount -= $latencyGraceInFrames;

                $estimatedFramesPerSecond = round($actualFrameCount / $secondsSinceCreated);

                if($actualFrameCount < $minFrameCount) {
                    $app->render(400,array(
                        'error' => TRUE,
                        'message' => "Your estimated frame rate is {$estimatedFramesPerSecond}, which is too low."
                    ));
                }

                if($actualFrameCount > $maxFrameCount) {
                    $app->render(400,array(
                        'error' => TRUE,
                        'message' => "Your estimated frame rate is {$estimatedFramesPerSecond}, which is too high."
                    ));
                }
            }

            // Build data for prepared statement
            $prepared_statement_types .= $field->prepared_statement_type;
            $foo_field_names[] = $field->name;
            $field_name = $field->name;
            $field_value = $data->$field_name;
            if($field->encoder) {
                $function_name = $field->encoder;
                // FIXME: This check is duplicate code
                if(!function_exists($function_name)) {
                    $message = "The \"{$field_name}\" field input parser could not be found.";
                    error_log('ERROR: ' . $message . ' // ' . mysqli_error($app->db));
                    $app->render(500,array(
                        'error' => TRUE,
                        'message' => $message
                    ));
                }
                $field_value = $function_name($field_value);
            }
            $foo_field_values[] = $field_value;
        }
    }

    // The receiving function requires params by reference, not value.
    $params = [];
    for($i=0; $i<count($foo_field_values); $i++) {
        $params[] = &$foo_field_values[$i];
    }

    if($action === 'update' || $action === 'append') {
        $params[] = &$value;
        $prepared_statement_types .= 's'; // FIXME: use actual field object?
        $stmt_str = build_update_statement_sql($table, $key, $foo_field_names);
    } else if($action === 'insert') {
        $stmt_str = build_insert_statement_sql($table, $foo_field_names);
    }

    $stmt = $app->db->prepare($stmt_str);
    if($stmt === FALSE) {
        $message = 'A problem occurred while preparing the statement.';
        error_log('ERROR: ' . $message . ' // ' . mysqli_error($app->db));
        $app->render(500,array(
            'error' => TRUE,
            'message' => $message
        ));
    }

    call_user_func_array(array($stmt, "bind_param"), array_merge(array($prepared_statement_types), $params));
    if(!$stmt->execute()) {
        $message = 'A problem occurred while binding prepared statement parameters.';
        error_log('ERROR: ' . $message . ' // ' . mysqli_error($app->db));
        $app->render(500,array(
            'error' => TRUE,
            'message' => $message
        ));
    }
    $stmt->close();

    // FIXME: Seems sloppy and brittle
    if($action === 'insert') {
        $key = 'uuid';
        $value = $data->uuid;
    }

    // FIXME: This leaks "non-readable" fields.
    $result = $app->db->query("SELECT * FROM `{$table}` WHERE `{$key}` = '{$value}'");
    if($result === FALSE) {
        $message = 'Failed to fetch latest copy of resource.';
        error_log('ERROR: ' . $message . ' // ' . mysqli_error($app->db));
        $app->render(500,array(
            'error' => TRUE,
            'message' => $message
        ));
    }
    $object = $result->fetch_object();
    $object = finalize_object($table, $object);

    // TODO: "In case of a POST that resulted in a creation,
    // ...include a Location header that points to the URL
    // of the new resource."
    // http://www.vinaysahni.com/best-practices-for-a-pragmatic-restful-api#useful-post-responses

    $message = 'The resource was ' . ($action === 'insert' ? 'inserted' : 'updated') . ' successfully.';
    $code = ($action === 'insert' ? 201 : 200);
    $app->render($code,array(
        'message' => $message,
        'data' => $object
    ));

    $app->db->close();
}

function build_insert_statement_sql($table, $field_names) {
    function wrap($str) {
        return "`{$str}`";
    }
    $columns = array_map('wrap', $field_names);
    $columns = implode(', ', $columns);
    $values = array();
    for($i=0; $i<count($field_names); $i++) {
        $values[] = '?';
    }
    $values = implode(', ', $values);
    return "INSERT INTO `{$table}` ({$columns}) VALUES ({$values});";
}

function build_update_statement_sql($table, $key, $field_names) {
    $set = array();
    for($i=0; $i<count($field_names); $i++) {
        $field_name = $field_names[$i];
        $set[] = "`{$field_name}` = ?";
    }
    $set = implode(', ', $set);
    return "UPDATE `{$table}` SET {$set} WHERE `{$key}` = ?;";
}

// Ensure that data does not contain non-existent fields.
function assert_data_only_contains_defined_fields($table, $data) {
    $fields = get_table_fields($table);
    $app = \Slim\Slim::getInstance();
    foreach($data as $field_name => $value) {
        $exists = FALSE;
        foreach($fields as $field) {
            if($field->name === $field_name) {
                $exists = TRUE;
                break;
            }
        }
        if(!$exists) {
            $app->render(400,array(
                'error' => TRUE,
                'message' => "The \"{$field_name}\" field does not exist. Check your spelling."
            ));
        }
    }
}

function get_table_fields($table) {
    global $tables;
    $app = \Slim\Slim::getInstance();
    if(!array_key_exists($table, $tables) || !is_array($tables[$table])) {
        $message = "The \"{$table}\" table has no fields.";
        error_log('ERROR: ' . $message . ' // ' . mysqli_error($app->db));
        $app->render(500,array(
            'error' => TRUE,
            'message' => $message
        ));
    }
    $fields = $tables[$table];
    return $fields;
}

// Finalize output by calling decoder function for fields that have it
function finalize_object($table, $object) {
    $fields = get_table_fields($table);
    foreach($fields as $field) {
        if(property_exists($object, $field->name)) {
            if($field->decoder) {
                // TODO: Throw an error if the function cannot be found. See existing example.
                $function_name = $field->decoder;
                $field_name = $field->name;

                // "NULL" is the only exception which we never decode.
                if(is_null($object->$field_name)) {
                    continue;
                }

                $object->$field_name = $function_name($object->$field_name);
            }
        }
    }
    return $object;
}

$app->run();
