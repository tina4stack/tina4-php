<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * List of Html tags prefixed with a : for where the opening tag should go
 */
const HTML_ELEMENTS = [":!DOCTYPE", ":!--", ":a", ":abbr", ":acronym", ":address", ":applet", ":area/", ":article", ":aside", ":audio", ":b", ":base/", ":basefont/", ":bdi", ":bdo", ":big", ":blockquote", ":body", ":br/", ":button", ":canvas", ":caption", ":center", ":cite", ":code", ":col/", ":colgroup", ":command", ":datagrid", ":datalist", ":dd", ":del", ":details", ":dfn", ":dialog", ":dir", ":div", ":dl", ":dt", ":em", ":embed/", ":eventsource", ":fieldset", ":figcaption", ":figure", ":font", ":footer", ":form", ":frame", ":frameset", ":h1", ":h2", ":h3", ":h4", ":h5", ":h6", ":h7", ":head", ":header", ":hgroup", ":hr/", ":html", ":i", ":iframe", ":img/", ":input", ":ins", ":isindex", ":kbd", ":keygen", ":label", ":legend", ":li", ":link", ":map", ":mark", ":menu", ":meta/", ":meter", ":nav", ":noframes", ":noscript", ":object", ":ol", ":optgroup", ":option", ":output", ":p", ":param", ":pre", ":progress", ":q", ":rp", ":rt", ":ruby", ":s", ":samp", ":script", ":section", ":select", ":small", ":source/", ":span", ":strike", ":strong", ":style", ":sub", ":sup", ":table", ":tbody", ":td", ":textarea", ":tfoot", ":th", ":thead", ":time", ":title", ":tr", ":track", ":tt", ":u", ":ul", ":var", ":video", ":wbr"];
/**
 * A way to code HTML5 elements using only PHP
 * @package Tina4
 */
class HTMLElement
{
    /**
     * String tag name like h1, p, b etc
     * @var false|string
     */
    private $tag = "";
    /**
     * An array of attributes -> name="test", class=""
     * @var array
     */
    private $attributes = [];

    /**
     * An array of html elements
     * @var array
     */
    private $elements = [];

    /**
     * HTMLElement constructor.
     * @param mixed ...$params
     */
    public function __construct(...$params)
    {
        //elements can be attributes or body parts
        foreach ($params as $index =>  $param) {
            if (is_string($param) && in_array($param, HTML_ELEMENTS)) {
                $this->tag = substr($param, 1);
            } elseif (is_array($param)) {
                $this->determineParamType($param, $index);
            } else {
                $this->tag = substr($param, 1);
            }
        }
    }

    /**
     * Determines the param type, either it is an element or attribute
     * @param mixed $element
     * @param int $index
     */
    private function determineParamType($element, int $index=0): void
    {
        foreach ($element as $key => $param) {
            if (is_array($param)) {
                $this->determineParamType($param, $key);
            } else if ($index === 0 && !is_object($param)) {
                $this->attributes[] = [$key => $param];
            } else {
                $this->elements[] = $param;
            }
        }
    }

    /**
     * Outputs the html
     * @return string
     */
    public function __toString(): string
    {
        //Check what type of tag
        if ($this->tag === "document" || $this->tag === "") {
            $html = (string)($this->getElements());
        } elseif ($this->tag[0] === "!") {
            if (strpos($this->tag, "!--") !== false) {
                $html =  "<$this->tag{$this->getAttributes()}{$this->getElements()}" . substr($this->tag, 1) . ">"; //tag like <!--
            } else {
                $html =  "<$this->tag{$this->getAttributes()}{$this->getElements()}>"; //tag like <!DOCTYPE html>
            }
        } elseif ($this->tag[strlen($this->tag) - 1] === "/") {
            $html =  "<".substr($this->tag, 0,-1)."{$this->getAttributes()}{$this->getElements()}>"; // tag like <img> or <area>
        } else {
            $html =  "<$this->tag{$this->getAttributes()}>{$this->getElements()}</{$this->tag}>"; //normal tag <a></a>
        }
        return $html;
    }

    /**
     * Gets all the elements which are HTML tags
     * @return string
     */
    private function getElements(): string
    {
        $html = "";
        foreach ($this->elements as $element) {
            $html .= $element;
        }
        return $html;
    }

    /**
     * Gets all the attributes for an HTML element, class is a good example
     * @return string
     */
    private function getAttributes(): string
    {
        $html = "";
        foreach ($this->attributes as $attribute) {
            if (is_array($attribute)) {
                foreach ($attribute as $key => $value) {
                    if (is_numeric($key)) {
                        $html .= (string)($value);
                    } else {
                        is_bool($value) ? ($value === true) ? ($value = "true") : ($value = "false") : null;
                        if ($value !== null) {
                            $html .= "{$key}=\"{$value}\" ";
                        }
                    }
                }
            }
        }
        if (!empty($html)) {
            $html  = " ".trim($html);
        }
        return $html;
    }

    /**
     * Finds an html element by id
     * @param string $id The name of the id of the element we are looking for
     * @return $this
     */
    final public function byId(string $id): ?HTMLElement
    {
        if ($this->attributeExists("id", $id)) {
            return $this;
        }

        return $this->findById($id, $this->elements);
    }

    /**
     * Checks if there is an attribute with the value
     * @param string $name Name of the attribute
     * @param string $value Value of the attribute
     * @return bool
     */
    final public function attributeExists(string $name, string $value): bool
    {
        foreach ($this->attributes as $attribute ){
            if(isset($attribute[$name]) && $attribute[$name] === $value){
                return true;
            }
        }
        return false;
    }

    /**
     * Recursive scan
     * @param string $id
     * @param mixed $elements
     * @return $this
     */
    final public function findById(string $id, $elements): ?HTMLElement
    {
        //Iterate through all the elements to find an element by id
        foreach ($elements as $element) {
            if (is_object($element) && $element instanceof self) {
                if ($element->attributeExists("id", $id)) {
                    return $element;
                }

                $found = $element->byId($id);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * Sets the element's "html" part to value
     * @param mixed $html
     */
    public function html($html): void
    {
        $this->elements = [$html];
    }
}

/**$elements = "";
 * //Dynamic code for creating HTML Elements
 * foreach (HTML_ELEMENTS as $id => $ELEMENT) {
 * if ($ELEMENT === ":!--") {
 * $variableName = "comment";
 * } else {
 * $variableName = "_" . strtolower(str_replace("!", "", str_replace("-", "", str_replace("/", "", substr($ELEMENT, 1)))));
 * }
 *
 * eval ('
 * function ' . $variableName . ' (...$elements) {
 * return new HTMLElement("' . $ELEMENT . '", $elements);
 * }');
 *
 * $elements .= '
 * /**
 * HTML TAG '.$variableName.'
 * @param $elements
 * @return HTMLElement
 */
/**function ' . $variableName . ' (...$elements) {
 * return new HTMLElement("' . $ELEMENT . '", $elements);
 * }';
 * }
 *
 * echo $elements;
 **/
