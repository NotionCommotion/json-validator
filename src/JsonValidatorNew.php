<?php
namespace Greenbean\JsonValidator;

class JsonValidator
{
    private $delimitor = '~';   //Internal use with object() for the replacement of parenthese content, but can be changed if conflicts with user data (not necessary?)
    private $strictMode=true;   //Currently only enfources that boolean is true/false
    private $sanitize=false;     //Whether to sanitize (i.e. 'false' is changed to false)

    public function __construct($config=[])
    {
        if(!is_array($config)) throw JsonValidatorException('Constructor must be provided an array or no value');
        if(array_diff($config,array_flip(['strictMode','delimitor','sanitize']))) throw JsonValidatorException('Invalid constructor value');
        foreach($config as $key=>$value) {
            $this->$key=$value;
        }
    }

    public function object($input, $blueprint){
        /*
        Description:
        Validateds JSON object's properties type and values based on a stdClass "blueprint" which specifies rules.
        The following describes the single public method "object()"

        Parameters:
        input. The JSON object (not associated array) to validate.
        blueprint.  The ruleset which the JSON object must follow.

        Return value:
        $input potentially sanitized

        Return Errors:
        Will be returned via a JsonValidatorException

        Ruleset:  Specifies the type and value of each property in the JSON object, and is also a JSON object.

        Types:
        Supported types: string, integer, double and boolean, object (stdClass only), and arrays.
        Note that double is used because of float since for historical reasons "double" is returned in case of a float, and not simply "float" (http://php.net/manual/en/function.gettype.php)
        Multiple types for a single property are not supported (except for general objects and arrays).
        Objects are specifying using an associated array using name/blueprint for each element.
        Sequential arrays are specified by a single element sequencial array which is used for all elements.
        Arrays and objects can be recursive.
        An asterisk "*" is for any type.
        If a string type starts with an tilde "~", it is optional.
        An object with any content is specified as an empty array [].
        An sequntial array with any content is specified by ['*']
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

        if( !is_array($blueprint) && !(is_object($blueprint) && is_a($blueprint,'stdClass'))) throw new JsonValidatorException('Invalid blueprint provided.  Must be an array or stdClass object.');
        if( !is_array($input) && !(is_object($input) && is_a($input,'stdClass'))) throw new JsonValidatorException('Invalid input provided.  Must be an array or stdClass object.');
        $input=json_decode(json_encode($input));
        $blueprint=$this->array2Obj($blueprint);
        if(!$input && !$blueprint) return $input;
        $errors=$this->_object($input, $blueprint, 'base', []);
        if($errors) {
            throw new JsonValidatorException('Validation error', 1, null, $errors, $blueprint, $input);
        }
        return $input;
    }

    protected function array2Obj(array $rules) {
        $obj=new \stdClass;
        foreach($rules as $key => $rule) {
            if (is_array($rule)) {
                if(isset($rule[0])) {
                    $obj->$key=$rule;
                }
                else {
                    $obj->$key = $this->array2Obj($rule, $obj);
                }
            }
            elseif(!$rule instanceOf ValidatorCallbackInterface) {
                $obj->$key=str_replace(' ', '', $rule);
            }
            elseif(is_string($key)) {
                $obj->$key=$rule;
            }
            else {
                $obj=[$rule];
            }
        }
        return $obj;
    }

    //Methods are protected and not private so that this class can be extended
    protected function _object($input, $blueprint, $level, array $errors){
        //$blueprint should only be an object or an unassociated array
        /*
        $xdb_input=json_encode($input);
        $xdb_blueprint=json_encode($blueprint);
        echo("<h1>$level</h1>");
        echo("<h4>input</h4>");
        echo('<pre>'.print_r($input,1).'</pre>');
        echo("<h4>blueprint</h4>");
        echo('<pre>'.print_r($blueprint,1).'</pre>');
        */
        if(is_array($blueprint)) {
            switch(count($blueprint)) {
                case 0:
                    //Can be an empty array or any object
                    if(is_array($input)) {
                        if(!empty($input)) {
                            $errors[]="Unexpected sequential array provided in the '$level' object.";
                        }
                    }
                    elseif(!is_object($input)) {
                        $errors[]="Unexpected value '$input' provided in the '$level' object.";
                    }
                    break;
                case 1:
                    if(is_array($input)) {

                        if(is_array($blueprint[0]) || is_object($blueprint[0])) {
                            $err=[];
                            foreach($input as $key=>$item) {
                                if($e=self::_object($item, $blueprint[0], $level.'['.$key.']', $errors)) {
                                    $e[]=$err;
                                }
                            }
                            if($err) {
                                $errors[]=implode(', ',$err);
                            }
                        }
                        else {

                            //String or value (coming from sequential array)
                            $rule=explode(':',$blueprint[0]); //[0=>typeRule,1=>validationRule]

                            if($rule[0]!='*') { // * means any type, so skip (value validation not avaiable)
                                foreach($input as $key=>$item) {
                                    if($this->sanitize) {
                                        $item=$this->sanitize($item, $rule[0]);
                                    }
                                    $type=gettype($item);
                                    if( $type!=$rule[0] && $this->strictBoolean($item,$rule[0])) {
                                        $errors[]="Sequential array value in the '$level' object is a $type but should be a $rule[0].";
                                    }
                                    elseif(count($rule)==2) {
                                        $rs=self::validateValue($item, $rule[1], $level.'['.$key.']', $this->delimitor);
                                        if(!$rs[0]) {
                                            $errors[]="Invalid value in the '$level' sequential array: ".$rs[1][0];
                                        }
                                    }
                                }
                            }
                        }
                    }
                    elseif(is_object($input)) {
                        $prop=implode(', ',array_keys((array)$input));
                        $errors[]="Unexpected property(s) '$prop' provided in the '$level' object.";
                    }
                    else {
                        $errors[]="Unexpected value '$input' provided in the '$level' object.";
                    }
                    break;
                default: throw new JsonValidatorException('Sequential array blueprint may only have one element');
            }
        }
        elseif($blueprint instanceof \stdClass) {
            if(!is_object($input)) {
                $prop=implode(', ',array_keys((array)$blueprint));
                $errors[]="Missing property(s) '$prop' provided in the '$level' object.";
                if(!is_array($input)) {
                    $errors[]="Unexpected sequential array provided in the '$level' object.";
                }
                else {
                    $errors[]="Unexpected value '$input' provided in the '$level' object.";
                }
            }
            else {
                if($extraKeys=array_diff(array_keys((array) $input), array_keys((array) $blueprint))) {
                    $prop=implode(', ',$extraKeys);
                    $errors[]="Unexpected property(s) '$prop' provided in the '$level' object.";
                }
                foreach($blueprint as $prop=>$rule){
                    if(is_object($rule)) {
                        if(!isset($input->$prop)) {
                            $errors[]="Missing object '$prop' in the '$level' object.";
                        }
                        elseif(!is_object($input->$prop)) {
                            $missingType=is_array($rule)?'sequential array':'value';
                            $errors[]="Unexpected $missingType property '$prop' in the '$level' object.";
                        }
                        else {
                            $errors=array_merge($errors,self::_object($input->$prop, $blueprint->$prop, $level.'['.$prop.']', $errors));
                        }
                    }
                    elseif(is_array($rule)) {
                        if(!isset($input->$prop)) {
                            $errors[]="Missing sequential array '$prop' in the '$level' object.";
                        }
                        elseif(!is_array($input->$prop)) {
                            $missingType=is_object($rule)?'object':'value';
                            $errors[]="Unexpected $missingType property '$prop' in the '$level' object.";
                        }
                        else {
                            $errors=array_merge($errors,self::_object($input->$prop, $rule, $level.'['.$prop.']', $errors));
                        }
                    }
                    elseif(isset($input->$prop) || $rule[0]!='~') { //Skip if optional and not provided in input

                        if($rule[0]=='~') {
                            $rule=substr($rule, 1);
                        }
                        $rule=explode(':',$rule); //[0=>typeRule,1=>validationRule]

                        if(!isset($input->$prop)) {
                            $errors[]="Missing property '$prop' in the '$level' object.";
                        }
                        elseif($rule[0]!='*') { // * means any type, so skip (value validation not avaiable)
                            if($this->sanitize) {
                                $input->$prop=$this->sanitize($input->$prop, $rule[0]);
                            }
                            $type=gettype($input->$prop);
                            if( $type!=$rule[0] && $this->strictBoolean($input->$prop,$rule[0])) {
                                $errors[]="Property '$prop' in the '$level' object is a $type but should be a $rule[0].";
                            }
                            elseif(count($rule)==2) {
                                $rs=self::validateValue($input->$prop, $rule[1], $prop, $this->delimitor);
                                if(!$rs[0]) {
                                    $errors[]="Invalid value for the '$prop' property in the '$level' object: ".$rs[1][0];
                                }
                            }
                        }
                        //else wildcard value is considered valid.
                    }
                    //else optional not provided value is considered valid.
                }
            }
        }
        elseif($blueprint instanceof ValidatorCallbackInterface){
            $blueprint->validate($this, $input, $errors);
        }
        else {
            throw new JsonValidatorException('Sanity check.  This should never occur.');
        }
        return $errors;
    }

    private function sanitize($value, $type) {
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
            default: throw new JsonValidatorException("Invalid type '$type'");
        }
        return $value;
    }

    private function strictBoolean($value, $rule) {
        //returns false only if not in strictMode and testing boolean who's value is 0 or 1
        return $this->strictMode || $rule!='boolean' || !in_array($value,[0,1]);
    }

    static protected function validateValue($value, $ruleString, $prop, $delimitor)
    {
        //Store content in first tier parenthese and replace with delimitor
        if(strpos($ruleString, '(')) {
            preg_match_all('/\( ( (?: [^()]* | (?R) )* ) \)/x', $string, $match);   //$match[1] holds the results
            $ruleString=preg_replace('/\( ( (?: [^()]* | (?R) )* ) \)/x',$delimitor,$ruleString);
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

                if(substr($rule,0,1)==$delimitor) {
                    syslog(LOG_ERR, 'JsonValidator::validateValue():  What does this do?');
                    $rs=self::validateValue($value,$match[1][$i++],$prop);
                }
                else {
                    $rule=explode(',',$rule);
                    $method=$rule[0];
                    unset($rule[0]);
                    if(!method_exists(get_called_class(), $method)) throw new JsonValidatorException("Method $method does not exist.");
                    $rs=self::$method($value,array_values($rule),$prop);
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

    static protected function isAssocArray($arr)
    {
        //No longer used
        return $arr===[]?false:array_keys($arr) !== range(0, count($arr) - 1);
    }

    // ##################################################################
    //Each returns whether valid (true/false), error message, and negated error message
    static protected function empty($v, array $a, $prop)
    {
        if(count($a)!==0) throw new JsonValidatorException('Invalid arguement count.');
        return [empty((array)$v),"empty($prop)"];
    }
    static protected function minValue($v, array $a, $prop)
    {
        if(count($a)!==1) throw new JsonValidatorException('Invalid arguement count.');
        return [$v>=$a[0],"$prop>=$a[0]"];
    }
    static protected function maxValue($v, array $a, $prop)
    {
        if(count($a)!==1) throw new JsonValidatorException('Invalid arguement count.');
        return [$v<=$a[0],"$prop<=$a[0]"];
    }
    static protected function betweenValue($v, array $a, $prop)
    {
        if(count($a)!==2) throw new JsonValidatorException('Invalid arguement count.');
        sort($a);
        return [$v>=$a[0] && $v<=$a[1],"$a[0]>=$prop<=$a[1]"];
    }
    static protected function exactValue($v, array $a, $prop)
    {
        if(count($a)!==1) throw new JsonValidatorException('Invalid arguement count.');
        return [$v==$a[0],"$prop==$a[0]"];
    }
    static protected function minLength($v, array $a, $prop)
    {
        if(count($a)!==1) throw new JsonValidatorException('Invalid arguement count.');
        return [strlen(trim($v))>=$a[0],"strlen($prop)>=$a[0]"];
    }
    static protected function maxLength($v, array $a, $prop)
    {
        if(count($a)!==1) throw new JsonValidatorException('Invalid arguement count.');
        return [strlen(trim($v))<=$a[0],"strlen($prop)<=$a[0]"];
    }
    static protected function betweenLength($v, array $a, $prop)
    {
        if(count($a)!==2) throw new JsonValidatorException('Invalid arguement count.');
        $v=trim($v);
        sort($a);
        return [strlen($v)>=$a[0] && strlen($v)<$a[1],"$a[0]>=strlen($prop)<=$a[1]"];
    }
    static protected function exactLength($v, array $a, $prop)
    {
        if(count($a)!==1) throw new JsonValidatorException('Invalid arguement count.');
        return [strlen(trim($v))==$a[0],"strlen($prop)==$a[0]"];
    }

    static protected function email($v, array $a, $prop)
    {
        if(count($a)!==0) throw new JsonValidatorException('Invalid arguement count.');
        return [filter_var($v, FILTER_VALIDATE_EMAIL),"valid_email($prop)"];
    }
    static protected function url($v, array $a, $prop)
    {
        if(count($a)!==0) throw new JsonValidatorException('Invalid arguement count.');
        return [filter_var($v, FILTER_VALIDATE_URL),"valid_url($prop)"];
    }
    static protected function ipaddress($v, array $a, $prop)
    {
        if(count($a)!==0) throw new JsonValidatorException('Invalid arguement count.');
        return [filter_var($v, FILTER_VALIDATE_IP),"valid_ip($prop)"];
    }

    /*
    //FUTURE.  NOT COMPLETE
    static protected function domain($v, array $a, $prop)
    {
    if(count($a)!==0) throw new JsonValidatorException('Invalid arguement count.');
    return !preg_match("/^[a-z0-9_-]+$/i",$v)?'Alphanumerical, underscore, and hyphes only':false;
    }

    static protected function noInvalid($v, array $a, $prop)
    {
    if(count($a)!==0) throw new JsonValidatorException('Invalid arguement count.');
    return !preg_match("/^[a-z0-9.,_() ]+$/i",$v)?'Invalid characters':false;
    }
    static protected function filename($v, array $a, $prop)
    {
    if(count($a)!==0) throw new JsonValidatorException('Invalid arguement count.');
    return !strpbrk($v, "\\/%*:|\"<>") === FALSE?'Invalid file name':false;
    }

    static protected function longitude($v, array $a, $prop)
    {
    if(count($a)!==0) throw new JsonValidatorException('Invalid arguement count.');
    return $v<0 || $v>180?'Invalid longitude':false;
    }
    static protected function latitude($v, array $a, $prop)
    {
    if(count($a)!==0) throw new JsonValidatorException('Invalid arguement count.');
    return $v<0 || $v>90?'Invalid latitude':false;
    }
    static protected function USstate($v, array $a, $prop)
    {
    if(count($a)!==0) throw new JsonValidatorException('Invalid arguement count.');
    $states=['AA'=>1,'AE'=>1,'AL'=>1,'AK'=>1,'AS'=>1,'AP'=>1,'AZ'=>1,'AR'=>1,'CA'=>1,'CO'=>1,'CT'=>1,'DE'=>1,'DC'=>1,'FM'=>1,'FL'=>1,'GA'=>1,'GU'=>1,'HI'=>1,'ID'=>1,'IL'=>1,'IN'=>1,'IA'=>1,'KS'=>1,'KY'=>1,'LA'=>1,'ME'=>1,'MH'=>1,'MD'=>1,'MA'=>1,'MI'=>1,'MN'=>1,'MS'=>1,'MO'=>1,'MT'=>1,'NE'=>1,'NV'=>1,'NH'=>1,'NJ'=>1,'NM'=>1,'NY'=>1,'NC'=>1,'ND'=>1,'MP'=>1,'OH'=>1,'OK'=>1,'OR'=>1,'PW'=>1,'PA'=>1,'PR'=>1,'RI'=>1,'SC'=>1,'SD'=>1,'TN'=>1,'TX'=>1,'UT'=>1,'VT'=>1,'VI'=>1,'VA'=>1,'WA'=>1,'WV'=>1,'WI'=>1,'WY'=>1];
    return !isset($states[$v])?'Must be a US State':false;
    }
    static protected function timezone($v, array $a, $prop)
    {
    if(count($a)!==0) throw new JsonValidatorException('Invalid arguement count.');
    return !in_array($v, DateTimeZone::listIdentifiers())?'Invalid timezone':false;
    }
    */
}