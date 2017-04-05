<?php

// TODO: Conver to a "settings" ENUM and store everything here?
class FieldPermission {
    public static $NONE = 0;
    public static $READABLE = 1;
    public static $INSERTABLE = 2;
    public static $UPDATABLE = 4;
    public static $APPENDABLE = 8;
}

class Field {
    public $name;
    private $permissions;
    public $validator;
    public $prepared_statement_type;
    public $required_on_insert = FALSE;
    public $required_on_update = FALSE;
    public $required_on_append = FALSE;
    public $requires_auth_to_read = FALSE;
    public $requires_auth_to_insert = FALSE;
    public $requires_auth_to_update = FALSE;
    public $requires_auth_to_append = FALSE;
    public $generate_on_insert = FALSE;
    public $generate_on_update = FALSE;
    public $generate_on_append = FALSE;
    public $generator;
    public $encoder;
    public $decoder;

    function __construct($settings) {
        $this->name = $settings->name;
        if(property_exists($settings, 'permissions')) {
            $this->permissions = $settings->permissions;
        }
        if(property_exists($settings, 'validator')) {
            $this->validator = $settings->validator;
        }
        if(property_exists($settings, 'prepared_statement_type')) {
            $this->prepared_statement_type = $settings->prepared_statement_type;
        }
        if(property_exists($settings, 'required_on_insert')) {
            $this->required_on_insert = $settings->required_on_insert;
        }
        if(property_exists($settings, 'required_on_update')) {
            $this->required_on_update = $settings->required_on_update;
        }
        if(property_exists($settings, 'required_on_append')) {
            $this->required_on_append = $settings->required_on_append;
        }
        if(property_exists($settings, 'requires_auth_to_read')) {
            $this->requires_auth_to_read = $settings->requires_auth_to_read;
        }
        if(property_exists($settings, 'requires_auth_to_insert')) {
            $this->requires_auth_to_insert = $settings->requires_auth_to_insert;
        }
        if(property_exists($settings, 'requires_auth_to_update')) {
            $this->requires_auth_to_update = $settings->requires_auth_to_update;
        }
        if(property_exists($settings, 'requires_auth_to_append')) {
            $this->requires_auth_to_append = $settings->requires_auth_to_append;
        }
        if(property_exists($settings, 'generate_on_insert')) {
            $this->generate_on_insert = $settings->generate_on_insert;
        }
        if(property_exists($settings, 'generate_on_update')) {
            $this->generate_on_update = $settings->generate_on_update;
        }
        if(property_exists($settings, 'generate_on_append')) {
            $this->generate_on_append = $settings->generate_on_append;
        }
        if(property_exists($settings, 'generator')) {
            $this->generator = $settings->generator;
        }
        if(property_exists($settings, 'encoder')) {
            $this->encoder = $settings->encoder;
        }
        if(property_exists($settings, 'decoder')) {
            $this->decoder = $settings->decoder;
        }
    }

    public function is_readable() {
        return ($this->permissions & FieldPermission::$READABLE) > 0;
    }

    public function is_insertable() {
        return ($this->permissions & FieldPermission::$INSERTABLE) > 0;
    }

    public function is_updatable() {
        return ($this->permissions & FieldPermission::$UPDATABLE) > 0;
    }

    public function is_appendable() {
        return ($this->permissions & FieldPermission::$APPENDABLE) > 0;
    }
}
