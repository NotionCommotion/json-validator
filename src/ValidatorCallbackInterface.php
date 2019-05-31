<?php
namespace Greenbean\JsonValidator;
interface ValidatorCallbackInterface{
    public function validate(JsonValidator $validator, \stdClass $input, array &$errors=[]):JsonValidator;
}
