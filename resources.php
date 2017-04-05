<?php

require_once 'classes/field.php';

use Respect\Validation\Validator as v;
v::with('My\\Validation\\Rules\\');

$tables = array(
    'leaderboard' => array(
        new Field((object) array(
            'name' => 'uuid',
            'permissions' => FieldPermission::$READABLE | FieldPermission::$INSERTABLE,
            'prepared_statement_type' => 's',
            'required_on_insert' => TRUE,
            'generate_on_insert' => TRUE,
            'generator' => 'generate_uuid',
            'validator' => v::stringType() // FIXME: Validate syntax
        )),
        new Field((object) array(
            'name' => 'name',
            'permissions' => (FieldPermission::$READABLE | FieldPermission::$INSERTABLE | FieldPermission::$UPDATABLE),
            'prepared_statement_type' => 's',
            'required_on_insert' => FALSE,
            'requires_auth_to_update' => TRUE,
            'validator' => v::stringType()->length(0,64)
        )),
        new Field((object) array(
            'name' => 'user_id',
            'permissions' => (FieldPermission::$READABLE | FieldPermission::$INSERTABLE),
            'prepared_statement_type' => 's',
            'required_on_insert' => TRUE,
            'requires_auth_to_read' => TRUE,
            'validator' => v::stringType()->length(36) // FIXME: Validate syntax
        )),
        new Field((object) array(
            'name' => 'email',
            'permissions' => (FieldPermission::$READABLE | FieldPermission::$INSERTABLE),
            'prepared_statement_type' => 's',
            'required_on_insert' => FALSE,
            'requires_auth_to_read' => TRUE,
            'encoder' => 'strtolower', // always store emails as lowercase
            'validator' => v::email()
        )),
        new Field((object) array(
            'name' => 'score',
            'permissions' => (FieldPermission::$READABLE | FieldPermission::$INSERTABLE),
            'prepared_statement_type' => 'i',
            'required_on_insert' => TRUE,
            'validator' => v::intType(),
            'decoder' => 'cast_to_int'
        )),
        new Field((object) array(
            // XXX: Required on insertion, but handled automatically by database, not API...
            'name' => 'created_at',
            'permissions' => FieldPermission::$READABLE,
            'decoder' => 'string_to_utc_string'
        )),
        new Field((object) array(
            // XXX: Required on insertion, but handled automatically by database, not API...
            'name' => 'updated_at',
            'permissions' => FieldPermission::$READABLE,
            'decoder' => 'string_to_utc_string'
        )),
        new Field((object) array(
            'name' => 'visible',
            'permissions' => (FieldPermission::$READABLE | FieldPermission::$UPDATABLE),
            'prepared_statement_type' => 'i',
            'validator' => v::boolType(),
            'decoder' => 'cast_to_bool'
        )),
        new Field((object) array(
            'name' => 'ip_address',
            'permissions' => FieldPermission::$INSERTABLE,
            'prepared_statement_type' => 's',
            'required_on_insert' => TRUE,
            'generate_on_insert' => TRUE,
            'generator' => 'generate_uploader_ip',
            'validator' => v::stringType() // TODO: Validate syntax?
        )),
    )
);
