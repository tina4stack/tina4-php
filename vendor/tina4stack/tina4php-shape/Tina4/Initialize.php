<?php

use Tina4\HTMLElement;


/**
 * Encloses all shape variables
 * @param mixed ...$elements
 * @return HTMLElement
 */
function _shape(...$elements): HTMLElement
{
    return new HTMLElement(":", $elements);
}

/**
 * HTML TAG _doctype
 * @param $elements
 * @return HTMLElement
 */
function _doctype(...$elements): HTMLElement
{
    return new HTMLElement(":!DOCTYPE", $elements);
}

/**
 * HTML TAG comment
 * @param $elements
 * @return HTMLElement
 */
function _comment(...$elements): HTMLElement
{
    return new HTMLElement(":!--", $elements);
}

/**
 * HTML TAG _a
 * @param $elements
 * @return HTMLElement
 */
function _a(...$elements): HTMLElement
{
    return new HTMLElement(":a", $elements);
}

/**
 * HTML TAG _abbr
 * @param $elements
 * @return HTMLElement
 */
function _abbr(...$elements): HTMLElement
{
    return new HTMLElement(":abbr", $elements);
}

/**
 * HTML TAG _acronym
 * @param $elements
 * @return HTMLElement
 */
function _acronym(...$elements): HTMLElement
{
    return new HTMLElement(":abbr", $elements);
}

/**
 * HTML TAG _address
 * @param $elements
 * @return HTMLElement
 */
function _address(...$elements): HTMLElement
{
    return new HTMLElement(":address", $elements);
}

/**
 * HTML TAG _applet
 * @param $elements
 * @return HTMLElement
 */
function _applet(...$elements): HTMLElement
{
    return new HTMLElement(":applet", $elements);
}

/**
 * HTML TAG _area
 * @param $elements
 * @return HTMLElement
 */
function _area(...$elements): HTMLElement
{
    return new HTMLElement(":area/", $elements);
}

/**
 * HTML TAG _article
 * @param $elements
 * @return HTMLElement
 */
function _article(...$elements): HTMLElement
{
    return new HTMLElement(":article", $elements);
}

/**
 * HTML TAG _aside
 * @param $elements
 * @return HTMLElement
 */
function _aside(...$elements): HTMLElement
{
    return new HTMLElement(":aside", $elements);
}

/**
 * HTML TAG _audio
 * @param $elements
 * @return HTMLElement
 */
function _audio(...$elements): HTMLElement
{
    return new HTMLElement(":audio", $elements);
}

/**
 * HTML TAG _b
 * @param $elements
 * @return HTMLElement
 */
function _b(...$elements): HTMLElement
{
    return new HTMLElement(":b", $elements);
}

/**
 * HTML TAG _base
 * @param $elements
 * @return HTMLElement
 */
function _base(...$elements): HTMLElement
{
    return new HTMLElement(":base/", $elements);
}

/**
 * HTML TAG _basefont
 * @param $elements
 * @return HTMLElement
 */
function _basefont(...$elements): HTMLElement
{
    return new HTMLElement(":basefont/", $elements);
}

/**
 * HTML TAG _bdi
 * @param $elements
 * @return HTMLElement
 */
function _bdi(...$elements): HTMLElement
{
    return new HTMLElement(":bdi", $elements);
}

/**
 * HTML TAG _bdo
 * @param $elements
 * @return HTMLElement
 */
function _bdo(...$elements): HTMLElement
{
    return new HTMLElement(":bdo", $elements);
}

/**
 * HTML TAG _big
 * @param $elements
 * @return HTMLElement
 */
function _big(...$elements): HTMLElement
{
    return new HTMLElement(":big", $elements);
}

/**
 * HTML TAG _blockquote
 * @param $elements
 * @return HTMLElement
 */
function _blockquote(...$elements): HTMLElement
{
    return new HTMLElement(":blockquote", $elements);
}

/**
 * HTML TAG _body
 * @param $elements
 * @return HTMLElement
 */
function _body(...$elements): HTMLElement
{
    return new HTMLElement(":body", $elements);
}

/**
 * HTML TAG _br
 * @param $elements
 * @return HTMLElement
 */
function _br(...$elements): HTMLElement
{
    return new HTMLElement(":br //", $elements);
}

/**
 * HTML TAG _button
 * @param $elements
 * @return HTMLElement
 */
function _button(...$elements): HTMLElement
{
    return new HTMLElement(":button", $elements);
}

/**
 * HTML TAG _canvas
 * @param $elements
 * @return HTMLElement
 */
function _canvas(...$elements): HTMLElement
{
    return new HTMLElement(":canvas", $elements);
}

/**
 * HTML TAG _caption
 * @param $elements
 * @return HTMLElement
 */
function _caption(...$elements): HTMLElement
{
    return new HTMLElement(":caption", $elements);
}

/**
 * HTML TAG _center
 * @param $elements
 * @return HTMLElement
 */
function _center(...$elements): HTMLElement
{
    return new HTMLElement(":center", $elements);
}

/**
 * HTML TAG _cite
 * @param $elements
 * @return HTMLElement
 */
function _cite(...$elements): HTMLElement
{
    return new HTMLElement(":cite", $elements);
}

/**
 * HTML TAG _code
 * @param $elements
 * @return HTMLElement
 */
function _code(...$elements): HTMLElement
{
    return new HTMLElement(":code", $elements);
}

/**
 * HTML TAG _col
 * @param $elements
 * @return HTMLElement
 */
function _col(...$elements): HTMLElement
{
    return new HTMLElement(":col/", $elements);
}

/**
 * HTML TAG _colgroup
 * @param $elements
 * @return HTMLElement
 */
function _colgroup(...$elements): HTMLElement
{
    return new HTMLElement(":colgroup", $elements);
}

/**
 * HTML TAG _data
 * @param $elements
 * @return HTMLElement
 */
function _data(...$elements): HTMLElement
{
    return new HTMLElement(":data", $elements);
}

/**
 * HTML TAG _datalist
 * @param $elements
 * @return HTMLElement
 */
function _datalist(...$elements): HTMLElement
{
    return new HTMLElement(":datalist", $elements);
}

/**
 * HTML TAG _dd
 * @param $elements
 * @return HTMLElement
 */
function _dd(...$elements): HTMLElement
{
    return new HTMLElement(":dd", $elements);
}

/**
 * HTML TAG _del
 * @param $elements
 * @return HTMLElement
 */
function _del(...$elements): HTMLElement
{
    return new HTMLElement(":del", $elements);
}

/**
 * HTML TAG _details
 * @param $elements
 * @return HTMLElement
 */
function _details(...$elements): HTMLElement
{
    return new HTMLElement(":details", $elements);
}

/**
 * HTML TAG _dfn
 * @param $elements
 * @return HTMLElement
 */
function _dfn(...$elements): HTMLElement
{
    return new HTMLElement(":dfn", $elements);
}

/**
 * HTML TAG _dialog
 * @param $elements
 * @return HTMLElement
 */
function _dialog(...$elements): HTMLElement
{
    return new HTMLElement(":dialog", $elements);
}

/**
 * HTML TAG _dir
 * @param $elements
 * @return HTMLElement
 */
function _dir(...$elements): HTMLElement
{
    return new HTMLElement(":dir", $elements);
}

/**
 * HTML TAG _div
 * @param $elements
 * @return HTMLElement
 */
function _div(...$elements): HTMLElement
{
    return new HTMLElement(":div", $elements);
}

/**
 * HTML TAG _dl
 * @param $elements
 * @return HTMLElement
 */
function _dl(...$elements): HTMLElement
{
    return new HTMLElement(":dl", $elements);
}

/**
 * HTML TAG _dt
 * @param $elements
 * @return HTMLElement
 */
function _dt(...$elements): HTMLElement
{
    return new HTMLElement(":dt", $elements);
}

/**
 * HTML TAG _em
 * @param $elements
 * @return HTMLElement
 */
function _em(...$elements): HTMLElement
{
    return new HTMLElement(":em", $elements);
}

/**
 * HTML TAG _embed
 * @param $elements
 * @return HTMLElement
 */
function _embed(...$elements): HTMLElement
{
    return new HTMLElement(":embed/", $elements);
}

/**
 * HTML TAG _fieldset
 * @param $elements
 * @return HTMLElement
 */
function _fieldset(...$elements): HTMLElement
{
    return new HTMLElement(":fieldset", $elements);
}

/**
 * HTML TAG _figcaption
 * @param $elements
 * @return HTMLElement
 */
function _figcaption(...$elements): HTMLElement
{
    return new HTMLElement(":figcaption", $elements);
}

/**
 * HTML TAG _figure
 * @param $elements
 * @return HTMLElement
 */
function _figure(...$elements): HTMLElement
{
    return new HTMLElement(":figure", $elements);
}

/**
 * HTML TAG _font
 * @param $elements
 * @return HTMLElement
 */
function _font(...$elements): HTMLElement
{
    return new HTMLElement(":font", $elements);
}

/**
 * HTML TAG _footer
 * @param $elements
 * @return HTMLElement
 */
function _footer(...$elements): HTMLElement
{
    return new HTMLElement(":footer", $elements);
}

/**
 * HTML TAG _form
 * @param $elements
 * @return HTMLElement
 */
function _form(...$elements): HTMLElement
{
    return new HTMLElement(":form", $elements);
}

/**
 * HTML TAG _frameset
 * @param $elements
 * @return HTMLElement
 */
function _frameset(...$elements): HTMLElement
{
    return new HTMLElement(":frameset", $elements);
}

/**
 * HTML TAG _h1
 * @param $elements
 * @return HTMLElement
 */
function _h1(...$elements): HTMLElement
{
    return new HTMLElement(":h1", $elements);
}

/**
 * HTML TAG _h2
 * @param $elements
 * @return HTMLElement
 */
function _h2(...$elements): HTMLElement
{
    return new HTMLElement(":h2", $elements);
}

/**
 * HTML TAG _h3
 * @param $elements
 * @return HTMLElement
 */
function _h3(...$elements): HTMLElement
{
    return new HTMLElement(":h3", $elements);
}

/**
 * HTML TAG _h4
 * @param $elements
 * @return HTMLElement
 */
function _h4(...$elements): HTMLElement
{
    return new HTMLElement(":h4", $elements);
}


/**
 * HTML TAG _h5
 * @param $elements
 * @return HTMLElement
 */
function _h5(...$elements): HTMLElement
{
    return new HTMLElement(":h5", $elements);
}

/**
 * HTML TAG _h6
 * @param $elements
 * @return HTMLElement
 */
function _h6(...$elements): HTMLElement
{
    return new HTMLElement(":h6", $elements);
}

/**
 * HTML TAG _head
 * @param $elements
 * @return HTMLElement
 */
function _head(...$elements): HTMLElement
{
    return new HTMLElement(":head", $elements);
}

/**
 * HTML TAG _header
 * @param $elements
 * @return HTMLElement
 */
function _header(...$elements): HTMLElement
{
    return new HTMLElement(":header", $elements);
}

/**
 * HTML TAG _hgroup
 * @param $elements
 * @return HTMLElement
 */
function _hgroup(...$elements): HTMLElement
{
    return new HTMLElement(":hgroup", $elements);
}

/**
 * HTML TAG _hr
 * @param $elements
 * @return HTMLElement
 */
function _hr(...$elements): HTMLElement
{
    return new HTMLElement(":hr //", $elements);
}

/**
 * HTML TAG _html
 * @param $elements
 * @return HTMLElement
 */
function _html(...$elements): HTMLElement
{
    return new HTMLElement(":html", $elements);
}

/**
 * HTML TAG _i
 * @param $elements
 * @return HTMLElement
 */
function _i(...$elements): HTMLElement
{
    return new HTMLElement(":i", $elements);
}

/**
 * HTML TAG _iframe
 * @param $elements
 * @return HTMLElement
 */
function _iframe(...$elements): HTMLElement
{
    return new HTMLElement(":iframe", $elements);
}

/**
 * HTML TAG _img
 * @param $elements
 * @return HTMLElement
 */
function _img(...$elements): HTMLElement
{
    return new HTMLElement(":img/", $elements);
}

/**
 * HTML TAG _input
 * @param $elements
 * @return HTMLElement
 */
function _input(...$elements): HTMLElement
{
    return new HTMLElement(":input/", $elements);
}

/**
 * HTML TAG _ins
 * @param $elements
 * @return HTMLElement
 */
function _ins(...$elements): HTMLElement
{
    return new HTMLElement(":ins", $elements);
}

/**
 * HTML TAG _kbd
 * @param $elements
 * @return HTMLElement
 */
function _kbd(...$elements): HTMLElement
{
    return new HTMLElement(":kbd", $elements);
}


/**
 * HTML TAG _label
 * @param $elements
 * @return HTMLElement
 */
function _label(...$elements): HTMLElement
{
    return new HTMLElement(":label", $elements);
}

/**
 * HTML TAG _legend
 * @param $elements
 * @return HTMLElement
 */
function _legend(...$elements): HTMLElement
{
    return new HTMLElement(":legend", $elements);
}

/**
 * HTML TAG _li
 * @param $elements
 * @return HTMLElement
 */
function _li(...$elements): HTMLElement
{
    return new HTMLElement(":li", $elements);
}

/**
 * HTML TAG _link
 * @param $elements
 * @return HTMLElement
 */
function _link(...$elements): HTMLElement
{
    return new HTMLElement(":link/", $elements);
}

/**
 * HTML TAG _map
 * @param $elements
 * @return HTMLElement
 */
function _map(...$elements): HTMLElement
{
    return new HTMLElement(":map", $elements);
}

/**
 * HTML TAG _mark
 * @param $elements
 * @return HTMLElement
 */
function _mark(...$elements): HTMLElement
{
    return new HTMLElement(":mark", $elements);
}


/**
 * HTML TAG _meta
 * @param $elements
 * @return HTMLElement
 */
function _meta(...$elements): HTMLElement
{
    return new HTMLElement(":meta/", $elements);
}

/**
 * HTML TAG _meter
 * @param $elements
 * @return HTMLElement
 */
function _meter(...$elements): HTMLElement
{
    return new HTMLElement(":meter", $elements);
}

/**
 * HTML TAG _nav
 * @param $elements
 * @return HTMLElement
 */
function _nav(...$elements): HTMLElement
{
    return new HTMLElement(":nav", $elements);
}

/**
 * HTML TAG _noframes
 * @param $elements
 * @return HTMLElement
 */
function _noframes(...$elements): HTMLElement
{
    return new HTMLElement(":noframes", $elements);
}

/**
 * HTML TAG _noscript
 * @param $elements
 * @return HTMLElement
 */
function _noscript(...$elements): HTMLElement
{
    return new HTMLElement(":noscript", $elements);
}

/**
 * HTML TAG _object
 * @param $elements
 * @return HTMLElement
 */
function _object(...$elements): HTMLElement
{
    return new HTMLElement(":object", $elements);
}

/**
 * HTML TAG _ol
 * @param $elements
 * @return HTMLElement
 */
function _ol(...$elements): HTMLElement
{
    return new HTMLElement(":ol", $elements);
}

/**
 * HTML TAG _optgroup
 * @param $elements
 * @return HTMLElement
 */
function _optgroup(...$elements): HTMLElement
{
    return new HTMLElement(":optgroup", $elements);
}

/**
 * HTML TAG _option
 * @param $elements
 * @return HTMLElement
 */
function _option(...$elements): HTMLElement
{
    return new HTMLElement(":option", $elements);
}

/**
 * HTML TAG _output
 * @param $elements
 * @return HTMLElement
 */
function _output(...$elements): HTMLElement
{
    return new HTMLElement(":output", $elements);
}

/**
 * HTML TAG _p
 * @param $elements
 * @return HTMLElement
 */
function _p(...$elements): HTMLElement
{
    return new HTMLElement(":p", $elements);
}

/**
 * HTML TAG _param
 * @param $elements
 * @return HTMLElement
 */
function _param(...$elements): HTMLElement
{
    return new HTMLElement(":param/", $elements);
}

/**
 * HTML TAG _param
 * @param $elements
 * @return HTMLElement
 */
function _picture(...$elements): HTMLElement
{
    return new HTMLElement(":picture", $elements);
}

/**
 * HTML TAG _pre
 * @param $elements
 * @return HTMLElement
 */
function _pre(...$elements): HTMLElement
{
    return new HTMLElement(":pre", $elements);
}

/**
 * HTML TAG _progress
 * @param $elements
 * @return HTMLElement
 */
function _progress(...$elements): HTMLElement
{
    return new HTMLElement(":progress", $elements);
}

/**
 * HTML TAG _q
 * @param $elements
 * @return HTMLElement
 */
function _q(...$elements): HTMLElement
{
    return new HTMLElement(":q", $elements);
}

/**
 * HTML TAG _rp
 * @param $elements
 * @return HTMLElement
 */
function _rp(...$elements): HTMLElement
{
    return new HTMLElement(":rp", $elements);
}

/**
 * HTML TAG _rt
 * @param $elements
 * @return HTMLElement
 */
function _rt(...$elements): HTMLElement
{
    return new HTMLElement(":rt", $elements);
}

/**
 * HTML TAG _ruby
 * @param $elements
 * @return HTMLElement
 */
function _ruby(...$elements): HTMLElement
{
    return new HTMLElement(":ruby", $elements);
}

/**
 * HTML TAG _s
 * @param $elements
 * @return HTMLElement
 */
function _s(...$elements): HTMLElement
{
    return new HTMLElement(":s", $elements);
}

/**
 * HTML TAG _samp
 * @param $elements
 * @return HTMLElement
 */
function _samp(...$elements): HTMLElement
{
    return new HTMLElement(":samp", $elements);
}

/**
 * HTML TAG _script
 * @param $elements
 * @return HTMLElement
 */
function _script(...$elements): HTMLElement
{
    return new HTMLElement(":script", $elements);
}

/**
 * HTML TAG _section
 * @param $elements
 * @return HTMLElement
 */
function _section(...$elements): HTMLElement
{
    return new HTMLElement(":section", $elements);
}

/**
 * HTML TAG _select
 * @param $elements
 * @return HTMLElement
 */
function _select(...$elements): HTMLElement
{
    return new HTMLElement(":select", $elements);
}

/**
 * HTML TAG _small
 * @param $elements
 * @return HTMLElement
 */
function _small(...$elements): HTMLElement
{
    return new HTMLElement(":small", $elements);
}

/**
 * HTML TAG _source
 * @param $elements
 * @return HTMLElement
 */
function _source(...$elements): HTMLElement
{
    return new HTMLElement(":source/", $elements);
}

/**
 * HTML TAG _span
 * @param $elements
 * @return HTMLElement
 */
function _span(...$elements): HTMLElement
{
    return new HTMLElement(":span", $elements);
}

/**
 * HTML TAG _strike
 * @param $elements
 * @return HTMLElement
 */
function _strike(...$elements): HTMLElement
{
    return new HTMLElement(":strike", $elements);
}

/**
 * HTML TAG _strong
 * @param $elements
 * @return HTMLElement
 */
function _strong(...$elements): HTMLElement
{
    return new HTMLElement(":strong", $elements);
}

/**
 * HTML TAG _style
 * @param $elements
 * @return HTMLElement
 */
function _style(...$elements): HTMLElement
{
    return new HTMLElement(":style", $elements);
}

/**
 * HTML TAG _sub
 * @param $elements
 * @return HTMLElement
 */
function _sub(...$elements): HTMLElement
{
    return new HTMLElement(":sub", $elements);
}

/**
 * HTML TAG _sub
 * @param $elements
 * @return HTMLElement
 */
function _summary(...$elements): HTMLElement
{
    return new HTMLElement(":summary", $elements);
}

/**
 * HTML TAG _sup
 * @param $elements
 * @return HTMLElement
 */
function _sup(...$elements): HTMLElement
{
    return new HTMLElement(":sup", $elements);
}

/**
 * HTML TAG _table
 * @param $elements
 * @return HTMLElement
 */
function _table(...$elements): HTMLElement
{
    return new HTMLElement(":table", $elements);
}

/**
 * HTML TAG _tbody
 * @param $elements
 * @return HTMLElement
 */
function _tbody(...$elements): HTMLElement
{
    return new HTMLElement(":tbody", $elements);
}

/**
 * HTML TAG _td
 * @param $elements
 * @return HTMLElement
 */
function _td(...$elements): HTMLElement
{
    return new HTMLElement(":td", $elements);
}

/**
 * HTML TAG _textarea
 * @param $elements
 * @return HTMLElement
 */
function _textarea(...$elements): HTMLElement
{
    return new HTMLElement(":textarea", $elements);
}

/**
 * HTML TAG _tfoot
 * @param $elements
 * @return HTMLElement
 */
function _tfoot(...$elements): HTMLElement
{
    return new HTMLElement(":tfoot", $elements);
}

/**
 * HTML TAG _th
 * @param $elements
 * @return HTMLElement
 */
function _th(...$elements): HTMLElement
{
    return new HTMLElement(":th", $elements);
}

/**
 * HTML TAG _thead
 * @param $elements
 * @return HTMLElement
 */
function _thead(...$elements): HTMLElement
{
    return new HTMLElement(":thead", $elements);
}

/**
 * HTML TAG _time
 * @param $elements
 * @return HTMLElement
 */
function _time(...$elements): HTMLElement
{
    return new HTMLElement(":time", $elements);
}

/**
 * HTML TAG _title
 * @param $elements
 * @return HTMLElement
 */
function _title(...$elements): HTMLElement
{
    return new HTMLElement(":title", $elements);
}

/**
 * HTML TAG _tr
 * @param $elements
 * @return HTMLElement
 */
function _tr(...$elements): HTMLElement
{
    return new HTMLElement(":tr", $elements);
}

/**
 * HTML TAG _track
 * @param $elements
 * @return HTMLElement
 */
function _track(...$elements): HTMLElement
{
    return new HTMLElement(":track/", $elements);
}

/**
 * HTML TAG _tt
 * @param $elements
 * @return HTMLElement
 */
function _tt(...$elements): HTMLElement
{
    return new HTMLElement(":tt", $elements);
}

/**
 * HTML TAG _u
 * @param $elements
 * @return HTMLElement
 */
function _u(...$elements): HTMLElement
{
    return new HTMLElement(":u", $elements);
}

/**
 * HTML TAG _ul
 * @param $elements
 * @return HTMLElement
 */
function _ul(...$elements): HTMLElement
{
    return new HTMLElement(":ul", $elements);
}

/**
 * HTML TAG _var
 * @param $elements
 * @return HTMLElement
 */
function _var(...$elements): HTMLElement
{
    return new HTMLElement(":var", $elements);
}

/**
 * HTML TAG _video
 * @param $elements
 * @return HTMLElement
 */
function _video(...$elements): HTMLElement
{
    return new HTMLElement(":video", $elements);
}

/**
 * HTML TAG _wbr
 * @param $elements
 * @return HTMLElement
 */
function _wbr(...$elements): HTMLElement
{
    return new HTMLElement(":wbr", $elements);
}