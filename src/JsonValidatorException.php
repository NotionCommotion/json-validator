<?php
namespace Greenbean\JsonValidator;
class JsonValidatorException extends \Exception
{
    // Supported codes Configuration error: 0, Validation error: 1
    private $errors, $blueprint, $input;

    public function __construct($message, $code=0, Exception $previous = null, $errors=false, $blueprint=false, $input=false) {
        $this->errors=$errors;
        $this->blueprint=$blueprint;
        $this->input=$input;
        parent::__construct($message, $code, $previous);
    }

    public function getError($sep=', ') {
        return is_array($this->errors)?implode($sep,$this->errors):$this->errors;
    }

    public function getErrorArray() {
        return (array) $this->errors;
    }

    public function getBlueprint() {
        return $this->blueprint;
    }

    public function getInput() {
        return $this->input;
    }
}
