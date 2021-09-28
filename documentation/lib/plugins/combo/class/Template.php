<?php


namespace ComboStrap;
/**
 * Class Template
 * @package ComboStrap
 * https://stackoverflow.com/questions/17869964/replacing-string-within-php-file
 */
class Template
{

    const VARIABLE_PREFIX = "$";
    protected $_string;
    protected $_data = array();

    public function __construct($string = null)
    {
        $this->_string = $string;
    }

    /**
     * @param $string
     * @return Template
     */
    public static function create($string)
    {
        return new Template($string);
    }

    public function set($key, $value)
    {
        $this->_data[$key] = $value;
        return $this;
    }

    public function render()
    {

        $splits = preg_split("/(\\".self::VARIABLE_PREFIX."[\w]*)/",$this->_string,-1,PREG_SPLIT_DELIM_CAPTURE);
        $rendered = "";
        foreach($splits as $part){
            if(substr($part,0,1)==self::VARIABLE_PREFIX){
                $variable = trim(substr($part,1));
                $value = $this->_data[$variable];
            } else {
                $value = $part;
            }
            $rendered .= $value;
        }
        return $rendered;

    }

    /**
     *
     * @return false|string
     * @deprecated Just for demo, don't use because the input is not validated
     *
     */
    public function renderViaEval()
    {
        extract($this->_data);
        ob_start();
        eval("echo $this->_string ;");
        return ob_get_clean();
    }
}
