<?php

namespace My\Validation\Exceptions;

use Respect\Validation\Exceptions\ValidationException;

class ValuesAreIntegersException extends ValidationException
{
    public static $defaultTemplates = [
        self::MODE_DEFAULT => [
            self::STANDARD => '{{name}} values must be integers',
        ],
        self::MODE_NEGATIVE => [
            self::STANDARD => '{{name}} values must not be integers',
        ],
    ];
}

class ValuesAreBetweenException extends ValidationException
{
    public static $defaultTemplates = [
        self::MODE_DEFAULT => [
            self::STANDARD => '{{name}} values must be between {{min}} and {{max}}',
        ],
        self::MODE_NEGATIVE => [
            self::STANDARD => '{{name}} values must not be between {{min}} and {{max}}',
        ],
    ];
}
