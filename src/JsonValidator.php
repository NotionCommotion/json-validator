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
Multiple types for a single property are not supported (except for general objects and arrays).
Objects are specifying using an associated array using name/rule for each element.
Sequential arrays are specified by a single element sequencial array which is used for all elements.
Arrays and objects can be recursive.
An asterisk "*" is for any type.
If a string type starts with an tilde "~", it is optional.
An object with any content is specified as an empty array [].
An sequntial array with any content is specified by ['*']
Object which implements JsonValidatorCallbackInterface (used for non-typical and complicated validation)


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
    const DELIMINATOR = '~';    //Internal use with object() for the replacement of parenthese content, but can be changed if conflicts with user data (not necessary?)
    private $strictMode=true;   //Currently only enfources that boolean is true/false
    private $sanitize=false;    //Whether to sanitize (i.e. 'false' is changed to false)
    private $methods;

    static public function create(array $config=[]):self
    {
        return new self(new JsonValidatorMethods, $config);
    }

    public function __construct(JsonValidatorMethods $methods, array $config=[])
    {
        $this->methods=$methods;
        if(!is_array($config)) throw JsonValidatorErrorException('Constructor must be provided an array or no value');
        if(array_diff($config,array_flip(['strictMode', 'sanitize']))) throw JsonValidatorErrorException('Invalid constructor value');
        foreach($config as $index=>$value) {
            $this->$index=$value;
        }
    }

    public function validate($input, $rules, ?bool $sanitize=null){

        if( !is_array($rules) && !is_a($rules,'stdClass')) throw new JsonValidatorErrorException('Invalid rule provided.  Must be an array or stdClass object.');
        if( !is_array($input) && !is_a($input,'stdClass')) throw new JsonValidatorErrorException('Invalid input provided.  Must be an array or stdClass object.');
        if(!$input && !$rules) return $input;
        if(!$origArray=is_array($input)) {
            $input=json_decode(json_encode($input), true);
        }
        $rules=is_array($rules)?$this->validateRules($rules):$this->objectToArray($rules);
        $errors=$this->isSequencial($rules)
        ?$this->validateArray($input, $rules[0]??['*'], $sanitize??$this->sanitize, 'base')
        :$this->validateObject($input, $rules, $sanitize??$this->sanitize, 'base');
        if($errors) {
            throw new JsonValidatorException('Validation error', 1, null, $errors, $rules, $input);
        }
        return $origArray?$input:json_decode(json_encode($input, false));
    }

    private function validateArray(array &$input, $rule, bool $sanitize, string $level):?string {
        $i=0;
        $errors=[];
        if(is_array($rule) && $this->isSequencial($rule)) {
            foreach($input as $index=>$item) {
                if($index!==$i++) {
                    $errors[]="Array index $index in $level is not sequencial";
                }
                elseif(!is_array($item)) {
                    $errors[]="Item $index must be an array";
                }
                elseif($rule && $e=$this->validateArray($item, $rule[0]??['*'], $sanitize, $level.'['.$index.']')) {
                    $errors[]=$e;
                }
            }
        }
        elseif(is_array($rule)) {
            foreach($input as $index=>$item) {
                if($index!==$i++) {
                    $errors[]="Array index $index in $level is not sequencial";
                }
                elseif(!is_array($item)) {
                    $errors[]="Item $index must be an array";
                }
                elseif($e=$this->validateObject($item, $rule, $sanitize, $level.'['.$index.']')) {
                    $errors[]=$e;
                }
            }
        }
        elseif($rule instanceof JsonValidatorCallbackInterface){
            foreach($input as $index=>$item) {
                if($index!==$i++) {
                    $errors[]="Array index $index in $level is not sequencial";
                }
                elseif($e=$rule->validate($this, $item)) {
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
                foreach($input as $index=>$item) {
                    if($index!==$i++) {
                        $errors[]="Array index $index in $level is not sequencial";
                    }
                    else {
                        try {
                            $input[$index]=$this->validateItem($rule[0], $method, $item, 'sequential array value', $sanitize);
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

    private function validateObject(array &$input, $rules, bool $sanitize, string $level):?string{
        $errors=[];
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
                    if($e = $this->validateArray($input[$prop], $rule[0]??['*'], $sanitize, $level.'['.$prop.']')) {
                        $errors[]=$e;
                    }
                }
                elseif($e = $this->validateObject($input[$prop], $rule, $sanitize, $level.'['.$prop.']')) {
                    $errors[]=$e;
                }
            }
            elseif($rules instanceof JsonValidatorCallbackInterface){
                //How can I make JsonValidatorCallbackInterface optional?
                if(!isset($input[$prop])) {
                    $errors[]="$prop for level $level is missing";
                }
                elseif($e=$rule->validate($this, $input[$prop])) {
                    $errors[]=$e;
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
                        $input[$prop]=$this->validateItem($rule[0], $rule[1]??null, $input[$prop], $prop, $sanitize);
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
        return $errors?implode(', ',$errors):null;
    }

    private function validateItem(string $requiredType, ?string $valueRule, $value, string $name, bool $sanitize) {
        if($requiredType[0]!=='*') { // * means any type, so skip (value validation not avaiable)
            if($sanitize) {
                $value=$this->sanitize($value, $requiredType);
            }
            $type=gettype($value);
            if( $type!==$requiredType && $this->strictBoolean($value, $requiredType)) {
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

    private function sanitize($value, string $type) {
        switch($type) {
            case 'string':case 'object':case 'array':    //Not sanitized
                break;
            case 'integer':
                if(ctype_digit($value)) $value=(int)$value;
                break;
            case 'boolean':
                if(!is_bool($value)) $value=filter_var($value, FILTER_VALIDATE_BOOLEAN);
                break;
            case 'double':
                if(!is_float($value)) $value=filter_var($value, FILTER_VALIDATE_FLOAT);
                break;
            default: throw new JsonValidatorErrorException("Invalid type '$type'");
        }
        return $value;
    }

    private function strictBoolean($value, string $rule):bool {
        //returns false only if not in strictMode and testing boolean who's value is 0 or 1
        return $this->strictMode || $rule!=='boolean' || !in_array($value,[0,1]);
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