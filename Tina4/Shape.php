<?php

namespace Tina4;

/**
 * The basic shape element class to handle inheritance
 */
class shapeBaseElement {

    private $id;
    private $parent;
    private $keyValue;
    private $keyName;
    private $parentElement;

    function __construct($keyName = "", $keyValue = "") {
        $this->id = uniqid();
        $this->parent = "";
        $this->keyName = $keyName;
        $this->keyValue = $keyValue;
        $this->registerGlobal();
    }

    function __clone() {
        if (empty($this->parent)) {
            $this->parent = $this->id;
            $this->id = uniqid();
        }
        $this->registerGlobal();
    }

    function registerGlobal() {
        $GLOBALS["shapeElements"][$this->id] = $this;
    }

    function getParent() {
        return $this->parent;
    }

    function getValue() {
        return $this->keyValue;
    }

    function getKey() {
        return $this->keyName;
    }

    function getId() {
        return $this->id;
    }

    function setParent($value = null) {
        $this->parent = $value;
    }

    function setValue($value) {
        $this->keyValue = $value;
    }

    function setKey($value) {
        $this->keyName = $value;
    }

    function setParentElement($parentElement) {
        $this->parentElement = $parentElement;
    }

    function getParentElement() {
        return $this->parentElement;
    }


}

/**
 * The htmlElement class which handles the HTML compilation etc
 */
class htmlElement extends shapeBaseElement {

    private $openingTag = "";
    private $closingTag = "";
    private $attributes;
    private $content;
    private $compress;

    private $DS; //data sources for templating

    /**
     * Compile all the Attributes
     * @return string
     */
    function compileAttributes() {
        $html = "";
        if (!empty($this->attributes)) {
            foreach ($this->attributes as $aid => $attribute) {
                if (!is_array($attribute->getValue())) {
                    $html .= ' ' . $attribute->getKey() . '="' . $attribute->getValue() . '"';
                }
            }
        }
        return $html;
    }

    /**
     * Compile the content for the Element
     * @param type $acontent
     * @return type
     */
    function compileContent($acontent = null) {
        $html = "";
        if (!empty($acontent)) {
            foreach ($acontent as $cid => $content) {
                if (is_object($content) && get_class($content) === "shapeBaseElement") {
                    if (is_object($content->getValue()) && get_class($content->getValue()) === "htmlElement") {
                        $html .= $content->getValue()->compileHTML();
                    } else {

                        if (is_array($content->getValue())) {
                            foreach ($content->getValue() as $ccid => $ccontent) {
                                if (is_object($ccontent) && get_class($ccontent) === "htmlElement") {
                                    $html .= $ccontent->compileHTML();
                                }
                            }
                        } else {
                            $html .= $content->getValue();
                        }
                    }
                }
            }
        }
        return $html;
    }


    function templateHTML ($content, $object) {
        foreach ($object as $keyName => $keyValue) {
            $content = str_ireplace('{'.$keyName.'}', $keyValue, $content);
        }

        return $content;
    }

    /**
     * Compiling HTML
     * @return type
     */
    function compileHTML() {
        $html = "";
        $attributes = $this->compileAttributes();
        $html .= str_ireplace("[attributes]", $attributes, $this->openingTag);
        $content = $this->compileContent($this->content);

        if (!empty($this->DS)) {
            foreach ($this->DS as $dsName => $ds) {
                if (is_array($ds)) {
                    //not implemented yet
                    foreach ($ds as $tid => $template) {
                        $content = $this->templateHTML ($content, $template);
                    }
                }
                else {
                    $content = $this->templateHTML ($content, $ds);
                }
            }
        }

        if ($this->compress) {
            $content = (new JSmin())->minify($content);
        }
        $html .= $content;
        $html .= str_ireplace("[attributes]", $attributes, $this->closingTag);
        return $html;
    }

    /**
     * Make HTML from the Object
     * @return type
     */
    function __toString() {
        return $this->compileHTML();
    }

    /**
     * Function to check is an array is an associative or not
     * @param type $array
     * @return type
     */
    function is_assoc($array) {
        return (bool) count(array_filter(array_keys($array), 'is_string'));
    }

    /**
     * Parse all the Arguments passed to the class, see if they are content or attributes
     * @param type $arg
     */
    function parseArgument($arg) {
        if (is_array($arg) && $this->is_assoc($arg) && !empty($arg)) {
            foreach ($arg as $keyName => $keyValue) {
                $this->attributes[] = new shapeBaseElement($keyName, $keyValue);
            }

        } else {

            $child = new shapeBaseElement("content", $arg);
            $child->setParentElement($this);
            $this->content[] = $child;
        }
    }

    /**
     * Constructor for HTMLElement
     */
    function __construct() {
        parent::__construct();
        $args = func_get_args();
        foreach ($args as $arg) {
            $this->parseArgument($arg);
        }
    }

    /**
     * Cloning the Object
     */
    function __clone() {
        parent::__clone();
        $this->cloneChildren($this);
    }

    /**
     * Clone All the Children
     * @param type $element
     */
    function cloneChildren($element) {
        if (!empty($element->attributes)) {
            foreach ($element->attributes as $aid => $attribute) {
                if (empty($attribute->getParent())) {
                    $element->attributes[$aid] = clone $attribute;
                }
            }
        }
        if (!empty($element->content)) {
            foreach ($element->content as $cid => $content) {

                $element->content[$cid] = clone $content;

                if (is_object($content) && get_class($content) === "shapeBaseElement") {

                    if (is_object($element->content[$cid]->getValue()) && get_class($element->content[$cid]->getValue()) == "htmlElement") {

                        $element->content[$cid]->setValue(clone $element->content[$cid]->getValue());
                        $this->cloneChildren($element->content[$cid]->getValue());
                    }
                }
            }
        }
    }

    /**
     * Setting Content
     * @param type $value
     */
    function setContent($value) {
        if (count($this->content) == 1) {
            $this->content[0]->setValue($value);
            if (!empty($this->content[0]->getParent())) {

                $this->content[0]->setParent();
            }
        } else {

            $content = new shapeBaseElement("content", $value);
            $this->content = [$content];
            //find all the children of this element and add the attribute
            foreach ($GLOBALS["shapeElements"] as $eid => $element) {
                if ($element->getParent() === $this->getId()) {
                    $element->cloneContent($content);
                }
            }
        }
        $this->setInherited();
    }

    /**
     * Getting the content for the Element
     * @return type
     */
    function getContent() {
        return $this->content;
    }

    /**
     * Adding Content
     * @param type $value
     */
    function addContent($value) {
        $this->content[] = new shapeBaseElement("content", $value);
    }

    /**
     * BySearch - internal function to find elements
     */
    function bySearch($keyName, $keyIndex) {
        $result = null;
        if (!empty($this->attributes)) {
            foreach ($this->attributes as $aid => $attribute) {

                if (strtoupper($attribute->getKey()) === strtoupper($keyIndex) && $attribute->getValue() === $keyName) {

                    $result = $this;
                }
            }
        }
        if (empty($result)) {
            if (!empty($this->content)) {
                foreach ($this->content as $cid => $content) {
                    if (is_object($content) && get_class($content) === "shapeBaseElement") {
                        if (is_object($this->content[$cid]->getValue()) && get_class($this->content[$cid]->getValue()) == "htmlElement") {
                            $result = $this->content[$cid]->getValue()->bySearch($keyName, $keyIndex);
                            if (!empty($result)) {
                                break;
                            }
                        }
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Find and Element by Its HTML Id
     * Example: p(["id" => "Test"])
     * @param type $keyName
     * @return \htmlElement
     */
    function byId($keyName) {
        return $this->bySearch($keyName, "id");
    }

    /**
     * Find and Element by Its HTML Class
     * Example: p(["clas" => "Test"])
     * @param type $keyName
     * @return \htmlElement
     */
    function byClass($keyName) {
        return $this->bySearch($keyName, "class");
    }

    function byFor($keyName) {
        return $this->bySearch($keyName, "for");
    }

    /**
     * Set inherited properties
     */
    function setInherited() {
        if (!empty($this->attributes)) {
            foreach ($this->attributes as $cid => $attribute) {
                if (is_object($attribute) && get_class($attribute) === "shapeBaseElement") {
                    //update all the children to have my value
                    foreach ($GLOBALS["shapeElements"] as $sid => $element) {
                        if ($element->getParent() === $attribute->getId()) {
                            $element->setValue($attribute->getValue());
                        }
                    }
                }
            }
        }
        if (!empty($this->content)) {
            foreach ($this->content as $cid => $content) {
                if (is_object($content) && get_class($content) === "shapeBaseElement") {
                    //update all the children to have my value
                    foreach ($GLOBALS["shapeElements"] as $sid => $element) {
                        if ($element->getParent() === $content->getId()) {
                            if (is_object($element) && get_class($element) == "shapeBaseElement") {
                                if (is_object($element->getValue()) && get_class($element->getValue()) === "htmlElement") {
                                    $this->content[$cid]->getValue()->setInherited();
                                } else {
                                    $element->setValue($content->getValue());
                                }
                            }
                        }
                    }

                    if (is_object($this->content[$cid]->getValue()) && get_class($this->content[$cid]->getValue()) === "htmlElement") {
                        if ($element->getParent() === $content->getId()) {

                        }
                    }
                }
            }
        }
    }

    /**
     * Set attributes to the Element
     * @param type $keyName
     * @param type $keyValue
     */
    function setAttribute($keyName, $keyValue) {
        $wasSet = false;
        if (!empty($this->attributes)) {
            foreach ($this->attributes as $aid => $attribute) {
                if ($attribute->getKey() === $keyName) {
                    if (!empty($attribute->getParent())) {
                        $this->attributes[$aid] = clone $attribute;
                    }
                    $this->attributes[$aid]->setValue($keyValue);
                    $this->attributes[$aid]->setParent();
                    $this->setInherited();
                    $wasSet = true;
                }
            }
        }

        if (!$wasSet) {
            $attribute = new shapeBaseElement($keyName, $keyValue);
            $this->attributes[] = $attribute;
            //find all the children of this element and add the attribute
            foreach ($GLOBALS["shapeElements"] as $eid => $element) {
                if ($element->getParent() === $this->getId()) {
                    $element->cloneAttribute($attribute);
                }
            }

            return $attribute;
        }
    }

    /**
     * Clones a new attribute onto the child
     * @param type $attribute
     */
    function cloneAttribute($attribute) {
        $this->attributes[] = clone $attribute;
    }

    /**
     * Clones content
     * @param type $content
     */
    function cloneContent($content) {
        $this->content[] = clone $content;
    }

    /**
     * Add Attributes to the Element
     * @param type $keyName
     * @param type $keyValue
     */
    function addAttribute($keyName, $keyValue) {
        $wasSet = false;
        if (!empty($this->attributes)) {
            foreach ($this->attributes as $aid => $attribute) {
                if ($attribute->getKey() === $keyName) {
                    if (!empty($attribute->getParent())) {
                        $this->attributes[$aid] = clone $attribute;
                    }
                    $this->attributes[$aid]->setValue($this->attributes[$aid]->getValue()." ".$keyValue);
                    $this->attributes[$aid]->setParent();
                    $this->setInherited();
                    $wasSet = true;
                }
            }
        }

        if (!$wasSet) {
            $attribute = new shapeBaseElement($keyName, $keyValue);
            $this->attributes[] = $attribute;
            //find all the children of this element and add the attribute
            foreach ($GLOBALS["shapeElements"] as $eid => $element) {
                if ($element->getParent() === $this->getId()) {
                    $element->cloneAttribute($attribute);
                }
            }

            return $attribute;
        }
    }

    /**
     * Add Opening and Closing Tags
     * @param type $openingTag
     * @param type $closingTag
     */
    function setTags($openingTag, $closingTag, $compress = false) {
        $this->openingTag = $openingTag;
        $this->closingTag = $closingTag;
        $this->compress = $compress;
    }

    /**
     * Function to evaluate a key value pair for a IDENTICAL MATCH - if it is true it returns the current object
     * @param type $args
     * @return \htmlElement
     */
    function whenEqual ($args) {
        if (is_array($args)) {
            $isTrue = true;
            foreach ($args as $key => $value) {
                if ($key !== $value) {
                    $isTrue = false;
                    break;
                }

            }
            if ($isTrue) {
                return $this;
            }
            else {
                return (new htmlElement());
            }
        }
        else {
            return (new htmlElement());
        }

    }

    /**
     * Function to evaluate a key value pair for a LIKE MATCH - if it is true it returns the current object
     * @param type $args
     * @return \htmlElement
     */
    function whenLike ($args) {
        if (is_array($args)) {
            $isTrue = true;
            foreach ($args as $key => $value) {
                if ( stripos($key, $value) === false) {
                    $isTrue = false;
                    break;
                }

            }

            if ($isTrue) {
                return $this;
            }
            else {
                return (shape());
            }
        }
        else {
            return (shape());
        }

    }


    /**
     * Function to evaluate a key value pair for any valid boolean expression, all evaluations should be true
     * @param type $args
     * @return \htmlElement
     */
    function whenOr ($args) {
        if (is_array($args)) {
            $isTrue = false;
            foreach ($args as $key => $value) {
                if ($value) {
                    $isTrue = true;
                    break;
                }
            }

            if ($isTrue) {
                return $this;
            }
            else {
                return (shape());
            }
        }
        else {
            return (shape());
        }

    }


    /**
     * Function to evaluate a key value pair for any valid boolean expression, all evaluations should be true
     * @param type $args
     * @return \htmlElement
     */
    function whenAnd ($args) {
        if (is_array($args)) {
            $isTrue = true;
            foreach ($args as $key => $value) {
                if (!$value) {
                    $isTrue = false;
                    break;
                }
            }

            if ($isTrue) {
                return $this;
            }
            else {
                return (shape());
            }
        }
        else {
            return (shape());
        }

    }

    /**
     * Add a datasource to the shape object for templating
     * @param type $object
     */
    function addDataSource ( $name, $object ) {
        $this->DS[$name] = $object;
    }





}

/**
 * Create a dynamic instance of a class
 * @param String $class Name of the class
 * @param String $params Params of the class
 * @return Class The new class
 */
function createInstance($class, $params) {
    $reflection_class = new ReflectionClass($class);
    return $reflection_class->newInstanceArgs($params);
}

function getHTMLAttributes($element) {
    $result = [];
    if (!empty($element->attributes)) {
        foreach ($element->attributes as $keyName => $keyValue) {
            $result[] = [$keyName => $keyValue->value];
        }
    }
    return $result;
}

function getHTMLText($element) {
    $result = "";
    if (!empty($element->childNodes)) {
        foreach (range(0, $element->childNodes->length - 1) as $idx) {
            if (!empty($element->childNodes->item($idx))) {
                if ($element->childNodes->item($idx)->nodeType == 3) {
                    $result .= $element->childNodes->item($idx)->nodeValue;
                }
            }
        }
    }
    return $result;
}

function traverseDOM($element) {
    $result = [];
    foreach ($element as $subelement) {
        if (!empty($subelement->tagName)) {
            $result[] = (object) ["tag" => $subelement->tagName, "attributes" => getHTMLAttributes($subelement), "content" => getHTMLText($subelement), "children" => traverseDOM($subelement->childNodes)];
        }
    }
    return $result;
}

function parseHTMLText($content) {
    $result = "";
    if (!empty($content)) {
        $result = '"' . $content . '"';
    } else {
        $result = "\n";
    }
    return $result;
}

/**
 * Parse all the HTML Attributes
 * @param type $attributes
 * @return string
 */
function parseHTMLAttributes($attributes) {
    $result = "";
    if (!empty($attributes)) {
        $result = [];
        foreach ($attributes as $aid => $attribute) {
            foreach ($attribute as $key => $value) {
                $result[] = '"' . $key . '"=>"' . $value . '"';
            }
        }
        $result = '[' . join(",", $result) . '],';
    }

    return $result;
}

/**
 * Make Shape code from an Array
 * @param type $shapeArray
 * @param type $level
 * @return string
 */
function arrayToShapeCode($shapeArray, $level = 0) {
    $code = "";
    $level++;
    $padding = str_repeat(" ", $level * 2);
    foreach ($shapeArray as $aid => $shape) {
        $code .= $padding . $shape->tag . '(' . parseHTMLAttributes($shape->attributes) . parseHTMLText($shape->content) . arrayToShapeCode($shape->children, $level) . $padding . ")";
        if ($aid < count($shapeArray) - 1) {
            $code .= ",\n";
        }
    }

    $code .= "\n";
    return $code;
}

/**
 * Convert the HTML content to Shape Code
 * @param String $content HTML from a website
 * @return String Shape code
 */
function HTMLtoShape($content) {
    $dom = new DOMDocument;
    @$dom->loadHTML($content);
    $document = $dom->childNodes;
    $shapeArray = traverseDOM($document);
    $shapeCode = arrayToShapeCode($shapeArray);
    return $shapeCode;
}

/**
 * Loop to iterate through a shape template and replace content
 * Example loop ( $names,  b ("{name}"), "!empty($name),strlen($name) > 5" );
 * @param type $elements
 * @param type $shapeTemplate
 * @param type $expression
 */
function loop($elements, $shapeTemplate, $expressions = "") {
    $html = "";
    foreach ($elements as $eid => $element) {
        $template = $shapeTemplate->compileHtml();
        foreach ($element as $key => $value) {
            $template = str_ireplace("{{$key}}", $value, $template);
        }
        $html .= $template;
    }
    return $html;
}

/**
 * Anchor function
 * The <a> tag defines a hyperlink, which is used to link from one page to another.
 * @return htmlElement
 */
function a() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<A[attributes]>", "</A>");
    return $html;
}

/**
 * Abbr function
 * The <abbr> tag defines an abbreviation or an acronym, like "Mr.", "Dec.", "ASAP", "ATM".
 * @return htmlElement
 */
function abbr() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<ABBR[attributes]>", "</ABBR>");
    return $html;
}

/**
 * Acronym function
 * The <acronym> tag is not supported in HTML5. Use the <abbr> tag instead.
 * @return htmlElement
 */
function acronym() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<ACRONYM[attributes]>", "</ACRONYM>");
    $html->setUnsupported();
    return $html;
}

/**
 * Address function
 * The <address> tag defines the contact information for the author/owner of a document or an article.
 * @return htmlElement
 */
function address() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<ADDRESS[attributes]>", "</ADDRESS>");
    return $html;
}

/**
 * Applet function - not supported in HTML5
 * The <applet> tag is not supported in HTML5. Use the <object> tag instead.
 * @return htmlElement
 */
function applet() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<APPLET[attributes]>", "</APPLET>");
    $html->setUnsupported();
    return $html;
}

/**
 * Area function
 * The <area> tag defines an area inside an image-map (an image-map is an image with clickable areas).
 * The <area> element is always nested inside a <map> tag.
 * @return htmlElement
 */
function area() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<AREA[attributes]>", "");
    return $html;
}

/**
 * Article function
 * The <article> tag specifies independent, self-contained content.
 * @return htmlElement
 */
function article() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<ARTICLE[attributes]>", "</ARTICLE>");
    return $html;
}

/**
 * Aside function
 * The <aside> tag defines some content aside from the content it is placed in.
 * @return htmlElement
 */
function aside() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<ASIDE[attributes]>", "</ASIDE>");
    return $html;
}

/**
 * Audio function
 * The <audio> tag defines sound, such as music or other audio streams.
 * @return htmlElement
 */
function audio() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<AUDIO CONTROLS[attributes]>", "</AUDIO>");
    return $html;
}

/**
 * Bold function
 * The <b> tag specifies bold text.
 * @return htmlElement
 */
function b() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<B[attributes]>", "</B>");
    return $html;
}

/**
 * Base function
 * Specify a default URL and a default target for all links on a page
 * @return type
 */
function base() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<BASE[attributes]>", "");
    return $html;
}

/**
 * Basefont function
 * The <basefont> tag is not supported in HTML5. Use CSS instead.
 * @return type
 */
function basefont() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<BASEFONT[attributes]>", "");
    $html->setUnsupported();
    return $html;
}

/**
 * BDI function
 * The <bdi> tag isolates a part of text that might be formatted in a different direction from other text outside it.
 * @return type
 */
function bdi() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<BDI[attributes]>", "</BDI>");
    return $html;
}

/**
 * BDO function
 * The <bdo> tag is used to override the current text direction.
 * @return type
 */
function bdo() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<BDO[attributes]>", "</BDO>");
    return $html;
}

/**
 * Big function
 * The <big> tag is not supported in HTML5. Use CSS instead.
 * @return type
 */
function big() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<BIG[attributes]>", "</BIG>");
    $html->setUnsupported();
    return $html;
}

/**
 * BlockQuote function
 * The <blockquote> tag specifies a section that is quoted from another source.
 * @return type
 */
function blockquote() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<BLOCKQUOTE[attributes]>", "</BLOCKQUOTE>");
    return $html;
}

/**
 * Body function
 * The <body> tag defines the document's body.
 * @return type
 */
function body() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<BODY[attributes]>", "</BODY>");
    return $html;
}

/**
 * Break tag
 * Use the <br> tag to enter line breaks, not to separate paragraphs.
 * @return type
 */
function br() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<BR[attributes]/>", "");
    return $html;
}

/**
 * Button function
 * The <button> tag defines a clickable button.
 * @return type
 */
function button() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<BUTTON[attributes]>", "</BUTTON>");
    return $html;
}

/**
 * Canvas function
 * The <canvas> tag is used to draw graphics, on the fly, via scripting (usually JavaScript).
 * @return type
 */
function canvas() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<CANVAS[attributes]>", "</CANVAS>");
    return $html;
}

/**
 * Caption function
 * The <caption> tag defines a table caption.
 * The <caption> tag must be inserted immediately after the <table> tag
 * @return type
 */
function caption() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<CAPTION[attributes]>", "</CAPTION>");
    return $html;
}

/**
 * Center function
 * The <center> tag is not supported in HTML5. Use CSS instead.
 * @return type
 */
function center() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<P[attributes]>", "</P>");
    $html->setUnsupported();
    return $html;
}

/**
 * Cite function
 * The <cite> tag defines the title of a work (e.g. a book, a song, a movie, a TV show, a painting, a sculpture, etc.).
 * @return type
 */
function cite() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<CITE[attributes]>", "</CITE>");
    return $html;
}

/**
 * Code function
 * The <code> tag is a phrase tag. It defines a piece of computer code.
 * @return type
 */
function code() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<CODE[attributes]>", "</CODE>");
    return $html;
}

/**
 * Col function
 * The <col> tag specifies column properties for each column within a <colgroup> element.
 * @return type
 */
function col() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<COL[attributes]>", "");
    return $html;
}

/**
 * Colgroup function
 * The <colgroup> tag specifies a group of one or more columns in a table for formatting.
 * @return type
 */
function colgroup() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<COLGROUP[attributes]>", "</COLGROUP>");
    return $html;
}

/**
 * Datalist function
 * The <datalist> tag specifies a list of pre-defined options for an <input> element.
 * @return type
 */
function datalist() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<DATALIST[attributes]>", "</DATALIST>");
    return $html;
}

function dd() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<DD[attributes]>", "</DD>");
    return $html;
}

/**
 * The <dl> tag defines a description list.
 * @return type
 */
function dl() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<DL[attributes]>", "</DL>");
    return $html;
}

function dt() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<DT[attributes]>", "</DT>");
    return $html;
}

/**
 * The <del> tag defines text that has been deleted from a document.
 * @return type
 */
function del() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<DEL[attributes]>", "</DEL>");
    return $html;
}

function details() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<DETAILS[attributes]>", "</DETAILS>");
    return $html;
}

function dfn() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<DFN[attributes]>", "</DFN>");
    return $html;
}

function dialog() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<DIALOG[attributes]>", "</DIALOG>");
    return $html;
}

/**
 * Dir function
 * The <dir> tag is not supported in HTML5. Use CSS instead.
 * @return type
 */
function dir() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<DIR[attributes]>", "</DIR>");
    $html->setUnsupported();
    return $html;
}

/**
 * Div function
 * The <div> tag defines a division or a section in an HTML document.
 * @return type
 */
function div() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<DIV[attributes]>", "</DIV>");
    return $html;
}

/**
 * Doctype for beginning of html page
 * The <!DOCTYPE> declaration must be the very first thing in your HTML document, before the <html> tag.
 * @return htmlElement
 */
function doctype() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<!DOCTYPE html[attributes]", ">");
    return $html;
}

/**
 * The <em> tag is a phrase tag. It renders as emphasized text.
 * @return type
 */
function em() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<P[attributes]>", "</P>");
    return $html;
}

/**
 * The <embed> tag defines a container for an external application or interactive content (a plug-in).
 * @return type
 */
function embed() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<EMBED[attributes]>", "");
    return $html;
}

/**
 * The <fieldset> tag is used to group related elements in a form.
 * @return type
 */
function fieldset() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<FIELDSET[attributes]>", "</FIELDSET>");
    return $html;
}

/**
 * The <figcaption> tag defines a caption for a <figure> element.
 * @return type
 */
function figcaption() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<FIGCAPTION[attributes]>", "</FIGCAPTION>");
    return $html;
}

/**
 * The <figure> tag specifies self-contained content, like illustrations, diagrams, photos, code listings, etc.
 * @return type
 */
function figure() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<FIGURE[attributes]>", "</FIGURE>");
    return $html;
}

function font() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<FONT[attributes]>", "</FONT>");
    $html->setUnsupported();
    return $html;
}

function footer() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<FOOTER[attributes]>", "</FOOTER>");
    return $html;
}

function form() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<FORM[attributes]>", "</FORM>");
    return $html;
}

function frame() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<FRAMESET[attributes]>", "");
    return $html;
}

function frameset() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<FRAMESET[attributes]>", "</FRAMESET>");
    $html->setUnsupported();
    return $html;
}

/**
 * The <head> element is a container for all the head elements.
 * @return type
 */
function head() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<HEAD[attributes]>", "</HEAD>");
    return $html;
}

/**
 * The <header> element represents a container for introductory content or a set of navigational links.
 * @return type
 */
function header() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<HEADER[attributes]>", "</HEADER>");
    return $html;
}

/**
 * The <hgroup> tag is used to group heading elements.
 * @return type
 */
function hgroup() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<HGROUP[attributes]>", "</HGROUP>");
    return $html;
}

/**
 * The <h1> to <h6> tags are used to define HTML headings.
 * @return htmlElement
 */
function h1() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<H1[attributes]>", "</H1>");
    return $html;
}

/**
 * The <h1> to <h6> tags are used to define HTML headings.
 * @return htmlElement
 */
function h2() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<H2[attributes]>", "</H2>");
    return $html;
}

/**
 * The <h1> to <h6> tags are used to define HTML headings.
 * @return htmlElement
 */
function h3() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<H3[attributes]>", "</H3>");
    return $html;
}

/**
 * The <h1> to <h6> tags are used to define HTML headings.
 * @return htmlElement
 */
function h4() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<H4[attributes]>", "</H4>");
    return $html;
}

/**
 * The <h1> to <h6> tags are used to define HTML headings.
 * @return htmlElement
 */
function h5() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<H5[attributes]>", "</H5>");
    return $html;
}

/**
 * The <h1> to <h6> tags are used to define HTML headings.
 * @return htmlElement
 */
function h6() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<H6[attributes]>", "</H6>");
    return $html;
}

/**
 * In HTML5, the <hr> tag defines a thematic break.
 */
function hr() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<HR[attributes] />", "");
    return $html;
}

/**
 * Html function
 * The <html> tag tells the browser that this is an HTML document.
 * @return type
 */
function html() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<HTML[attributes]>", "</HTML>");
    return $html;
}

/**
 * The <i> tag defines a part of text in an alternate voice or mood.
 * @return type
 */
function i() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<I[attributes]>", "</I>");
    return $html;
}

/**
 * The <iframe> tag specifies an inline frame.
 * @return type
 */
function iframe() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<IFRAME[attributes]>", "</IFRAME>");
    return $html;
}

function img() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<IMG[attributes]>", "");
    return $html;
}

function input() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<INPUT[attributes] value=\"", "\">");
    return $html;
}

function ins() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<INS[attributes]>", "</INS>");
    return $html;
}

function kbd() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<KBD[attributes]>", "</KBD>");
    return $html;
}

function keygen() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<KEYGEN[attributes]>", "");
    return $html;
}

function label() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<LABEL[attributes]>", "</LABEL>");
    return $html;
}

function legend() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<LEGEND[attributes]>", "</LEGEND>");
    return $html;
}

function li() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<LI[attributes]>", "</LI>");
    return $html;
}

function link() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<LINK[attributes]>", "");
    return $html;
}

function main() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<MAIN[attributes]>", "</MAIN>");
    return $html;
}

function map() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<MAP[attributes]>", "</MAP>");
    return $html;
}

function mark() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<MARK[attributes]>", "</MARK>");
    return $html;
}

function menu() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<MENU[attributes]>", "</MENU>");
    return $html;
}

function menuitem() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<MENUITEM[attributes]>", "</MENUITEM>");
    return $html;
}

function meta() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<META[attributes]>", "");
    return $html;
}

function meter() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<METER[attributes]>", "</METER>");
    return $html;
}

function nav() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<NAV[attributes]>", "</NAV>");
    return $html;
}

function noframes() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<NOFRAMES[attributes]>", "</NOFRAMES>");
    return $html;
}

function noscript() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<NOSCRIPT[attributes]>", "</NOSCRIPT>");
    return $html;
}

function object() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<OBJECT[attributes]>", "</OBJECT>");
    return $html;
}

function ol() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<OL[attributes]>", "</OL>");
    return $html;
}

function optgroup() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<OPTGROUP[attributes]>", "</OPTGROUP>");
    return $html;
}

function option() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<OPTION[attributes]>", "</OPTION>");
    return $html;
}

function output() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<OUTPUT[attributes]>", "</OUTPUT>");
    return $html;
}

function p() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<P[attributes]>", "</P>");
    return $html;
}

function param() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<PARAM[attributes]>", "");
    return $html;
}

function pre() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<PRE[attributes]>", "</PRE>");
    return $html;
}

function progress() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<PROGRESS[attributes]>", "</PROGRESS>");
    return $html;
}

function q() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<Q[attributes]>", "</Q>");
    return $html;
}

function rp() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<RP[attributes]>", "</RP>");
    return $html;
}

function rt() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<RT[attributes]>", "</RT>");
    return $html;
}

function ruby() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<RUBY[attributes]>", "</RUBY>");
    return $html;
}

function s() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<S[attributes]>", "</S>");
    return $html;
}

function samp() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<SAMP[attributes]>", "</SAMP>");
    return $html;
}

function script() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<SCRIPT[attributes]>", "</SCRIPT>", true);
    return $html;
}

function section() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<SECTION[attributes]>", "</SECTION>");
    return $html;
}

function select() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<SELECT[attributes]>", "</SELECT>");
    return $html;
}

/**
 * Container for HTML
 * @return type
 */
function shape() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("", "");
    return $html;
}

function small() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<SMALL[attributes]>", "</SMALL>");
    return $html;
}

function source() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<SOURCE[attributes]>", "");
    return $html;
}

function span() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<SPAN[attributes]>", "</SPAN>");
    return $html;
}

function strike() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<STRIKE[attributes]>", "</STRIKE>");
    return $html;
}

function strong() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<STRONG[attributes]>", "</STRONG>");
    return $html;
}

function style() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<STYLE[attributes]>", "</STYLE>");
    return $html;
}

function sub() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<SUB[attributes]>", "</SUB>");
    return $html;
}

function summary() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<SUMMARY[attributes]>", "</SUMMARY>");
    return $html;
}

function sup() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<SUP[attributes]>", "</SUP>");
    return $html;
}

function table() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<TABLE[attributes]>", "</TABLE>");
    return $html;
}

function tbody() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<TBODY[attributes]>", "</TBODY>");
    return $html;
}

function td() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<TD[attributes]>", "</TD>");
    return $html;
}

function textarea() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<TEXTAREA[attributes]>", "</TEXTAREA>");
    return $html;
}

function tfoot() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<TFOOT[attributes]>", "</TFOOT>");
    return $html;
}

function th() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<TH[attributes]>", "</TH>");
    return $html;
}

function thead() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<THEAD[attributes]>", "</THEAD>");
    return $html;
}

function time() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<TIME[attributes]>", "</TIME>");
    return $html;
}

function title() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<TITLE[attributes]>", "</TITLE>");
    return $html;
}

function tr() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<TR[attributes]>", "</TR>");
    return $html;
}

function track() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<TRACK[attributes]>", "");
    return $html;
}

function tt() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<TT[attributes]>", "</TT>");
    return $html;
}

function u() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<U[attributes]>", "</U>");
    return $html;
}

function ul() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<UL[attributes]>", "</UL>");
    return $html;
}

function vari() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<VAR[attributes]>", "</VAR>");
    return $html;
}

function video() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<VIDEO[attributes] controls>", "</VIDEO>");
    return $html;
}

function wbr() {
    $html = createInstance("htmlElement", func_get_args());
    $html->setTags("<WBR[attributes]>", "</WBR>");
    return $html;
}

/**
 * JSMin.php - modified PHP implementation of Douglas Crockford's JSMin.
 *
 * <code>
 * $minifiedJs = JSMin::minify($js);
 * </code>
 *
 * This is a modified port of jsmin.c. Improvements:
 *
 * Does not choke on some regexp literals containing quote characters. E.g. /'/
 *
 * Spaces are preserved after some add/sub operators, so they are not mistakenly
 * converted to post-inc/dec. E.g. a + ++b -> a+ ++b
 *
 * Preserves multi-line comments that begin with /*!
 *
 * PHP 5 or higher is required.
 *
 * Permission is hereby granted to use this version of the library under the
 * same terms as jsmin.c, which has the following license:
 *
 * --
 * Copyright (c) 2002 Douglas Crockford  (www.crockford.com)
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * The Software shall be used for Good, not Evil.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * --
 *
 * @package JSMin
 * @author Ryan Grove <ryan@wonko.com> (PHP port)
 * @author Steve Clay <steve@mrclay.org> (modifications + cleanup)
 * @author Andrea Giammarchi <http://www.3site.eu> (spaceBeforeRegExp)
 * @copyright 2002 Douglas Crockford <douglas@crockford.com> (jsmin.c)
 * @copyright 2008 Ryan Grove <ryan@wonko.com> (PHP port)
 * @license http://opensource.org/licenses/mit-license.php MIT License
 * @link http://code.google.com/p/jsmin-php/
 */

class JSMin {
    const ORD_LF            = 10;
    const ORD_SPACE         = 32;
    const ACTION_KEEP_A     = 1;
    const ACTION_DELETE_A   = 2;
    const ACTION_DELETE_A_B = 3;

    protected $a           = "\n";
    protected $b           = '';
    protected $input       = '';
    protected $inputIndex  = 0;
    protected $inputLength = 0;
    protected $lookAhead   = null;
    protected $output      = '';
    protected $lastByteOut  = '';
    protected $keptComment = '';

    /**
     * Minify Javascript.
     *
     * @param string $js Javascript to be minified
     *
     * @return string
     */
    public static function minify($js)
    {
        $jsmin = new JSMin($js);
        return $jsmin->min();
    }

    /**
     * @param string $input
     */
    public function __construct($input="")
    {
        $this->input = $input;
    }

    /**
     * Perform minification, return result
     *
     * @return string
     */
    public function min()
    {
        if ($this->output !== '') { // min already run
            return $this->output;
        }

        $mbIntEnc = null;
        if (function_exists('mb_strlen') && ((int)ini_get('mbstring.func_overload') & 2)) {
            $mbIntEnc = mb_internal_encoding();
            mb_internal_encoding('8bit');
        }
        $this->input = str_replace("\r\n", "\n", $this->input);
        $this->inputLength = strlen($this->input);

        $this->action(self::ACTION_DELETE_A_B);

        while ($this->a !== null) {
            // determine next command
            $command = self::ACTION_KEEP_A; // default
            if ($this->a === ' ') {
                if (($this->lastByteOut === '+' || $this->lastByteOut === '-')
                    && ($this->b === $this->lastByteOut)) {
                    // Don't delete this space. If we do, the addition/subtraction
                    // could be parsed as a post-increment
                } elseif (! $this->isAlphaNum($this->b)) {
                    $command = self::ACTION_DELETE_A;
                }
            } elseif ($this->a === "\n") {
                if ($this->b === ' ') {
                    $command = self::ACTION_DELETE_A_B;

                    // in case of mbstring.func_overload & 2, must check for null b,
                    // otherwise mb_strpos will give WARNING
                } elseif ($this->b === null
                    || (false === strpos('{[(+-!~', $this->b)
                        && ! $this->isAlphaNum($this->b))) {
                    $command = self::ACTION_DELETE_A;
                }
            } elseif (! $this->isAlphaNum($this->a)) {
                if ($this->b === ' '
                    || ($this->b === "\n"
                        && (false === strpos('}])+-"\'', $this->a)))) {
                    $command = self::ACTION_DELETE_A_B;
                }
            }
            $this->action($command);
        }
        $this->output = trim($this->output);

        if ($mbIntEnc !== null) {
            mb_internal_encoding($mbIntEnc);
        }
        return $this->output;
    }

    /**
     * ACTION_KEEP_A = Output A. Copy B to A. Get the next B.
     * ACTION_DELETE_A = Copy B to A. Get the next B.
     * ACTION_DELETE_A_B = Get the next B.
     *
     * @param int $command
     * @throws JSMin_UnterminatedRegExpException|JSMin_UnterminatedStringException
     */
    protected function action($command)
    {
        // make sure we don't compress "a + ++b" to "a+++b", etc.
        if ($command === self::ACTION_DELETE_A_B
            && $this->b === ' '
            && ($this->a === '+' || $this->a === '-')) {
            // Note: we're at an addition/substraction operator; the inputIndex
            // will certainly be a valid index
            if ($this->input[$this->inputIndex] === $this->a) {
                // This is "+ +" or "- -". Don't delete the space.
                $command = self::ACTION_KEEP_A;
            }
        }

        switch ($command) {
            case self::ACTION_KEEP_A: // 1
                $this->output .= $this->a;

                if ($this->keptComment) {
                    $this->output = rtrim($this->output, "\n");
                    $this->output .= $this->keptComment;
                    $this->keptComment = '';
                }

                $this->lastByteOut = $this->a;

            // fallthrough intentional
            case self::ACTION_DELETE_A: // 2
                $this->a = $this->b;
                if ($this->a === "'" || $this->a === '"') { // string literal
                    $str = $this->a; // in case needed for exception
                    for(;;) {
                        $this->output .= $this->a;
                        $this->lastByteOut = $this->a;

                        $this->a = $this->get();
                        if ($this->a === $this->b) { // end quote
                            break;
                        }
                        if ($this->isEOF($this->a)) {
                            $byte = $this->inputIndex - 1;
                            throw new JSMin_UnterminatedStringException(
                                "JSMin: Unterminated String at byte {$byte}: {$str}");
                        }
                        $str .= $this->a;
                        if ($this->a === '\\') {
                            $this->output .= $this->a;
                            $this->lastByteOut = $this->a;

                            $this->a       = $this->get();
                            $str .= $this->a;
                        }
                    }
                }

            // fallthrough intentional
            case self::ACTION_DELETE_A_B: // 3
                $this->b = $this->next();
                if ($this->b === '/' && $this->isRegexpLiteral()) {
                    $this->output .= $this->a . $this->b;
                    $pattern = '/'; // keep entire pattern in case we need to report it in the exception
                    for(;;) {
                        $this->a = $this->get();
                        $pattern .= $this->a;
                        if ($this->a === '[') {
                            for(;;) {
                                $this->output .= $this->a;
                                $this->a = $this->get();
                                $pattern .= $this->a;
                                if ($this->a === ']') {
                                    break;
                                }
                                if ($this->a === '\\') {
                                    $this->output .= $this->a;
                                    $this->a = $this->get();
                                    $pattern .= $this->a;
                                }
                                if ($this->isEOF($this->a)) {
                                    throw new JSMin_UnterminatedRegExpException(
                                        "JSMin: Unterminated set in RegExp at byte "
                                        . $this->inputIndex .": {$pattern}");
                                }
                            }
                        }

                        if ($this->a === '/') { // end pattern
                            break; // while (true)
                        } elseif ($this->a === '\\') {
                            $this->output .= $this->a;
                            $this->a = $this->get();
                            $pattern .= $this->a;
                        } elseif ($this->isEOF($this->a)) {
                            $byte = $this->inputIndex - 1;
                            throw new JSMin_UnterminatedRegExpException(
                                "JSMin: Unterminated RegExp at byte {$byte}: {$pattern}");
                        }
                        $this->output .= $this->a;
                        $this->lastByteOut = $this->a;
                    }
                    $this->b = $this->next();
                }
            // end case ACTION_DELETE_A_B
        }
    }

    /**
     * @return bool
     */
    protected function isRegexpLiteral()
    {
        if (false !== strpos("(,=:[!&|?+-~*{;", $this->a)) {
            // we obviously aren't dividing
            return true;
        }

        // we have to check for a preceding keyword, and we don't need to pattern
        // match over the whole output.
        $recentOutput = substr($this->output, -10);

        // check if return/typeof directly precede a pattern without a space
        foreach (array('return', 'typeof') as $keyword) {
            if ($this->a !== substr($keyword, -1)) {
                // certainly wasn't keyword
                continue;
            }
            if (preg_match("~(^|[\\s\\S])" . substr($keyword, 0, -1) . "$~", $recentOutput, $m)) {
                if ($m[1] === '' || !$this->isAlphaNum($m[1])) {
                    return true;
                }
            }
        }

        // check all keywords
        if ($this->a === ' ' || $this->a === "\n") {
            if (preg_match('~(^|[\\s\\S])(?:case|else|in|return|typeof)$~', $recentOutput, $m)) {
                if ($m[1] === '' || !$this->isAlphaNum($m[1])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Return the next character from stdin. Watch out for lookahead. If the character is a control character,
     * translate it to a space or linefeed.
     *
     * @return string
     */
    protected function get()
    {
        $c = $this->lookAhead;
        $this->lookAhead = null;
        if ($c === null) {
            // getc(stdin)
            if ($this->inputIndex < $this->inputLength) {
                $c = $this->input[$this->inputIndex];
                $this->inputIndex += 1;
            } else {
                $c = null;
            }
        }
        if (ord($c) >= self::ORD_SPACE || $c === "\n" || $c === null) {
            return $c;
        }
        if ($c === "\r") {
            return "\n";
        }
        return ' ';
    }

    /**
     * Does $a indicate end of input?
     *
     * @param string $a
     * @return bool
     */
    protected function isEOF($a)
    {
        return ord($a) <= self::ORD_LF;
    }

    /**
     * Get next char (without getting it). If is ctrl character, translate to a space or newline.
     *
     * @return string
     */
    protected function peek()
    {
        $this->lookAhead = $this->get();
        return $this->lookAhead;
    }

    /**
     * Return true if the character is a letter, digit, underscore, dollar sign, or non-ASCII character.
     *
     * @param string $c
     *
     * @return bool
     */
    protected function isAlphaNum($c)
    {
        return (preg_match('/^[a-z0-9A-Z_\\$\\\\]$/', $c) || ord($c) > 126);
    }

    /**
     * Consume a single line comment from input (possibly retaining it)
     */
    protected function consumeSingleLineComment()
    {
        $comment = '';
        while (true) {
            $get = $this->get();
            $comment .= $get;
            if (ord($get) <= self::ORD_LF) { // end of line reached
                // if IE conditional comment
                if (preg_match('/^\\/@(?:cc_on|if|elif|else|end)\\b/', $comment)) {
                    $this->keptComment .= "/{$comment}";
                }
                return;
            }
        }
    }

    /**
     * Consume a multiple line comment from input (possibly retaining it)
     *
     * @throws JSMin_UnterminatedCommentException
     */
    protected function consumeMultipleLineComment()
    {
        $this->get();
        $comment = '';
        for(;;) {
            $get = $this->get();
            if ($get === '*') {
                if ($this->peek() === '/') { // end of comment reached
                    $this->get();
                    if (0 === strpos($comment, '!')) {
                        // preserved by YUI Compressor
                        if (!$this->keptComment) {
                            // don't prepend a newline if two comments right after one another
                            $this->keptComment = "\n";
                        }
                        $this->keptComment .= "/*!" . substr($comment, 1) . "*/\n";
                    } else if (preg_match('/^@(?:cc_on|if|elif|else|end)\\b/', $comment)) {
                        // IE conditional
                        $this->keptComment .= "/*{$comment}*/";
                    }
                    return;
                }
            } elseif ($get === null) {
                throw new JSMin_UnterminatedCommentException(
                    "JSMin: Unterminated comment at byte {$this->inputIndex}: /*{$comment}");
            }
            $comment .= $get;
        }
    }

    /**
     * Get the next character, skipping over comments. Some comments may be preserved.
     *
     * @return string
     */
    protected function next()
    {
        $get = $this->get();
        if ($get === '/') {
            switch ($this->peek()) {
                case '/':
                    $this->consumeSingleLineComment();
                    $get = "\n";
                    break;
                case '*':
                    $this->consumeMultipleLineComment();
                    $get = ' ';
                    break;
            }
        }
        return $get;
    }
}

class JSMin_UnterminatedStringException extends Exception {}
class JSMin_UnterminatedCommentException extends Exception {}
class JSMin_UnterminatedRegExpException extends Exception {}