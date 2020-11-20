<?php
namespace Greenbean\JsonValidator;

/*
Description:
Validateds JSON object's properties type and values based on an array $rules which specifies rules.
The following describes the single public method "object()"

Parameters:
$input. The JSON object (not associated array) to validate.
$rules.  The ruleset which the JSON object must follow.

Return value:
$input potentially sanitized

Return Errors:
Will be returned via a JsonValidatorException

Ruleset:  Specifies the type and value of each property in the JSON object, and is also a JSON object.

Types:
Supported types: string, integer, double and boolean, object (stdClass only), and arrays.
Note that double is used because of float since for historical reasons "double" is returned in case of a float, and not simply "float" (http://php.net/manual/en/function.gettype.php)
Multiple types for a single property are specified by using | (i.e. double|text) (and also are supprted for general objects and arrays?)
Objects are specifying using an associated array using name/rule for each element.
Sequential arrays are specified by a single element sequencial array which is used for all elements.
Arrays and objects can be recursive.
An asterisk "*" is for any type.
If a string type starts with an tilde "~", it is optional.
An object with any content is specified as an empty array [].
An sequntial array with any content is specified by ['*']
Object which implements JsonValidatorCallbackInterface (used for non-typical and complicated validation)

Exlusive Property Names:
If $arr[0] is either "||" (OR) or "|!" (XOR) and the array is not a sequencial array, it specifices that the JSON element must have properties with the names of the other elements, and normal rules apply.
Example: ['propertyName'=>['|*', 'choice1'=>'int', 'choice2'=>'string'] ]
Consider changing to make it an array key?
FUTURE Example: ['propertyName'=>['|*'=>['choice1'=>'int', 'choice2'=>'string'] ]]



## REMOVE: Special types "array", "object", "object|array", and "array|object" can be specified for the general type, and if so, will not be validated recursivly and value validation methods are limited to empty and notEmpty.

Values:
Value rules are specified by a validation method preceeded by a colon ":".
can be validated by changing the desired type from type to type:validation_method
Additional variables to include with the given validation_method can be specified by including a comma "," between them.
Multiple validation methods can be used and must be separated by && for AND or || for OR.
Order is based on 1) parenthese, 2) AND, and 3) OR.
! is used to negate.

Example:
['method'=>'string:max,5||someOtherMethod,123','params'=>['paramString'=>'(~string:ip&&minLengh,5)'],'extra'=>['name'=>'string','test'=>['string']]]
*/

class JsonValidator
{
    //Use provided options which can be applied either in constructor or validation method, but cannot be disabled once set.
    const SANITIZE      =   0b0001;   //Whether to sanitize (i.e. 'false' is changed to false)
    const STRICTMODE    =   0b0010;   //Currently only enfources that boolean is true/false (instead of 1/0 or "true"/"false")
    const SETDEFAULT    =   0b0100;   //If true, type double and value "nan" will be converted to 0.
    const CAMELIZE      =   0b1000;   //If true, will change from snake-type to camelType.

    const DELIMINATOR = '~';    //Internal use with object() for the replacement of parenthese content, but can be changed if conflicts with user data (not necessary?)
    private $methods,
    $options,
    $true, $false;  // $true and $false used to validate boolean

    static public function create(int $options=0, array $true=[], array $false=[]):self
    {
        return new self(new JsonValidatorMethods, $options, $true, $false);
    }

    static public function getOption(array $options):int
    {
        $o=0;
        foreach($options as $option) {
            if(!defined("self::$option")){
                throw new JsonValidatorErrorException((is_string($option)?$option:'given constant').' is not valid');
            }
            $o=$o|constant("self::$option");
        }
        return $o;
    }

    public function __construct(JsonValidatorMethods $methods, int $options=0, array $true=[], array $false=[])
    {
        $this->methods=$methods;
        $this->options=$options;
        $this->true=$true;
        $this->false=$false;
    }

    public function validate($input, $rules, int $options=0){

        $options=$options|$this->options;

        if( !is_array($rules) && !is_a($rules,'stdClass')) throw new JsonValidatorErrorException('Invalid rule provided.  Must be an array or stdClass object.');
        if( !is_array($input) && !is_a($input,'stdClass')) throw new JsonValidatorErrorException('Invalid input provided.  Must be an array or stdClass object.');
        if(!$input && !$rules) return $input;
        if(!$origArray=is_array($input)) {
            $input=json_decode(json_encode($input), true);
        }
        $rules=is_array($rules)?$this->validateRules($rules):$this->objectToArray($rules);
        $errors=$this->isSequencial($rules)
        ?$this->validateArray($input, $rules[0]??['*'], $options, 'base')
        :$this->validateObject($input, $rules, $options, 'base');
        if($errors) {
            throw new JsonValidatorException('Validation error', 1, null, $errors, $rules, $input);
        }
        return $origArray?$input:json_decode(json_encode($input, false));
    }

    // Used by this class and maybe by JsonValidatorCallbackInterface
    public function toBoolean($value) {
        //Will not convert non-key words to boolean
        if(in_array($value, $this->true)) {
            return true;
        }
        if(in_array($value, $this->false)) {
            return false;
        }
        return $value;
    }

    // Only used by JsonValidatorCallbackInterface
    public function getUnexpectedProperties(array $o, array $valid):?string
    {
        return ($extra = array_diff_key($o, array_flip($valid)))?'Unknown object properties: '.implode(', ', array_keys($extra)):null;
    }

    private function validateArray(array &$input, $rule, int $options, string $level):?string {
        $i=0;
        $errors=[];
        if(is_array($rule) && $this->isSequencial($rule)) {
            foreach($input as $index=>&$item) {
                if($index!==$i++) {
                    $errors[]="Array index $index in $level is not sequencial";
                }
                elseif(!is_array($item)) {
                    $errors[]="Item $index must be an array";
                }
                elseif($rule && $e=$this->validateArray($item, $rule[0]??['*'], $options, $level.'['.$index.']')) {
                    $errors[]=$e;
                }
            }
        }
        elseif(is_array($rule)) {
            foreach($input as $index=>&$item) {
                if($index!==$i++) {
                    $errors[]="Array index $index in $level is not sequencial";
                }
                elseif(!is_array($item)) {
                    $errors[]="Item $index must be an array";
                }
                elseif($e=$this->validateObject($item, $rule, $options, $level.'['.$index.']')) {
                    $errors[]=$e;
                }
            }
        }
        elseif($rule instanceof JsonValidatorCallbackInterface){
            foreach($input as $index=>&$item) {
                if($index!==$i++) {
                    $errors[]="Array index $index in $level is not sequencial";
                }
                elseif($e=$rule->validate($this, null, $item)) {
                    $errors[]=$e;
                }
            }
        }
        elseif(is_string($rule)) {
            if($rule[0]=='~') {
                throw new JsonValidatorErrorException('Optional tilde (~) not applicable to sequencial arrays');
            }
            if($rule[0]!=='*') {
                $rule=explode(':',$rule); //[0=>typeRule,1=>validationRule]
                $method=$rule[1]??null;
                foreach($input as $index=>&$item) {
                    if($index!==$i++) {
                        $errors[]="Array index $index in $level is not sequencial";
                    }
                    else {
                        try {
                            $input[$index]=$this->validateItem($rule[0], $method, $item, 'sequential array value', $options);
                        }
                        catch(JsonValidatorItemException $e) {
                            $errors[]=$e->getMessage();
                        }
                    }
                }
            }
            //else no validation
        }
        else {
            throw new JsonValidatorErrorException('Invalid rule.  Must be array, string, or JsonValidatorCallbackInterface');
        }
        return $errors?implode(', ',$errors):null;
    }

    private function validateObject(array &$input, $rules, int $options, string $level):?string{
        $errors=[];
        $customRuleObjects = [];
        foreach($rules as $prop=>$rule){
            if(!is_string($prop) || !$prop) {
                $errors[]="$prop for level $level must be an associated array";
            }
            elseif(is_array($rule)) {
                //How can I make sequential arrays and associated arrays optional?
                if(!isset($input[$prop])) {
                    $errors[]="$prop for level $level is missing";
                }
                elseif(!is_array($input[$prop])) {
                    $errors[]="$prop for level $level must be an array";
                }
                elseif($this->isSequencial($rule)) {
                    if($e = $this->validateArray($input[$prop], $rule[0]??['*'], $options, $level.'['.$prop.']')) {
                        $errors[]=$e;
                    }
                }
                elseif($e = $this->validateObject($input[$prop], $rule, $options, $level.'['.$prop.']')) {
                    $errors[]=$e;
                }
            }
            elseif($rule instanceof JsonValidatorCallbackInterface){
                //How can I make JsonValidatorCallbackInterface optional?
                if(!isset($input[$prop]) && mb_substr($prop, 0, 1)!=='~') {
                    $errors[]="$prop for level $level is missing";
                }
                else {
                    // Check after all other properties have been sanitized
                    $customRuleObjects[$prop]=$rule;
                }
            }
            elseif(is_string($rule)) {
                if($rule[0]==='~') {
                    if(!isset($input[$prop])){
                        break;
                    }
                    $rule=substr($rule, 1);
                }
                if(!isset($input[$prop])) {
                    $errors[]="$prop for level $level is missing";
                }
                elseif($rule[0]!=='*') {    //
                    $rule=explode(':',$rule); //[0=>typeRule,1=>validationRule]
                    try {
                        $input[$prop]=$this->validateItem($rule[0], $rule[1]??null, $input[$prop], $prop, $options);
                    }
                    catch(JsonValidatorItemException $e) {
                        $errors[]=$e->getMessage();
                    }
                }
            }
            else {
                throw new JsonValidatorErrorException('Invalid rule.  Must be array, string, or JsonValidatorCallbackInterface');
            }
        }
        foreach($customRuleObjects as $prop =>$customRuleObject) {
            if($e=$customRuleObject->validate($this, $prop, $input)) {
                $errors[]=$e;
            }
        }
        /*
        if(self::CAMELIZE) {
            $output = [];
            foreach($input as $key=>$value) {
                $output[$this->camelize($key)] = $value;
            }
            $input = $output;
        }
        */
        return $errors?implode(', ',$errors):null;
    }

    public function camelize(string $input, string $separator='_'):string
    {
        return str_replace($separator, '', lcfirst(ucwords($input, $separator)));
    }

    private function isBoolean($value):bool {
        return is_bool($value) || is_string($value)&&in_array(strtolower($value), array_merge($this->true, $this->false), true);
    }

    private function validateItem(string $requiredType, ?string $valueRule, $value, string $name, int $options) {
        if($requiredType[0]!=='*') { // * means any type, so skip (value validation not avaiable)
            $types=explode('|',$requiredType);
            if(count($types)>1) {
                //This happens before sanitation thus all types are strings, and must "guess" type
                if(in_array('integer', $types) && (is_int($value) || ctype_digit($value))){
                    $requiredType='integer';
                }
                elseif(in_array('boolean', $types) && $this->isBoolean($value) ){
                    $requiredType='boolean';
                }
                elseif(in_array('double', $types) && is_numeric($value)){
                    $requiredType='double';
                }
                elseif(in_array('string', $types) && is_string($value)){
                    $requiredType='string';
                }
                else{
                    syslog(LOG_ERR, "JsonValidator::validateItem():  Invalid type for $name");
                    $test = "requiredType $requiredType gettype ".gettype($value);
                    throw new JsonValidatorItemException("Invalid type for $name $test");
                }
            }
            if($options & self::SANITIZE) {
                $value=$this->sanitize($value, $requiredType, $options);
            }
            $type=gettype($value);
            if($requiredType==='timestamp') {
                $requiredType='string';
            }
            if( $type!==$requiredType && $this->strictBoolean($value, $requiredType, $options)) {
                throw new JsonValidatorItemException("property is a $type but should be a $requiredType.");
                $errors[]=$isArr
                ?"Sequential array value in the '$level' object is a $type but should be a $requiredType."
                :"Property '$prop' in the '$level' object is a $type but should be a $requiredType.";
            }
            elseif($valueRule) {
                $rs=$this->validateValue($value, $valueRule, $name);
                if(!$rs[0]) {
                    throw new JsonValidatorItemException($rs[1][0]);
                    return $rs[1][0];
                    $errors[]=$isArr
                    ?"Invalid value in the '$level' sequential array: ".$rs[1][0]
                    :"Invalid value for the '$prop' property in the '$level' object: ".$rs[1][0];
                }
            }
        }
        return $value;
    }

    private function validateValue($value, $ruleString, $prop)
    {
        //Store content in first tier parenthese and replace with delimitor
        if(strpos($ruleString, '(')) {
            preg_match_all('/\( ( (?: [^()]* | (?R) )* ) \)/x', $string, $match);   //$match[1] holds the results
            $ruleString=preg_replace('/\( ( (?: [^()]* | (?R) )* ) \)/x', self::DELIMINATOR, $ruleString);
            $i=0;   //index for placeholders with parenthese
        }

        $errors=[];
        foreach(explode('||',$ruleString) as $orString) {
            foreach(explode('&&',$orString) as $rule) {

                if(substr($rule,0,1)=='!') {
                    $not=true;
                    $rule=substr($rule,1);
                }
                else $not=false;

                if(substr($rule,0,1)===self::DELIMINATOR) {
                    syslog(LOG_ERR, 'JsonValidator::validateValue():  What does this do?');
                    $rs=$this->validateValue($value,$match[1][$i++],$prop);
                }
                else {
                    $rule=explode(',',$rule);
                    $method=$rule[0];
                    unset($rule[0]);
                    $rs=$this->methods->$method($value, array_values($rule), $prop);
                }

                $rs[0]=$rs[0]!==$not;
                if(!$rs[0]) {
                    $errors[]=$rs[1];
                    break; //If in an AND group, one false makes all false so no need to continue
                }
            }
            if($rs[0]) break;     //If in an OR group, one true makes all true so no need to continue
        }
        if($rs[0])$rs[1]=[];
        else {
            //FUTURE.  Revise to return all errors with NOT state, and assemble when complete so that NOTs can be cancelled out
            $errors=implode(', ',$errors);
            $rs[1]=["Value of ".json_encode($value)." violates ".($not?"NOT($errors)":$errors)];
        }
        return $rs;
    }

    private function validateRules(array $rules) {
        foreach($rules as $index => $rule) {
            if (is_array($rule)) {
                if($this->isSequencial($rule)) {
                    if($rule) {
                        if(is_array($rule[0])) {
                            $rules[$index] = [$this->validateRules($rule[0])];
                        }
                        elseif(!$rule[0] instanceOf JsonValidatorCallbackInterface) {
                            $rules[$index]=[str_replace(' ', '', $rule[0])];
                        }
                    }
                }
                else {
                    $rules[$index] = $this->validateRules($rule);
                }
            }
            elseif(!$rule instanceOf JsonValidatorCallbackInterface) {
                $rules[$index]=str_replace(' ', '', $rule);
            }
        }
        return $rules;
    }

    private function sanitize($value, string $type, int $options) {
        switch($type) {
            case 'object':case 'array':    //Not sanitized
                break;
            case 'string': case 'timestamp':    //timestamp will always be a string
                $value = trim($value);
                break;
            case 'integer':
                if(ctype_digit($value) || (!is_int($value) && self::SETDEFAULT&$options && $this->logStrictSanitize($value, $type))) {
                    $value=(int)$value;
                }
                break;
            case 'boolean':
                $value = $this->toBoolean($value);
                /*
                if($this->isBoolean($value) || (!is_bool($value) && self::SETDEFAULT&$options && $this->logStrictSanitize($value, $type))) {
                $value=filter_var($value, FILTER_VALIDATE_BOOLEAN);
                }
                */
                break;
            case 'double':
                if(!is_float($value)) {
                    if(($v=filter_var($value, FILTER_VALIDATE_FLOAT))===false) {
                        if(self::SETDEFAULT&$options){
                            $this->logStrictSanitize($value, $type);
                            $value=0.0; //Must use 0.0 or type cast as float.
                        }
                    }
                    else $value=$v;
                }
                break;
            default: throw new JsonValidatorErrorException("Invalid type '$type'");
        }
        return $value;
    }

    private function logStrictSanitize($value, string $type):bool{
        syslog(LOG_ERR, "JsonValidator::sanitize(value: $value, type: $type)");
        return true;
    }

    private function strictBoolean($value, string $rule, int $options):bool {
        //returns false only if not in STRICTMODE and testing boolean who's value is 0 or 1
        return self::STRICTMODE&$options || $rule!=='boolean' || !in_array($value,[0,1]);
    }

    //Converts stdClass to array and removes whitespace. Needs further testing.
    private function objectToArray(\stdClass $objRules) {
        $rules=[];
        foreach($objRules as $index => $rule) {
            if (is_array($rule)) {
                if(count($rule)>1) {
                    throw new JsonValidatorErrorException('Sequencial arrays may only have one or two elements.');
                }
                else {
                    $rules[$index] = $this->objectToArray((object)$rule);
                }
            }
            elseif($rule instanceOf \stdClass) {
                $rules[$index] = $this->objectToArray($rule);
            }
            elseif(!$rule instanceOf JsonValidatorCallbackInterface) {
                $rules[$index]=str_replace(' ', '', $rule);
            }
            elseif(is_string($index)) {
                $rules[$index]=$rule;
            }
            else {
                $rules=[$rule];
            }
        }
        return $rules;
    }

    //Tests whether array is sequencial or associative and validates sequential array.  Since input is developer provided and not user provided, is not foolproof, but fast
    private function isSequencial(array $arr):bool {
        if(empty($arr)) return true;
        if(isset($arr[0])) {
            if(count($arr)>1) throw new JsonValidatorErrorException('Sequencial arrays may only have one or two elements.');
            return true;
        }
        return false;
    }

}