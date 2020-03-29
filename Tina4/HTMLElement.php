<?php

const HTML_ELEMENTS = [":html",":a", ":br/",":!DOCTYPE", ":!--"];

class HTMLElement {
    private $tag="";
    private $attributes = [];
    private $elements = [];

    function __construct(...$elements)
    {
        //elements can be attributes or body parts
        foreach ($elements as $id => $element) {
            if (is_string($element) && in_array($element, HTML_ELEMENTS)) {
                $this->tag = substr($element,1);
            }
              else
            if (is_array($element)) {
                foreach ($element as $pId => $param) {
                    if (is_array($param)) {
                        $this->attributes[] = $param;
                    } else {
                        $this->elements[] = $param;
                    }
                }
            } else {
                $this->tag = substr($element,1);
            }
        }
        return $this;
    }

    function getAttributes() {
        $html = "";
        foreach ($this->attributes as $id => $attribute) {
            if (is_array($attribute)) {
                foreach ($attribute as $key => $value) {
                    if (is_numeric($key)) {
                        $html .= " {$value}";
                    } else {
                        if (is_bool($value)) {
                            if ($value) {
                                $value="true";
                            } else {
                                $value="false";
                            }
                        }
                        $html .= " {$key}=\"{$value}\"";
                    }
                }
            }
        }
        return $html;
    }

    function getElements() {
        $html = "";
        foreach ($this->elements as $id => $element) {
            $html .= $element;
        }
        return $html;
    }

    function __toString()
    {
        //Check what type of tag
        if ($this->tag[0] === "!")  {
            if (substr()) {

            }
            return "<$this->tag{$this->getAttributes()}>";
        } else
        if ($this->tag[strlen($this->tag)-1] === "/") {
            return "<$this->tag{$this->getAttributes()}/>{$this->getElements()}";
        } else {
            return "<$this->tag{$this->getAttributes()}>{$this->getElements()}</{$this->tag}>";
        }

    }
}

//Dynamic code for creating HTML Elements
foreach (HTML_ELEMENTS as $id => $ELEMENT) {
    $variableName = strtolower(str_replace ("!", "", str_replace("-", "", str_replace("/", "", substr($ELEMENT,1)))));
    if ($ELEMENT === ":!--") $variableName = "comment";
    eval ('$'.$variableName.' = function (...$elements) {
           return new HTMLElement("'.$ELEMENT.'", $elements); 
    };');
}

$dom = function (...$elements) {
    return new HTMLElement("", $elements);
};