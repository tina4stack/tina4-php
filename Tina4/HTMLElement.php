<?php

namespace Tina4;

const HTML_ELEMENTS = [":!DOCTYPE", ":!--", ":a", ":abbr", ":acronym", ":address", ":applet", ":area", ":article", ":aside", ":audio", ":b", ":base", ":basefont", ":bb", ":bdo", ":big", ":blockquote", ":body", ":br/", ":button", ":canvas", ":caption", ":center", ":cite", ":code", ":col", ":colgroup", ":command", ":datagrid", ":datalist", ":dd", ":del", ":details", ":dfn", ":dialog", ":dir", ":div", ":dl", ":dt", ":em", ":embed", ":eventsource", ":fieldset", ":figcaption", ":figure", ":font", ":footer", ":form", ":frame", ":frameset", ":h1", ":head", ":header", ":hgroup", ":hr/", ":html", ":i", ":iframe", ":img/", ":input", ":ins", ":isindex", ":kbd", ":keygen", ":label", ":legend", ":li", ":link", ":map", ":mark", ":menu", ":meta/", ":meter", ":nav", ":noframes", ":noscript", ":object", ":ol", ":optgroup", ":option", ":output", ":p", ":param", ":pre", ":progress", ":q", ":rp", ":rt", ":ruby", ":s", ":samp", ":script", ":section", ":select", ":small", ":source", ":span", ":strike", ":strong", ":style", ":sub", ":sup", ":table", ":tbody", ":td", ":textarea", ":tfoot", ":th", ":thead", ":time", ":title", ":tr", ":track", ":tt", ":u", ":ul", ":var", ":video", ":wbr"];


class HTMLElement {
    private $tag="";
    private $attributes = [];
    private $elements = [];

    function sortElements($element) {
        foreach ($element as $pId => $param) {
            if (is_array($param)) {
                $this->sortElements($param);
            } else {
                if (is_object($param)) {
                    if (get_class($param) === "Tina4\HTMLElement") {
                        $this->elements[] = $param;
                    } else {
                        echo "DEBUG {$param}";
                    }
                } else {
                    if (is_numeric($pId)) {
                        $this->elements[] = $param;
                    } else {
                        $this->attributes[] = [$pId => $param];
                    }
                }
            }
        }
    }

    function __construct(...$elements)
    {
        //elements can be attributes or body parts
        foreach ($elements as $id => $element) {
            if (is_string($element) && in_array($element, HTML_ELEMENTS)) {
                $this->tag = substr($element,1);
            }
              else
            if (is_array($element)) {
                $this->sortElements($element);
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
        if ($this->tag === "document") {
            return "{$this->getElements()}";
        } else
        if ($this->tag[0] === "!")  {
            if (strpos($this->tag, "!--") !== false) {
                return "<$this->tag{$this->getAttributes()}>{$this->getElements()}</{$this->tag}>";
            } else {
                return "<$this->tag{$this->getAttributes()}>";
            }
        } else
        if ($this->tag[strlen($this->tag)-1] === "/") {
            return "<$this->tag{$this->getAttributes()}>{$this->getElements()}";
        } else {
            return "<$this->tag{$this->getAttributes()}>{$this->getElements()}</{$this->tag}>";
        }

    }
}

//Dynamic code for creating HTML Elements
foreach (HTML_ELEMENTS as $id => $ELEMENT) {
    if ($ELEMENT === ":!--") {
        $variableName = "comment";
    } else {
        $variableName = "_".strtolower(str_replace("!", "", str_replace("-", "", str_replace("/", "", substr($ELEMENT, 1)))));
    }
    eval ('
        function '.$variableName.' (...$elements) {
           return new \Tina4\HTMLElement("'.$ELEMENT.'", $elements); 
    };');
}

