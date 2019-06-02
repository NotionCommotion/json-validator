<?php
namespace Greenbean\JsonValidator;

class JsonValidatorMethods
{

    public function __call(string $name, array $args)
    {
        throw new JsonValidatorErrorException("Method $name does not exist");
    }

    //Each returns whether valid (true/false) and error message
    public function empty($v, array $a, $prop)
    {
        if(count($a)!==0) throw new JsonValidatorErrorException('Invalid arguement count.');
        return [empty((array)$v),"empty($prop)"];
    }
    public function minValue($v, array $a, $prop)
    {
        if(count($a)!==1) throw new JsonValidatorErrorException('Invalid arguement count.');
        return [$v>=$a[0],"$prop>=$a[0]"];
    }
    public function maxValue($v, array $a, $prop)
    {
        if(count($a)!==1) throw new JsonValidatorErrorException('Invalid arguement count.');
        return [$v<=$a[0],"$prop<=$a[0]"];
    }
    public function betweenValue($v, array $a, $prop)
    {
        if(count($a)!==2) throw new JsonValidatorErrorException('Invalid arguement count.');
        sort($a);
        return [$v>=$a[0] && $v<=$a[1],"$a[0]>=$prop<=$a[1]"];
    }
    public function exactValue($v, array $a, $prop)
    {
        if(count($a)!==1) throw new JsonValidatorErrorException('Invalid arguement count.');
        return [$v==$a[0],"$prop==$a[0]"];
    }
    public function minLength($v, array $a, $prop)
    {
        if(count($a)!==1) throw new JsonValidatorErrorException('Invalid arguement count.');
        return [strlen(trim($v))>=$a[0],"strlen($prop)>=$a[0]"];
    }
    public function maxLength($v, array $a, $prop)
    {
        if(count($a)!==1) throw new JsonValidatorErrorException('Invalid arguement count.');
        return [strlen(trim($v))<=$a[0],"strlen($prop)<=$a[0]"];
    }
    public function betweenLength($v, array $a, $prop)
    {
        if(count($a)!==2) throw new JsonValidatorErrorException('Invalid arguement count.');
        $v=trim($v);
        sort($a);
        return [strlen($v)>=$a[0] && strlen($v)<$a[1],"$a[0]>=strlen($prop)<=$a[1]"];
    }
    public function exactLength($v, array $a, $prop)
    {
        if(count($a)!==1) throw new JsonValidatorErrorException('Invalid arguement count.');
        return [strlen(trim($v))==$a[0],"strlen($prop)==$a[0]"];
    }

    public function email($v, array $a, $prop)
    {
        if(count($a)!==0) throw new JsonValidatorErrorException('Invalid arguement count.');
        return [filter_var($v, FILTER_VALIDATE_EMAIL),"valid_email($prop)"];
    }
    public function url($v, array $a, $prop)
    {
        if(count($a)!==0) throw new JsonValidatorErrorException('Invalid arguement count.');
        return [filter_var($v, FILTER_VALIDATE_URL),"valid_url($prop)"];
    }
    public function ipaddress($v, array $a, $prop)
    {
        if(count($a)!==0) throw new JsonValidatorErrorException('Invalid arguement count.');
        return [filter_var($v, FILTER_VALIDATE_IP),"valid_ip($prop)"];
    }

    /*
    //FUTURE.  NOT COMPLETE
    public function domain($v, array $a, $prop)
    {
    if(count($a)!==0) throw new JsonValidatorErrorException('Invalid arguement count.');
    return !preg_match("/^[a-z0-9_-]+$/i",$v)?'Alphanumerical, underscore, and hyphes only':false;
    }

    public function noInvalid($v, array $a, $prop)
    {
    if(count($a)!==0) throw new JsonValidatorErrorException('Invalid arguement count.');
    return !preg_match("/^[a-z0-9.,_() ]+$/i",$v)?'Invalid characters':false;
    }
    public function filename($v, array $a, $prop)
    {
    if(count($a)!==0) throw new JsonValidatorErrorException('Invalid arguement count.');
    return !strpbrk($v, "\\/%*:|\"<>") === FALSE?'Invalid file name':false;
    }

    public function longitude($v, array $a, $prop)
    {
    if(count($a)!==0) throw new JsonValidatorErrorException('Invalid arguement count.');
    return $v<0 || $v>180?'Invalid longitude':false;
    }
    public function latitude($v, array $a, $prop)
    {
    if(count($a)!==0) throw new JsonValidatorErrorException('Invalid arguement count.');
    return $v<0 || $v>90?'Invalid latitude':false;
    }
    public function USstate($v, array $a, $prop)
    {
    if(count($a)!==0) throw new JsonValidatorErrorException('Invalid arguement count.');
    $states=['AA'=>1,'AE'=>1,'AL'=>1,'AK'=>1,'AS'=>1,'AP'=>1,'AZ'=>1,'AR'=>1,'CA'=>1,'CO'=>1,'CT'=>1,'DE'=>1,'DC'=>1,'FM'=>1,'FL'=>1,'GA'=>1,'GU'=>1,'HI'=>1,'ID'=>1,'IL'=>1,'IN'=>1,'IA'=>1,'KS'=>1,'KY'=>1,'LA'=>1,'ME'=>1,'MH'=>1,'MD'=>1,'MA'=>1,'MI'=>1,'MN'=>1,'MS'=>1,'MO'=>1,'MT'=>1,'NE'=>1,'NV'=>1,'NH'=>1,'NJ'=>1,'NM'=>1,'NY'=>1,'NC'=>1,'ND'=>1,'MP'=>1,'OH'=>1,'OK'=>1,'OR'=>1,'PW'=>1,'PA'=>1,'PR'=>1,'RI'=>1,'SC'=>1,'SD'=>1,'TN'=>1,'TX'=>1,'UT'=>1,'VT'=>1,'VI'=>1,'VA'=>1,'WA'=>1,'WV'=>1,'WI'=>1,'WY'=>1];
    return !isset($states[$v])?'Must be a US State':false;
    }
    public function timezone($v, array $a, $prop)
    {
    if(count($a)!==0) throw new JsonValidatorErrorException('Invalid arguement count.');
    return !in_array($v, DateTimeZone::listIdentifiers())?'Invalid timezone':false;
    }
    */
}