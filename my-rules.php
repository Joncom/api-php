<?php

namespace My\Validation\Rules;

use Respect\Validation\Rules\AbstractRule;

class ValuesAreIntegers extends AbstractRule
{
    public function validate($input)
    {
        if(!is_array($input)) {
            return false;
        }
        foreach($input as $value) {
            if(!is_int($value)) {
                return false;
            }
        }
        return true;
    }
}

class ValuesAreBetween extends AbstractRule
{
    public $min;
    public $max;
    public function __construct($min, $max)
    {
        $this->min = $min;
        $this->max = $max;
    }
    public function validate($input)
    {
        if(!is_array($input)) {
            return false;
        }
        foreach($input as $value) {
            if($value < $this->min || $value > $this->max) {
                return false;
            }
        }
        return true;
    }
}
