<?php

use PHPUnit\Framework\TestCase;

/**
 * Test cases for the HTML Element class
 * Class HTMLElementTest
 */
class HTMLElementTest extends TestCase
{

    final function testEmbedding(): void
    {
        $lis = [];


        $lis[] = _li(["id" => "test"], "hello");
        $lis[] = _li("hello",  _p(_div(["id" => "hello"], "mmmm")));

        $ul = _ul($lis);

        $html = _html($ul);

        $html->byId("test")->html("Ok!");
        $html->byId("hello")->html("Ok!");

        $this->assertEquals("<ul><li id=\"test\">Ok!</li><li>hello<p><div id=\"hello\">Ok!</div></p></li></ul>", $ul."");
    }

    function testHTMLElement(): void
    {
        $htmlElement = new \Tina4\HTMLElement(":h1",  ["Hello"]);
        $this->assertEquals("<h1>Hello</h1>", $htmlElement."" );
    }



    final function testElements(): void
    {
        //$this->assertEquals("<></>",  _([])."");
        $this->assertEquals("<!--Some Comments-->",  _comment("Some Comments")."");
        $this->assertEquals("<!DOCTYPE html>",  _doctype(["html"])."");
        $this->assertEquals('<a href="https://google.com">Link</a>',  _a(["href" => "https://google.com"], "Link")."");
        $this->assertEquals('<abbr title="Cascading Style Sheets">CSS</abbr>', _abbr(["title" => "Cascading Style Sheets"], "CSS"));
        $this->assertEquals('<abbr title="Cascading Style Sheets">CSS</abbr>', _acronym(["title" => "Cascading Style Sheets"], "CSS"));
        $this->assertEquals('<address>Some address</address>',  _address("Some address")."");
        $this->assertEquals('<applet>No longer supported</applet>',  _applet("No longer supported")."");
        $this->assertEquals('<map name="workmap"><area shape="rect" coords="34,44,270,350" alt="Computer" href="computer.html"></map>', _map(["name" => "workmap"], _area(["shape" => "rect", "coords"=>"34,44,270,350", "alt" => "Computer", "href" => "computer.html"]))."");
        $this->assertEquals('<article>Some article</article>',  _article("Some article")."");
        $this->assertEquals('<aside>Some text</aside>',  _aside("Some text")."");
        $this->assertEquals('<audio controls><source src="horse.ogg" type="audio/ogg"></audio>', _audio(["controls"], _source(["src" => "horse.ogg", "type" => "audio/ogg"]))."");
        $this->assertEquals('<b>Some text</b>',  _b("Some text")."");
        $this->assertEquals('<head><base href="https://somesite.com/" target="_blank"></head>', _head(_base(["href" => "https://somesite.com/", "target" => "_blank"]))."");
        $this->assertEquals('<basefont color="blue">',  _basefont(["color" => "blue"])."");
        $this->assertEquals('<ul><li>User <bdi>إيان</bdi>: 90 points</li></ul>', _ul(_li("User ",_bdi("إيان"),": 90 points") )."" );
        $this->assertEquals('<bdo dir="rtl">This text will go right-to-left.</bdo>', _bdo(["dir" => "rtl"], "This text will go right-to-left.")."");
        $this->assertEquals('<big>No longer supported</big>',  _big("No longer supported")."");
        $this->assertEquals('<blockquote cite="http://somewhere.com/text.html">Some text</blockquote>',  _blockquote(["cite" => "http://somewhere.com/text.html"], "Some text")."");
        $this->assertEquals('<body>Some text</body>',  _body("Some text")."");
        $this->assertEquals('<br />',  _br()."");
        $this->assertEquals('<button type="button">Click Me!</button>', _button(["type" => "button"], "Click Me!")."");
        $this->assertEquals('<canvas id="myCanvas">Your browser does not support canvases</canvas>',  _canvas(["id" => "myCanvas"], "Your browser does not support canvases")."");
        $this->assertEquals('<table><caption>Monthly savings</caption></table>', _table(_caption("Monthly savings"))."");
        $this->assertEquals('<center>No longer supported</center>',  _center("No longer supported")."");
        $this->assertEquals('<cite>Some text</cite>',  _cite("Some text")."");
        $this->assertEquals('<code>Some code</code>',  _code("Some code")."");
        $this->assertEquals('<samp>Some text</samp>',  _samp("Some text")."");
        $this->assertEquals('<kbd>Ctrl</kbd> + <kbd>C</kbd>',  _kbd("Ctrl")." + "._kbd("C"));
        $this->assertEquals('<colgroup><col span="2" style="background-color:red"><col style="background-color:yellow"></colgroup>', _colgroup(_col(["span" => "2", "style" => "background-color:red"]), _col(["style" => "background-color:yellow"]))."");
        $this->assertEquals('<ul><li><data value="21053">Cherry Tomato</data></li></ul>', _ul(_li(_data(["value" => "21053"], "Cherry Tomato")))."");
        $this->assertEquals('<datalist id="browsers"><option value="Edge"></option></datalist>',  _datalist(["id" => "browsers"], _option(["value" => "Edge"]))."");
        $this->assertEquals('<dl><dt>A</dt><dd>B</dd></dl>', _dl(_dt("A"), _dd("B"))."");
        $this->assertEquals('<del>Some code</del>',  _del("Some code")."");
        $this->assertEquals('<dfn>Some code</dfn>',  _dfn("Some code")."");
        $this->assertEquals('<details><summary>Epcot Center</summary></details>', _details(_summary("Epcot Center"))."");
        $this->assertEquals('<cite>Some text</cite>',  _cite("Some text")."");
        $this->assertEquals('<dialog open>Some text</dialog>',  _dialog(["open"],"Some text")."");
        $this->assertEquals('<dir>No longer supported</dir>',  _dir("No longer supported")."");
        $this->assertEquals('<font>No longer supported</font>',  _font("No longer supported")."");
        $this->assertEquals('<frameset>No longer supported</frameset>',  _frameset("No longer supported")."");
        $this->assertEquals('<div>Some text</div>',  _div("Some text")."");
        $this->assertEquals('<em>Some code</em>',  _em("Some code")."");
        $this->assertEquals('<embed type="image/jpg" src="pic_trulli.jpg" width="300" height="200">',  _embed(["type" => "image/jpg", "src" => "pic_trulli.jpg", "width" => "300", "height" => "200"])."");
        $this->assertEquals('<fieldset><legend>Testing</legend></fieldset>', _fieldset(_legend("Testing"))."");
        $this->assertEquals('<figure><figcaption>Testing</figcaption></figure>', _figure(_figcaption("Testing"))."");
        $this->assertEquals('<footer>Some code</footer>',  _footer("Some code")."");
        $this->assertEquals('<form name="data"><input type="text" value="hello"></form>',  _form(["name" => "data"], _input(["type" => "text", "value" => "hello"]))."");
        $this->assertEquals('<h1>Some text</h1>',  _h1("Some text")."");
        $this->assertEquals('<h2>Some text</h2>',  _h2("Some text")."");
        $this->assertEquals('<h3>Some text</h3>',  _h3("Some text")."");
        $this->assertEquals('<h4>Some text</h4>',  _h4("Some text")."");
        $this->assertEquals('<h5>Some text</h5>',  _h5("Some text")."");
        $this->assertEquals('<h6>Some text</h6>',  _h6("Some text")."");
        $this->assertEquals('<head><title>Some title</title></head>',  _head(_title("Some title"))."");
        $this->assertEquals('<header><hgroup>Some text</hgroup></header>',  _header(_hgroup("Some text"))."");
        $this->assertEquals('<hr />',  _hr()."");
        $this->assertEquals('<html>Testing</html>',  _html("Testing")."");
        $this->assertEquals('<i>Some text</i>',  _i("Some text")."");
        $this->assertEquals('<iframe src="https://tina4.com" title="Tina4"></iframe>', _iframe(["src" => "https://tina4.com", "title" => "Tina4"])."");
        $this->assertEquals('<img src="img_girl.jpg" alt="Girl in a jacket" width="500" height="600">', _img(["src" => "img_girl.jpg", "alt" => "Girl in a jacket", "width" => "500", "height" => "600"]));
        $this->assertEquals('<ins>Some text</ins>',  _ins("Some text")."");
        $this->assertEquals('<label>Some text</label>',  _label("Some text")."");
        $this->assertEquals('<link rel="stylesheet" href="styles.css">', _link(["rel" => "stylesheet", "href" => "styles.css"])."");
        $this->assertEquals('<mark>Some text</mark>',  _mark("Some text")."");
        $this->assertEquals('<meta charset="UTF-8">',  _meta(["charset" => "UTF-8"])."");
        $this->assertEquals('<meter id="disk_d" value="0.6">60%</meter>', _meter(["id" => "disk_d", "value" => "0.6"], "60%")."");
        $this->assertEquals('<nav>Some text</nav>',  _nav("Some text")."");
        $this->assertEquals('<noframes>No longer supported</noframes>',  _noframes("No longer supported")."");
        $this->assertEquals('<script>document.write("Hello World!")</script><noscript>Your browser does not support JavaScript!</noscript>', _script('document.write("Hello World!")')._noscript("Your browser does not support JavaScript!")."");
        $this->assertEquals('<object data="snippet.html" width="500" height="200"></object>',  _object(["data" => "snippet.html", "width" => "500", "height" => "200"])."");
        $this->assertEquals('<ol><li>Some text</li></ol>',  _ol(_li("Some text"))."");
        $this->assertEquals('<select name="cars" id="cars"><optgroup label="Swedish Cars"><option value="volvo">Volvo</option></optgroup></select>', _select(["name" => "cars", "id" => "cars"], _optgroup(["label" => "Swedish Cars"], _option(["value" => "volvo"], "Volvo")))."");
        $this->assertEquals('<output name="x" for="a b"></output>', _output(["name" => "x", "for" => "a b"])."");
        $this->assertEquals('<p>Some text</p>',  _p("Some text")."");
        $this->assertEquals('<q>Some text</q>',  _q("Some text")."");
        $this->assertEquals('<param name="autoplay" value="true">', _param(["name" => "autoplay", "value" => "true"])."");
        $this->assertEquals('<picture>Some text</picture>',  _picture("Some text")."");
        $this->assertEquals('<progress id="file" value="32" max="100">32%</progress>',  _progress(["id" => "file", "value" => "32", "max" => "100"], "32%")."");
        $this->assertEquals('<pre>Some text</pre>',  _pre("Some text")."");
        $this->assertEquals('<ruby><rp>(</rp>漢 <rt> ㄏㄢˋ </rt><rp>)</rp></ruby>', _ruby(_rp("("), "漢 ", _rt(" ㄏㄢˋ "), _rp(")"))."");
        $this->assertEquals('<s>Some text</s>',  _s("Some text")."");
        $this->assertEquals('<section>Some text</section>',  _section("Some text")."");
        $this->assertEquals('<small>Some text</small>',  _small("Some text")."");
        $this->assertEquals('<span>Some text</span>',  _span("Some text")."");
        $this->assertEquals('<strong>Some text</strong>',  _strong("Some text")."");
        $this->assertEquals('<sup>Some text</sup>',  _sup("Some text")."");
        $this->assertEquals('<sub>Some text</sub>',  _sub("Some text")."");
        $this->assertEquals('<style>body { background: red }</style>',  _style("body { background: red }")."");
        $this->assertEquals('<strike>No longer supported</strike>',  _strike("No longer supported")."");
        $this->assertEquals('<u>Some text</u>',  _u("Some text")."");
        $this->assertEquals('<table><thead><tr><th>H</th></tr></thead><tbody><tr><td>C</td></tr></tbody><tfoot><tr><td>F</td></tr></tfoot></table>', _table(_thead(_tr(_th("H"))), _tbody(_tr(_td("C"))), _tfoot(_tr(_td("F"))))."");
        $this->assertEquals('<tt>No longer supported</tt>',  _tt("No longer supported")."");
        $this->assertEquals('<var>Some var</var>',  _var("Some var")."");
        $this->assertEquals('<time datetime="2008-02-14 20:00">Valentines day</time>', _time(["datetime" => "2008-02-14 20:00"], "Valentines day")."");
        $this->assertEquals('<track src="fgsubtitles_en.vtt" kind="subtitles" srclang="en" label="English">', _track(["src" => "fgsubtitles_en.vtt", "kind" => "subtitles", "srclang" => "en", "label" => "English"])."");
        $this->assertEquals('<textarea>Some var</textarea>',  _textarea("Some var")."");
        $this->assertEquals('<video width="320" height="240" controls></video>', _video(["width" => "320", "height" => "240", "controls"])."");
        $this->assertEquals('<wbr>Some text</wbr>',  _wbr("Some text")."");
    }



}