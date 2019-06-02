<?php
namespace Greenbean\JsonValidator;
interface JsonValidatorCallbackInterface{
    public function validate(JsonValidator $validator, \stdClass $input, array &$errors=[]):JsonValidator;
}
