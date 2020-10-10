<?php
namespace Greenbean\JsonValidator;
/**
* Accept all fields in an array object which is passed by referrence and return array of errors.
* $prop can either be the property name if applied to a specific property or some fake property name which starts with "~" and isn't used.
*/
interface JsonValidatorCallbackInterface{
    public function validate(JsonValidator $validator, $prop, array &$input):array;
}
