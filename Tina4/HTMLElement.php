<?php

namespace Tina4;

const HTML_ELEMENTS = [":!DOCTYPE", ":!--", ":a", ":abbr", ":acronym", ":address", ":applet", ":area", ":article", ":aside", ":audio", ":b", ":base", ":basefont", ":bb", ":bdo", ":big", ":blockquote", ":body", ":br/", ":button", ":canvas", ":caption", ":center", ":cite", ":code", ":col", ":colgroup", ":command", ":datagrid", ":datalist", ":dd", ":del", ":details", ":dfn", ":dialog", ":dir", ":div", ":dl", ":dt", ":em", ":embed", ":eventsource", ":fieldset", ":figcaption", ":figure", ":font", ":footer", ":form", ":frame", ":frameset", ":h1", ":head", ":header", ":hgroup", ":hr/", ":html", ":i", ":iframe", ":img", ":input", ":ins", ":isindex", ":kbd", ":keygen", ":label", ":legend", ":li", ":link", ":map", ":mark", ":menu", ":meta/", ":meter", ":nav", ":noframes", ":noscript", ":object", ":ol", ":optgroup", ":option", ":output", ":p", ":param", ":pre", ":progress", ":q", ":rp", ":rt", ":ruby", ":s", ":samp", ":script", ":section", ":select", ":small", ":source", ":span", ":strike", ":strong", ":style", ":sub", ":sup", ":table", ":tbody", ":td", ":textarea", ":tfoot", ":th", ":thead", ":time", ":title", ":tr", ":track", ":tt", ":u", ":ul", ":var", ":video", ":wbr"];


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
/*foreach (HTML_ELEMENTS as $id => $ELEMENT) {

    if ($ELEMENT === ":!--") {
        $variableName = "comment";
    } else {
        $variableName = strtolower(str_replace("!", "", str_replace("-", "", str_replace("/", "", substr($ELEMENT, 1)))));
    }
    eval ('$'.$variableName.' = function (...$elements) {
           return new \Tina4\HTMLElement("'.$ELEMENT.'", $elements); 
    };');
}*/


$dom = function (...$elements) {
    return new \Tina4\HTMLElement(":document", $elements);
};
$doctype = function(...$elements) {
    return new \Tina4\HTMLElement(":doctype", $elements);
};
$a = function(...$elements) {
    return new \Tina4\HTMLElement(":a", $elements);
};
$abbr = function(...$elements) {
    return new \Tina4\HTMLElement(":abbr", $elements);
};
$acronym = function(...$elements) {
    return new \Tina4\HTMLElement(":acronym", $elements);
};
$address = function(...$elements) {
    return new \Tina4\HTMLElement(":address", $elements);
};
$applet = function(...$elements) {
    return new \Tina4\HTMLElement(":applet", $elements);
};
$area = function(...$elements) {
    return new \Tina4\HTMLElement(":area", $elements);
};
$article = function(...$elements) {
    return new \Tina4\HTMLElement(":article", $elements);
};
$aside = function(...$elements) {
    return new \Tina4\HTMLElement(":aside", $elements);
};
$audio = function(...$elements) {
    return new \Tina4\HTMLElement(":audio", $elements);
};
$b = function(...$elements) {
    return new \Tina4\HTMLElement(":b", $elements);
};
$base = function(...$elements) {
    return new \Tina4\HTMLElement(":base", $elements);
};
$basefont = function(...$elements) {
    return new \Tina4\HTMLElement(":basefont", $elements);
};
$bb = function(...$elements) {
    return new \Tina4\HTMLElement(":bb", $elements);
};
$bdo = function(...$elements) {
    return new \Tina4\HTMLElement(":bdo", $elements);
};
$big = function(...$elements) {
    return new \Tina4\HTMLElement(":big", $elements);
};
$blockquote = function(...$elements) {
    return new \Tina4\HTMLElement(":blockquote", $elements);
};
$body = function(...$elements) {
    return new \Tina4\HTMLElement(":body", $elements);
};
$br = function(...$elements) {
    return new \Tina4\HTMLElement(":br", $elements);
};
$button = function(...$elements) {
    return new \Tina4\HTMLElement(":button", $elements);
};
$canvas = function(...$elements) {
    return new \Tina4\HTMLElement(":canvas", $elements);
};
$caption = function(...$elements) {
    return new \Tina4\HTMLElement(":caption", $elements);
};
$center = function(...$elements) {
    return new \Tina4\HTMLElement(":center", $elements);
};
$cite = function(...$elements) {
    return new \Tina4\HTMLElement(":cite", $elements);
};
$code = function(...$elements) {
    return new \Tina4\HTMLElement(":code", $elements);
};
$col = function(...$elements) {
    return new \Tina4\HTMLElement(":col", $elements);
};
$colgroup = function(...$elements) {
    return new \Tina4\HTMLElement(":colgroup", $elements);
};
$command = function(...$elements) {
    return new \Tina4\HTMLElement(":command", $elements);
};
$datagrid = function(...$elements) {
    return new \Tina4\HTMLElement(":datagrid", $elements);
};
$datalist = function(...$elements) {
    return new \Tina4\HTMLElement(":datalist", $elements);
};
$dd = function(...$elements) {
    return new \Tina4\HTMLElement(":dd", $elements);
};
$del = function(...$elements) {
    return new \Tina4\HTMLElement(":del", $elements);
};
$details = function(...$elements) {
    return new \Tina4\HTMLElement(":details", $elements);
};
$dfn = function(...$elements) {
    return new \Tina4\HTMLElement(":dfn", $elements);
};
$dialog = function(...$elements) {
    return new \Tina4\HTMLElement(":dialog", $elements);
};
$dir = function(...$elements) {
    return new \Tina4\HTMLElement(":dir", $elements);
};
$div = function(...$elements) {
    return new \Tina4\HTMLElement(":div", $elements);
};
$dl = function(...$elements) {
    return new \Tina4\HTMLElement(":dl", $elements);
};
$dt = function(...$elements) {
    return new \Tina4\HTMLElement(":dt", $elements);
};
$em = function(...$elements) {
    return new \Tina4\HTMLElement(":em", $elements);
};
$embed = function(...$elements) {
    return new \Tina4\HTMLElement(":embed", $elements);
};
$eventsource = function(...$elements) {
    return new \Tina4\HTMLElement(":eventsource", $elements);
};
$fieldset = function(...$elements) {
    return new \Tina4\HTMLElement(":fieldset", $elements);
};
$figcaption = function(...$elements) {
    return new \Tina4\HTMLElement(":figcaption", $elements);
};
$figure = function(...$elements) {
    return new \Tina4\HTMLElement(":figure", $elements);
};
$font = function(...$elements) {
    return new \Tina4\HTMLElement(":font", $elements);
};
$footer = function(...$elements) {
    return new \Tina4\HTMLElement(":footer", $elements);
};
$form = function(...$elements) {
    return new \Tina4\HTMLElement(":form", $elements);
};
$frame = function(...$elements) {
    return new \Tina4\HTMLElement(":frame", $elements);
};
$frameset = function(...$elements) {
    return new \Tina4\HTMLElement(":frameset", $elements);
};
$h1 = function(...$elements) {
    return new \Tina4\HTMLElement(":h1", $elements);
};
$head = function(...$elements) {
    return new \Tina4\HTMLElement(":head", $elements);
};
$header = function(...$elements) {
    return new \Tina4\HTMLElement(":header", $elements);
};
$hgroup = function(...$elements) {
    return new \Tina4\HTMLElement(":hgroup", $elements);
};
$hr = function(...$elements) {
    return new \Tina4\HTMLElement(":hr", $elements);
};
$html = function(...$elements) {
    return new \Tina4\HTMLElement(":html", $elements);
};
$i = function(...$elements) {
    return new \Tina4\HTMLElement(":i", $elements);
};
$iframe = function(...$elements) {
    return new \Tina4\HTMLElement(":iframe", $elements);
};
$img = function(...$elements) {
    return new \Tina4\HTMLElement(":img", $elements);
};
$input = function(...$elements) {
    return new \Tina4\HTMLElement(":input", $elements);
};
$ins = function(...$elements) {
    return new \Tina4\HTMLElement(":ins", $elements);
};
$isindex = function(...$elements) {
    return new \Tina4\HTMLElement(":isindex", $elements);
};
$kbd = function(...$elements) {
    return new \Tina4\HTMLElement(":kbd", $elements);
};
$keygen = function(...$elements) {
    return new \Tina4\HTMLElement(":keygen", $elements);
};
$label = function(...$elements) {
    return new \Tina4\HTMLElement(":label", $elements);
};
$legend = function(...$elements) {
    return new \Tina4\HTMLElement(":legend", $elements);
};
$li = function(...$elements) {
    return new \Tina4\HTMLElement(":li", $elements);
};
$link = function(...$elements) {
    return new \Tina4\HTMLElement(":link", $elements);
};
$map = function(...$elements) {
    return new \Tina4\HTMLElement(":map", $elements);
};
$mark = function(...$elements) {
    return new \Tina4\HTMLElement(":mark", $elements);
};
$menu = function(...$elements) {
    return new \Tina4\HTMLElement(":menu", $elements);
};
$meta = function(...$elements) {
    return new \Tina4\HTMLElement(":meta", $elements);
};
$meter = function(...$elements) {
    return new \Tina4\HTMLElement(":meter", $elements);
};
$nav = function(...$elements) {
    return new \Tina4\HTMLElement(":nav", $elements);
};
$noframes = function(...$elements) {
    return new \Tina4\HTMLElement(":noframes", $elements);
};
$noscript = function(...$elements) {
    return new \Tina4\HTMLElement(":noscript", $elements);
};
$object = function(...$elements) {
    return new \Tina4\HTMLElement(":object", $elements);
};
$ol = function(...$elements) {
    return new \Tina4\HTMLElement(":ol", $elements);
};
$optgroup = function(...$elements) {
    return new \Tina4\HTMLElement(":optgroup", $elements);
};
$option = function(...$elements) {
    return new \Tina4\HTMLElement(":option", $elements);
};
$output = function(...$elements) {
    return new \Tina4\HTMLElement(":output", $elements);
};
$p = function(...$elements) {
    return new \Tina4\HTMLElement(":p", $elements);
};
$param = function(...$elements) {
    return new \Tina4\HTMLElement(":param", $elements);
};
$pre = function(...$elements) {
    return new \Tina4\HTMLElement(":pre", $elements);
};
$progress = function(...$elements) {
    return new \Tina4\HTMLElement(":progress", $elements);
};
$q = function(...$elements) {
    return new \Tina4\HTMLElement(":q", $elements);
};
$rp = function(...$elements) {
    return new \Tina4\HTMLElement(":rp", $elements);
};
$rt = function(...$elements) {
    return new \Tina4\HTMLElement(":rt", $elements);
};
$ruby = function(...$elements) {
    return new \Tina4\HTMLElement(":ruby", $elements);
};
$s = function(...$elements) {
    return new \Tina4\HTMLElement(":s", $elements);
};
$samp = function(...$elements) {
    return new \Tina4\HTMLElement(":samp", $elements);
};
$script = function(...$elements) {
    return new \Tina4\HTMLElement(":script", $elements);
};
$section = function(...$elements) {
    return new \Tina4\HTMLElement(":section", $elements);
};
$select = function(...$elements) {
    return new \Tina4\HTMLElement(":select", $elements);
};
$small = function(...$elements) {
    return new \Tina4\HTMLElement(":small", $elements);
};
$source = function(...$elements) {
    return new \Tina4\HTMLElement(":source", $elements);
};
$span = function(...$elements) {
    return new \Tina4\HTMLElement(":span", $elements);
};
$strike = function(...$elements) {
    return new \Tina4\HTMLElement(":strike", $elements);
};
$strong = function(...$elements) {
    return new \Tina4\HTMLElement(":strong", $elements);
};
$style = function(...$elements) {
    return new \Tina4\HTMLElement(":style", $elements);
};
$sub = function(...$elements) {
    return new \Tina4\HTMLElement(":sub", $elements);
};
$sup = function(...$elements) {
    return new \Tina4\HTMLElement(":sup", $elements);
};
$table = function(...$elements) {
    return new \Tina4\HTMLElement(":table", $elements);
};
$tbody = function(...$elements) {
    return new \Tina4\HTMLElement(":tbody", $elements);
};
$td = function(...$elements) {
    return new \Tina4\HTMLElement(":td", $elements);
};
$textarea = function(...$elements) {
    return new \Tina4\HTMLElement(":textarea", $elements);
};
$tfoot = function(...$elements) {
    return new \Tina4\HTMLElement(":tfoot", $elements);
};
$th = function(...$elements) {
    return new \Tina4\HTMLElement(":th", $elements);
};
$thead = function(...$elements) {
    return new \Tina4\HTMLElement(":thead", $elements);
};
$time = function(...$elements) {
    return new \Tina4\HTMLElement(":time", $elements);
};
$title = function(...$elements) {
    return new \Tina4\HTMLElement(":title", $elements);
};
$tr = function(...$elements) {
    return new \Tina4\HTMLElement(":tr", $elements);
};
$track = function(...$elements) {
    return new \Tina4\HTMLElement(":track", $elements);
};
$tt = function(...$elements) {
    return new \Tina4\HTMLElement(":tt", $elements);
};
$u = function(...$elements) {
    return new \Tina4\HTMLElement(":u", $elements);
};
$ul = function(...$elements) {
    return new \Tina4\HTMLElement(":ul", $elements);
};
$var = function(...$elements) {
    return new \Tina4\HTMLElement(":var", $elements);
};
$video = function(...$elements) {
    return new \Tina4\HTMLElement(":video", $elements);
};
$wbr = function(...$elements) {
    return new \Tina4\HTMLElement(":wbr", $elements);
};