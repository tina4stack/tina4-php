<?php

namespace Tina4;

const HTML_ELEMENTS = [":!DOCTYPE", ":!--", ":a", ":abbr", ":acronym", ":address", ":applet", ":area", ":article", ":aside", ":audio", ":b", ":base", ":basefont", ":bb", ":bdo", ":big", ":blockquote", ":body", ":br/", ":button", ":canvas", ":caption", ":center", ":cite", ":code", ":col", ":colgroup", ":command", ":datagrid", ":datalist", ":dd", ":del", ":details", ":dfn", ":dialog", ":dir", ":div", ":dl", ":dt", ":em", ":embed", ":eventsource", ":fieldset", ":figcaption", ":figure", ":font", ":footer", ":form", ":frame", ":frameset", ":h1", ":head", ":header", ":hgroup", ":hr/", ":html", ":i", ":iframe", ":img/", ":input", ":ins", ":isindex", ":kbd", ":keygen", ":label", ":legend", ":li", ":link", ":map", ":mark", ":menu", ":meta/", ":meter", ":nav", ":noframes", ":noscript", ":object", ":ol", ":optgroup", ":option", ":output", ":p", ":param", ":pre", ":progress", ":q", ":rp", ":rt", ":ruby", ":s", ":samp", ":script", ":section", ":select", ":small", ":source", ":span", ":strike", ":strong", ":style", ":sub", ":sup", ":table", ":tbody", ":td", ":textarea", ":tfoot", ":th", ":thead", ":time", ":title", ":tr", ":track", ":tt", ":u", ":ul", ":var", ":video", ":wbr"];

class HTMLElement
{
    private $tag = "";
    private $attributes = [];
    private $elements = [];

    /**
     * HTMLElement constructor.
     * @param mixed ...$elements
     */
    function __construct(...$elements)
    {
        //elements can be attributes or body parts
        foreach ($elements as $id => $element) {
            if (is_string($element) && in_array($element, HTML_ELEMENTS)) {
                $this->tag = substr($element, 1);
            } else
                if (is_array($element)) {
                    $this->sortElements($element);
                } else {
                    $this->tag = substr($element, 1);
                }
        }
        return $this;
    }

    /**
     * Sort the elements
     * @param $element
     */
    function sortElements($element)
    {
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
                        if ($pId == "_" || $pId == "" || $pId == " ") {
                            $this->attributes[] = [$param];
                        } else {
                            $this->attributes[] = [$pId => $param];
                        }
                    }
                }
            }
        }
    }

    /**
     * Outputs the html
     * @return string
     */
    function __toString()
    {
        //Check what type of tag
        if ($this->tag === "document") {
            return "{$this->getElements()}";
        } else
            if ($this->tag[0] === "!") {
                if (strpos($this->tag, "!--") !== false) {
                    return "<$this->tag{$this->getAttributes()}>{$this->getElements()}</{$this->tag}>";
                } else {
                    return "<$this->tag{$this->getAttributes()}>";
                }
            } else
                if ($this->tag[strlen($this->tag) - 1] === "/") {
                    return "<$this->tag{$this->getAttributes()}>{$this->getElements()}";
                } else {
                    return "<$this->tag{$this->getAttributes()}>{$this->getElements()}</{$this->tag}>";
                }

    }

    function getElements()
    {
        $html = "";
        foreach ($this->elements as $id => $element) {
            $html .= $element;
        }
        return $html;
    }

    function getAttributes()
    {
        $html = "";
        foreach ($this->attributes as $id => $attribute) {
            if (is_array($attribute)) {
                foreach ($attribute as $key => $value) {
                    if (is_numeric($key)) {
                        $html .= " {$value}";
                    } else {
                        if (is_bool($value)) {
                            if ($value) {
                                $value = "true";
                            } else {
                                $value = "false";
                            }
                        }
                        if ($value !== null) {
                            $html .= " {$key}=\"{$value}\"";
                        }
                    }
                }
            }
        }
        return $html;
    }
}

/**$elements = "";
//Dynamic code for creating HTML Elements
foreach (HTML_ELEMENTS as $id => $ELEMENT) {
    if ($ELEMENT === ":!--") {
        $variableName = "comment";
    } else {
        $variableName = "_" . strtolower(str_replace("!", "", str_replace("-", "", str_replace("/", "", substr($ELEMENT, 1)))));
    }

    eval ('
        function ' . $variableName . ' (...$elements) {
           return new HTMLElement("' . $ELEMENT . '", $elements); 
    }');

    $elements .= '
/**
* HTML TAG '.$variableName.'
* @param $elements
* @return HTMLElement
*/
/**function ' . $variableName . ' (...$elements) {
           return new HTMLElement("' . $ELEMENT . '", $elements);
    }';
}

echo $elements;
**/


/**
 * HTML TAG _doctype
 * @param $elements
 * @return HTMLElement
 */
function _doctype (...$elements) {
    return new HTMLElement(":!DOCTYPE", $elements);
}
/**
 * HTML TAG comment
 * @param $elements
 * @return HTMLElement
 */
function comment (...$elements) {
    return new HTMLElement(":!--", $elements);
}
/**
 * HTML TAG _a
 * @param $elements
 * @return HTMLElement
 */
function _a (...$elements) {
    return new HTMLElement(":a", $elements);
}
/**
 * HTML TAG _abbr
 * @param $elements
 * @return HTMLElement
 */
function _abbr (...$elements) {
    return new HTMLElement(":abbr", $elements);
}
/**
 * HTML TAG _acronym
 * @param $elements
 * @return HTMLElement
 */
function _acronym (...$elements) {
    return new HTMLElement(":acronym", $elements);
}
/**
 * HTML TAG _address
 * @param $elements
 * @return HTMLElement
 */
function _address (...$elements) {
    return new HTMLElement(":address", $elements);
}
/**
 * HTML TAG _applet
 * @param $elements
 * @return HTMLElement
 */
function _applet (...$elements) {
    return new HTMLElement(":applet", $elements);
}
/**
 * HTML TAG _area
 * @param $elements
 * @return HTMLElement
 */
function _area (...$elements) {
    return new HTMLElement(":area", $elements);
}
/**
 * HTML TAG _article
 * @param $elements
 * @return HTMLElement
 */
function _article (...$elements) {
    return new HTMLElement(":article", $elements);
}
/**
 * HTML TAG _aside
 * @param $elements
 * @return HTMLElement
 */
function _aside (...$elements) {
    return new HTMLElement(":aside", $elements);
}
/**
 * HTML TAG _audio
 * @param $elements
 * @return HTMLElement
 */
function _audio (...$elements) {
    return new HTMLElement(":audio", $elements);
}
/**
 * HTML TAG _b
 * @param $elements
 * @return HTMLElement
 */
function _b (...$elements) {
    return new HTMLElement(":b", $elements);
}
/**
 * HTML TAG _base
 * @param $elements
 * @return HTMLElement
 */
function _base (...$elements) {
    return new HTMLElement(":base", $elements);
}
/**
 * HTML TAG _basefont
 * @param $elements
 * @return HTMLElement
 */
function _basefont (...$elements) {
    return new HTMLElement(":basefont", $elements);
}
/**
 * HTML TAG _bb
 * @param $elements
 * @return HTMLElement
 */
function _bb (...$elements) {
    return new HTMLElement(":bb", $elements);
}
/**
 * HTML TAG _bdo
 * @param $elements
 * @return HTMLElement
 */
function _bdo (...$elements) {
    return new HTMLElement(":bdo", $elements);
}
/**
 * HTML TAG _big
 * @param $elements
 * @return HTMLElement
 */
function _big (...$elements) {
    return new HTMLElement(":big", $elements);
}
/**
 * HTML TAG _blockquote
 * @param $elements
 * @return HTMLElement
 */
function _blockquote (...$elements) {
    return new HTMLElement(":blockquote", $elements);
}
/**
 * HTML TAG _body
 * @param $elements
 * @return HTMLElement
 */
function _body (...$elements) {
    return new HTMLElement(":body", $elements);
}
/**
 * HTML TAG _br
 * @param $elements
 * @return HTMLElement
 */
function _br (...$elements) {
    return new HTMLElement(":br/", $elements);
}
/**
 * HTML TAG _button
 * @param $elements
 * @return HTMLElement
 */
function _button (...$elements) {
    return new HTMLElement(":button", $elements);
}
/**
 * HTML TAG _canvas
 * @param $elements
 * @return HTMLElement
 */
function _canvas (...$elements) {
    return new HTMLElement(":canvas", $elements);
}
/**
 * HTML TAG _caption
 * @param $elements
 * @return HTMLElement
 */
function _caption (...$elements) {
    return new HTMLElement(":caption", $elements);
}
/**
 * HTML TAG _center
 * @param $elements
 * @return HTMLElement
 */
function _center (...$elements) {
    return new HTMLElement(":center", $elements);
}
/**
 * HTML TAG _cite
 * @param $elements
 * @return HTMLElement
 */
function _cite (...$elements) {
    return new HTMLElement(":cite", $elements);
}
/**
 * HTML TAG _code
 * @param $elements
 * @return HTMLElement
 */
function _code (...$elements) {
    return new HTMLElement(":code", $elements);
}
/**
 * HTML TAG _col
 * @param $elements
 * @return HTMLElement
 */
function _col (...$elements) {
    return new HTMLElement(":col", $elements);
}
/**
 * HTML TAG _colgroup
 * @param $elements
 * @return HTMLElement
 */
function _colgroup (...$elements) {
    return new HTMLElement(":colgroup", $elements);
}
/**
 * HTML TAG _command
 * @param $elements
 * @return HTMLElement
 */
function _command (...$elements) {
    return new HTMLElement(":command", $elements);
}
/**
 * HTML TAG _datagrid
 * @param $elements
 * @return HTMLElement
 */
function _datagrid (...$elements) {
    return new HTMLElement(":datagrid", $elements);
}
/**
 * HTML TAG _datalist
 * @param $elements
 * @return HTMLElement
 */
function _datalist (...$elements) {
    return new HTMLElement(":datalist", $elements);
}
/**
 * HTML TAG _dd
 * @param $elements
 * @return HTMLElement
 */
function _dd (...$elements) {
    return new HTMLElement(":dd", $elements);
}
/**
 * HTML TAG _del
 * @param $elements
 * @return HTMLElement
 */
function _del (...$elements) {
    return new HTMLElement(":del", $elements);
}
/**
 * HTML TAG _details
 * @param $elements
 * @return HTMLElement
 */
function _details (...$elements) {
    return new HTMLElement(":details", $elements);
}
/**
 * HTML TAG _dfn
 * @param $elements
 * @return HTMLElement
 */
function _dfn (...$elements) {
    return new HTMLElement(":dfn", $elements);
}
/**
 * HTML TAG _dialog
 * @param $elements
 * @return HTMLElement
 */
function _dialog (...$elements) {
    return new HTMLElement(":dialog", $elements);
}
/**
 * HTML TAG _dir
 * @param $elements
 * @return HTMLElement
 */
function _dir (...$elements) {
    return new HTMLElement(":dir", $elements);
}
/**
 * HTML TAG _div
 * @param $elements
 * @return HTMLElement
 */
function _div (...$elements) {
    return new HTMLElement(":div", $elements);
}
/**
 * HTML TAG _dl
 * @param $elements
 * @return HTMLElement
 */
function _dl (...$elements) {
    return new HTMLElement(":dl", $elements);
}
/**
 * HTML TAG _dt
 * @param $elements
 * @return HTMLElement
 */
function _dt (...$elements) {
    return new HTMLElement(":dt", $elements);
}
/**
 * HTML TAG _em
 * @param $elements
 * @return HTMLElement
 */
function _em (...$elements) {
    return new HTMLElement(":em", $elements);
}
/**
 * HTML TAG _embed
 * @param $elements
 * @return HTMLElement
 */
function _embed (...$elements) {
    return new HTMLElement(":embed", $elements);
}
/**
 * HTML TAG _eventsource
 * @param $elements
 * @return HTMLElement
 */
function _eventsource (...$elements) {
    return new HTMLElement(":eventsource", $elements);
}
/**
 * HTML TAG _fieldset
 * @param $elements
 * @return HTMLElement
 */
function _fieldset (...$elements) {
    return new HTMLElement(":fieldset", $elements);
}
/**
 * HTML TAG _figcaption
 * @param $elements
 * @return HTMLElement
 */
function _figcaption (...$elements) {
    return new HTMLElement(":figcaption", $elements);
}
/**
 * HTML TAG _figure
 * @param $elements
 * @return HTMLElement
 */
function _figure (...$elements) {
    return new HTMLElement(":figure", $elements);
}
/**
 * HTML TAG _font
 * @param $elements
 * @return HTMLElement
 */
function _font (...$elements) {
    return new HTMLElement(":font", $elements);
}
/**
 * HTML TAG _footer
 * @param $elements
 * @return HTMLElement
 */
function _footer (...$elements) {
    return new HTMLElement(":footer", $elements);
}
/**
 * HTML TAG _form
 * @param $elements
 * @return HTMLElement
 */
function _form (...$elements) {
    return new HTMLElement(":form", $elements);
}
/**
 * HTML TAG _frame
 * @param $elements
 * @return HTMLElement
 */
function _frame (...$elements) {
    return new HTMLElement(":frame", $elements);
}
/**
 * HTML TAG _frameset
 * @param $elements
 * @return HTMLElement
 */
function _frameset (...$elements) {
    return new HTMLElement(":frameset", $elements);
}
/**
 * HTML TAG _h1
 * @param $elements
 * @return HTMLElement
 */
function _h1 (...$elements) {
    return new HTMLElement(":h1", $elements);
}
/**
 * HTML TAG _head
 * @param $elements
 * @return HTMLElement
 */
function _head (...$elements) {
    return new HTMLElement(":head", $elements);
}
/**
 * HTML TAG _header
 * @param $elements
 * @return HTMLElement
 */
function _header (...$elements) {
    return new HTMLElement(":header", $elements);
}
/**
 * HTML TAG _hgroup
 * @param $elements
 * @return HTMLElement
 */
function _hgroup (...$elements) {
    return new HTMLElement(":hgroup", $elements);
}
/**
 * HTML TAG _hr
 * @param $elements
 * @return HTMLElement
 */
function _hr (...$elements) {
    return new HTMLElement(":hr/", $elements);
}
/**
 * HTML TAG _html
 * @param $elements
 * @return HTMLElement
 */
function _html (...$elements) {
    return new HTMLElement(":html", $elements);
}
/**
 * HTML TAG _i
 * @param $elements
 * @return HTMLElement
 */
function _i (...$elements) {
    return new HTMLElement(":i", $elements);
}
/**
 * HTML TAG _iframe
 * @param $elements
 * @return HTMLElement
 */
function _iframe (...$elements) {
    return new HTMLElement(":iframe", $elements);
}
/**
 * HTML TAG _img
 * @param $elements
 * @return HTMLElement
 */
function _img (...$elements) {
    return new HTMLElement(":img/", $elements);
}
/**
 * HTML TAG _input
 * @param $elements
 * @return HTMLElement
 */
function _input (...$elements) {
    return new HTMLElement(":input", $elements);
}
/**
 * HTML TAG _ins
 * @param $elements
 * @return HTMLElement
 */
function _ins (...$elements) {
    return new HTMLElement(":ins", $elements);
}
/**
 * HTML TAG _isindex
 * @param $elements
 * @return HTMLElement
 */
function _isindex (...$elements) {
    return new HTMLElement(":isindex", $elements);
}
/**
 * HTML TAG _kbd
 * @param $elements
 * @return HTMLElement
 */
function _kbd (...$elements) {
    return new HTMLElement(":kbd", $elements);
}
/**
 * HTML TAG _keygen
 * @param $elements
 * @return HTMLElement
 */
function _keygen (...$elements) {
    return new HTMLElement(":keygen", $elements);
}
/**
 * HTML TAG _label
 * @param $elements
 * @return HTMLElement
 */
function _label (...$elements) {
    return new HTMLElement(":label", $elements);
}
/**
 * HTML TAG _legend
 * @param $elements
 * @return HTMLElement
 */
function _legend (...$elements) {
    return new HTMLElement(":legend", $elements);
}
/**
 * HTML TAG _li
 * @param $elements
 * @return HTMLElement
 */
function _li (...$elements) {
    return new HTMLElement(":li", $elements);
}
/**
 * HTML TAG _link
 * @param $elements
 * @return HTMLElement
 */
function _link (...$elements) {
    return new HTMLElement(":link", $elements);
}
/**
 * HTML TAG _map
 * @param $elements
 * @return HTMLElement
 */
function _map (...$elements) {
    return new HTMLElement(":map", $elements);
}
/**
 * HTML TAG _mark
 * @param $elements
 * @return HTMLElement
 */
function _mark (...$elements) {
    return new HTMLElement(":mark", $elements);
}
/**
 * HTML TAG _menu
 * @param $elements
 * @return HTMLElement
 */
function _menu (...$elements) {
    return new HTMLElement(":menu", $elements);
}
/**
 * HTML TAG _meta
 * @param $elements
 * @return HTMLElement
 */
function _meta (...$elements) {
    return new HTMLElement(":meta/", $elements);
}
/**
 * HTML TAG _meter
 * @param $elements
 * @return HTMLElement
 */
function _meter (...$elements) {
    return new HTMLElement(":meter", $elements);
}
/**
 * HTML TAG _nav
 * @param $elements
 * @return HTMLElement
 */
function _nav (...$elements) {
    return new HTMLElement(":nav", $elements);
}
/**
 * HTML TAG _noframes
 * @param $elements
 * @return HTMLElement
 */
function _noframes (...$elements) {
    return new HTMLElement(":noframes", $elements);
}
/**
 * HTML TAG _noscript
 * @param $elements
 * @return HTMLElement
 */
function _noscript (...$elements) {
    return new HTMLElement(":noscript", $elements);
}
/**
 * HTML TAG _object
 * @param $elements
 * @return HTMLElement
 */
function _object (...$elements) {
    return new HTMLElement(":object", $elements);
}
/**
 * HTML TAG _ol
 * @param $elements
 * @return HTMLElement
 */
function _ol (...$elements) {
    return new HTMLElement(":ol", $elements);
}
/**
 * HTML TAG _optgroup
 * @param $elements
 * @return HTMLElement
 */
function _optgroup (...$elements) {
    return new HTMLElement(":optgroup", $elements);
}
/**
 * HTML TAG _option
 * @param $elements
 * @return HTMLElement
 */
function _option (...$elements) {
    return new HTMLElement(":option", $elements);
}
/**
 * HTML TAG _output
 * @param $elements
 * @return HTMLElement
 */
function _output (...$elements) {
    return new HTMLElement(":output", $elements);
}
/**
 * HTML TAG _p
 * @param $elements
 * @return HTMLElement
 */
function _p (...$elements) {
    return new HTMLElement(":p", $elements);
}
/**
 * HTML TAG _param
 * @param $elements
 * @return HTMLElement
 */
function _param (...$elements) {
    return new HTMLElement(":param", $elements);
}
/**
 * HTML TAG _pre
 * @param $elements
 * @return HTMLElement
 */
function _pre (...$elements) {
    return new HTMLElement(":pre", $elements);
}
/**
 * HTML TAG _progress
 * @param $elements
 * @return HTMLElement
 */
function _progress (...$elements) {
    return new HTMLElement(":progress", $elements);
}
/**
 * HTML TAG _q
 * @param $elements
 * @return HTMLElement
 */
function _q (...$elements) {
    return new HTMLElement(":q", $elements);
}
/**
 * HTML TAG _rp
 * @param $elements
 * @return HTMLElement
 */
function _rp (...$elements) {
    return new HTMLElement(":rp", $elements);
}
/**
 * HTML TAG _rt
 * @param $elements
 * @return HTMLElement
 */
function _rt (...$elements) {
    return new HTMLElement(":rt", $elements);
}
/**
 * HTML TAG _ruby
 * @param $elements
 * @return HTMLElement
 */
function _ruby (...$elements) {
    return new HTMLElement(":ruby", $elements);
}
/**
 * HTML TAG _s
 * @param $elements
 * @return HTMLElement
 */
function _s (...$elements) {
    return new HTMLElement(":s", $elements);
}
/**
 * HTML TAG _samp
 * @param $elements
 * @return HTMLElement
 */
function _samp (...$elements) {
    return new HTMLElement(":samp", $elements);
}
/**
 * HTML TAG _script
 * @param $elements
 * @return HTMLElement
 */
function _script (...$elements) {
    return new HTMLElement(":script", $elements);
}
/**
 * HTML TAG _section
 * @param $elements
 * @return HTMLElement
 */
function _section (...$elements) {
    return new HTMLElement(":section", $elements);
}
/**
 * HTML TAG _select
 * @param $elements
 * @return HTMLElement
 */
function _select (...$elements) {
    return new HTMLElement(":select", $elements);
}
/**
 * HTML TAG _small
 * @param $elements
 * @return HTMLElement
 */
function _small (...$elements) {
    return new HTMLElement(":small", $elements);
}
/**
 * HTML TAG _source
 * @param $elements
 * @return HTMLElement
 */
function _source (...$elements) {
    return new HTMLElement(":source", $elements);
}
/**
 * HTML TAG _span
 * @param $elements
 * @return HTMLElement
 */
function _span (...$elements) {
    return new HTMLElement(":span", $elements);
}
/**
 * HTML TAG _strike
 * @param $elements
 * @return HTMLElement
 */
function _strike (...$elements) {
    return new HTMLElement(":strike", $elements);
}
/**
 * HTML TAG _strong
 * @param $elements
 * @return HTMLElement
 */
function _strong (...$elements) {
    return new HTMLElement(":strong", $elements);
}
/**
 * HTML TAG _style
 * @param $elements
 * @return HTMLElement
 */
function _style (...$elements) {
    return new HTMLElement(":style", $elements);
}
/**
 * HTML TAG _sub
 * @param $elements
 * @return HTMLElement
 */
function _sub (...$elements) {
    return new HTMLElement(":sub", $elements);
}
/**
 * HTML TAG _sup
 * @param $elements
 * @return HTMLElement
 */
function _sup (...$elements) {
    return new HTMLElement(":sup", $elements);
}
/**
 * HTML TAG _table
 * @param $elements
 * @return HTMLElement
 */
function _table (...$elements) {
    return new HTMLElement(":table", $elements);
}
/**
 * HTML TAG _tbody
 * @param $elements
 * @return HTMLElement
 */
function _tbody (...$elements) {
    return new HTMLElement(":tbody", $elements);
}
/**
 * HTML TAG _td
 * @param $elements
 * @return HTMLElement
 */
function _td (...$elements) {
    return new HTMLElement(":td", $elements);
}
/**
 * HTML TAG _textarea
 * @param $elements
 * @return HTMLElement
 */
function _textarea (...$elements) {
    return new HTMLElement(":textarea", $elements);
}
/**
 * HTML TAG _tfoot
 * @param $elements
 * @return HTMLElement
 */
function _tfoot (...$elements) {
    return new HTMLElement(":tfoot", $elements);
}
/**
 * HTML TAG _th
 * @param $elements
 * @return HTMLElement
 */
function _th (...$elements) {
    return new HTMLElement(":th", $elements);
}
/**
 * HTML TAG _thead
 * @param $elements
 * @return HTMLElement
 */
function _thead (...$elements) {
    return new HTMLElement(":thead", $elements);
}
/**
 * HTML TAG _time
 * @param $elements
 * @return HTMLElement
 */
function _time (...$elements) {
    return new HTMLElement(":time", $elements);
}
/**
 * HTML TAG _title
 * @param $elements
 * @return HTMLElement
 */
function _title (...$elements) {
    return new HTMLElement(":title", $elements);
}
/**
 * HTML TAG _tr
 * @param $elements
 * @return HTMLElement
 */
function _tr (...$elements) {
    return new HTMLElement(":tr", $elements);
}
/**
 * HTML TAG _track
 * @param $elements
 * @return HTMLElement
 */
function _track (...$elements) {
    return new HTMLElement(":track", $elements);
}
/**
 * HTML TAG _tt
 * @param $elements
 * @return HTMLElement
 */
function _tt (...$elements) {
    return new HTMLElement(":tt", $elements);
}
/**
 * HTML TAG _u
 * @param $elements
 * @return HTMLElement
 */
function _u (...$elements) {
    return new HTMLElement(":u", $elements);
}
/**
 * HTML TAG _ul
 * @param $elements
 * @return HTMLElement
 */
function _ul (...$elements) {
    return new HTMLElement(":ul", $elements);
}
/**
 * HTML TAG _var
 * @param $elements
 * @return HTMLElement
 */
function _var (...$elements) {
    return new HTMLElement(":var", $elements);
}
/**
 * HTML TAG _video
 * @param $elements
 * @return HTMLElement
 */
function _video (...$elements) {
    return new HTMLElement(":video", $elements);
}
/**
 * HTML TAG _wbr
 * @param $elements
 * @return HTMLElement
 */
function _wbr (...$elements) {
    return new HTMLElement(":wbr", $elements);
}