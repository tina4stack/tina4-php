<?php
/**
 * Copyright (c) 2021. ComboStrap, Inc. and its affiliates. All Rights Reserved.
 *
 * This source code is licensed under the GPL license found in the
 * COPYING  file in the root directory of this source tree.
 *
 * @license  GPL 3 (https://www.gnu.org/licenses/gpl-3.0.en.html)
 * @author   ComboStrap <support@combostrap.com>
 *
 */

/**
 * Plugin Webcode: Show webcode (Css, HTML) in a iframe
 *
 */

// must be run within Dokuwiki
use ComboStrap\CallStack;
use ComboStrap\Dimension;
use ComboStrap\LogUtility;
use ComboStrap\PluginUtility;
use ComboStrap\Site;
use ComboStrap\TagAttributes;

if (!defined('DOKU_INC')) die();

/**
 * Webcode
 */
class syntax_plugin_combo_webcode extends DokuWiki_Syntax_Plugin
{

    const EXTERNAL_RESOURCES_ATTRIBUTE_DISPLAY = 'externalResources'; // In the action bar
    const EXTERNAL_RESOURCES_ATTRIBUTE_KEY = 'externalresources'; // In the code

    // Simple cache bursting implementation for the webCodeConsole.(js|css) file
    // They must be incremented manually when they changed
    const WEB_CSS_VERSION = 1.1;
    const WEB_CONSOLE_JS_VERSION = 2.1;

    const TAG = 'webcode';

    /**
     * The tag that have codes
     */
    const CODE_TAGS =
        array(
            syntax_plugin_combo_code::CODE_TAG,
            "plugin_combo_code",
            syntax_plugin_combo_codemarkdown::TAG
        );

    /**
     * The attribute names in the array
     */
    const CODES_ATTRIBUTE = "codes";
    const USE_CONSOLE_ATTRIBUTE = "useConsole";
    const RENDERING_MODE_ATTRIBUTE = 'renderingmode';
    const RENDERING_ONLY_RESULT = "onlyresult";

    /**
     * Marki code
     */
    const MARKI_LANG = 'marki';
    const DOKUWIKI_LANG = 'dw';
    const MARKIS = [self::MARKI_LANG, self::DOKUWIKI_LANG];

    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in $PARSER_MODES in parser.php
     * @see https://www.dokuwiki.org/devel:syntax_plugins#syntax_types
     *
     * container because it may contain header in case of how to
     */
    public function getType()
    {
        return 'container';
    }

    public function getPType()
    {
        return "stack";
    }


    /**
     * @return array
     * Allow which kind of plugin inside
     *
     * array('container', 'baseonly','formatting', 'substition', 'protected', 'disabled', 'paragraphs')
     *
     */
    public function getAllowedTypes()
    {
        return array('container', 'baseonly', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs');
    }


    public function accepts($mode)
    {

        return syntax_plugin_combo_preformatted::disablePreformatted($mode);

    }

    /**
     * @see Doku_Parser_Mode::getSort()
     * The mode (plugin) with the lowest sort number will win out
     *
     * See {@link Doku_Parser_Mode_code}
     */
    public function getSort()
    {
        return 99;
    }

    /**
     * Called before any calls to ConnectTo
     * @return void
     */
    function preConnect()
    {
    }

    /**
     * Create a pattern that will called this plugin
     *
     * @param string $mode
     *
     * All dokuwiki mode can be seen in the parser.php file
     * @see Doku_Parser_Mode::connectTo()
     */
    public function connectTo($mode)
    {

        $pattern = PluginUtility::getContainerTagPattern(self::TAG);
        $this->Lexer->addEntryPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));

    }


    // This where the addPattern and addExitPattern are defined
    public function postConnect()
    {
        $this->Lexer->addExitPattern('</' . self::TAG . '>', PluginUtility::getModeFromTag($this->getPluginComponent()));
    }


    /**
     * Handle the match
     * You get the match for each pattern in the $match variable
     * $state says if it's an entry, exit or match pattern
     *
     * This is an instruction block and is cached apart from the rendering output
     * There is two caches levels
     * This cache may be suppressed with the url parameters ?purge=true
     *
     * The returned values are cached in an array that will be passed to the render method
     * The handle function goal is to parse the matched syntax through the pattern function
     * and to return the result for use in the renderer
     * This result is always cached until the page is modified.
     * @param string $match
     * @param int $state
     * @param int $pos
     * @param Doku_Handler $handler
     * @return array|bool
     * @throws Exception
     * @see DokuWiki_Syntax_Plugin::handle()
     *
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        switch ($state) {

            case DOKU_LEXER_ENTER :

                // Default
                $defaultAttributes = array();
                $defaultAttributes['frameborder'] = 1;
                $defaultAttributes['width'] = '100%';
                $defaultAttributes['name'] = "WebCode iFrame";
                $defaultAttributes[self::RENDERING_MODE_ATTRIBUTE] = 'story';
                // 'height' is set by the javascript if not set
                // 'width' and 'scrolling' gets their natural value

                // Parse and create the call stack array
                $tagAttributes = TagAttributes::createFromTagMatch($match, $defaultAttributes);
                $callStackArray = $tagAttributes->toCallStackArray();

                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $callStackArray
                );


            case DOKU_LEXER_UNMATCHED :

                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);


            case DOKU_LEXER_EXIT:

                /**
                 * Capture all codes
                 */
                $codes = array();
                /**
                 * Does the javascript contains a console statement
                 */
                $useConsole = false;

                /**
                 * Callstack
                 */
                $callStack = CallStack::createFromHandler($handler);
                $openingTag = $callStack->moveToPreviousCorrespondingOpeningCall();
                $renderingMode = strtolower($openingTag->getAttribute(self::RENDERING_MODE_ATTRIBUTE));

                /**
                 * The mime (ie xml,html, ...) and code content are in two differents
                 * call. To be able to set the content to the good type
                 * we keep a trace of it
                 */
                $actualCodeType = "";

                /**
                 * Loop
                 */
                while ($actualTag = $callStack->next()) {


                    $tagName = $actualTag->getTagName();
                    if (in_array($tagName, self::CODE_TAGS)) {

                        /**
                         * Only rendering mode, we don't display the node
                         * on all node (enter, exit and unmatched)
                         */
                        if ($renderingMode == self::RENDERING_ONLY_RESULT) {
                            $actualTag->addAttribute(TagAttributes::DISPLAY, "none");
                        }

                        switch ($actualTag->getState()) {

                            case DOKU_LEXER_ENTER:
                                // Get the code (The content between the code nodes)
                                // We ltrim because the match gives us the \n at the beginning and at the end
                                $actualCodeType = strtolower(trim($actualTag->getType()));

                                // Xml is html
                                if ($actualCodeType == 'xml') {
                                    $actualCodeType = 'html';
                                }

                                // markdown, dokuwiki is marki
                                if (in_array($actualCodeType, ['md', 'markdown', 'dw'])) {
                                    $actualCodeType = self::MARKI_LANG;
                                }

                                // The code for a language may be scattered in multiple block
                                if (!isset($codes[$actualCodeType])) {
                                    $codes[$actualCodeType] = "";
                                }

                                continue 2;

                            case DOKU_LEXER_UNMATCHED:

                                $codeContent = $actualTag->getPluginData()[PluginUtility::PAYLOAD];

                                if (empty($actualCodeType)) {
                                    LogUtility::msg("The type of the code should not be null for the code content " . $codeContent, LogUtility::LVL_MSG_WARNING, self::TAG);
                                    continue 2;
                                }

                                // Append it
                                $codes[$actualCodeType] = $codes[$actualCodeType] . $codeContent;

                                // Check if a javascript console function is used, only if the flag is not set to true
                                if (!$useConsole == true) {
                                    if (in_array($actualCodeType, array('babel', 'javascript', 'html', 'xml'))) {
                                        // if the code contains 'console.'
                                        $result = preg_match('/' . 'console\.' . '/is', $codeContent);
                                        if ($result) {
                                            $useConsole = true;
                                        }
                                    }
                                }
                                // Reset
                                $actualCodeType = "";
                                break;

                        }
                    }

                }

                return array(
                    PluginUtility::STATE => $state,
                    self::CODES_ATTRIBUTE => $codes,
                    self::USE_CONSOLE_ATTRIBUTE => $useConsole,
                    PluginUtility::ATTRIBUTES => $openingTag->getAttributes()
                );

        }
        return false;

    }

    /**
     * Render the output
     * @param string $mode
     * @param Doku_Renderer $renderer
     * @param array $data - what the function handle() return'ed
     * @return bool - rendered correctly (not used)
     *
     * The rendering process
     * @see DokuWiki_Syntax_Plugin::render()
     *
     */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        // The $data variable comes from the handle() function
        //
        // $mode = 'xhtml' means that we output html
        // There is other mode such as metadata where you can output data for the headers (Not 100% sure)
        if ($mode == 'xhtml') {


            /** @var Doku_Renderer_xhtml $renderer */

            $state = $data[PluginUtility::STATE];
            switch ($state) {


                case DOKU_LEXER_UNMATCHED :

                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;

                case DOKU_LEXER_EXIT :
                    $codes = $data[self::CODES_ATTRIBUTE];
                    $callStackArray = $data[PluginUtility::ATTRIBUTES];
                    $iFrameAttributes = TagAttributes::createFromCallStackArray($callStackArray, self::TAG);

                    // Create the real output of webcode
                    if (sizeof($codes) == 0) {
                        return false;
                    }

                    // Credits bar
                    $bar = '<div class="webcode-bar">';


                    // Dokuwiki Code ?
                    if (array_key_exists(self::MARKI_LANG, $codes)) {

                        $markiCode = $codes[self::MARKI_LANG];
                        /**
                         * By default, markup code
                         * is rendered inside the page
                         * We got less problem such as iframe overflow
                         * due to lazy loading, such as relative link, ...
                         *
                         */
                        if (!$iFrameAttributes->hasComponentAttribute("iframe")) {
                            $renderer->doc .= PluginUtility::render($markiCode);
                            return true;
                        }

                        $queryParams = array(
                            'call' => action_plugin_combo_webcode::CALL_ID,
                            action_plugin_combo_webcode::MARKI_PARAM => $markiCode
                        );
                        $queryString = http_build_query($queryParams);
                        $url = Site::getAjaxUrl() . "?$queryString";
                        $iFrameAttributes->addHtmlAttributeValue("src", $url);

                    } else {


                        // Js, Html, Css
                        $iframeSrcValue = '<html><head>';
                        $iframeSrcValue .= '<meta http-equiv="content-type" content="text/html; charset=UTF-8"/>';
                        $iframeSrcValue .= '<title>Made by WebCode</title>';
                        $iframeSrcValue .= '<link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/3.0.3/normalize.min.css"/>';


                        // External Resources such as css stylesheet or js
                        $externalResources = [];
                        if ($iFrameAttributes->hasComponentAttribute(self::EXTERNAL_RESOURCES_ATTRIBUTE_KEY)) {
                            $resources = $iFrameAttributes->getValueAndRemove(self::EXTERNAL_RESOURCES_ATTRIBUTE_KEY);
                            $externalResources = explode(",", $resources);
                        }

                        // Babel Preprocessor, if babel is used, add it to the external resources
                        if (array_key_exists('babel', $codes)) {
                            $babelMin = "https://unpkg.com/babel-standalone@6/babel.min.js";
                            // a load of babel invoke it (be sure to not have it twice
                            if (!(array_key_exists($babelMin, $externalResources))) {
                                $externalResources[] = $babelMin;
                            }
                        }

                        // Add the external resources
                        foreach ($externalResources as $externalResource) {
                            $pathInfo = pathinfo($externalResource);
                            $fileExtension = $pathInfo['extension'];
                            switch ($fileExtension) {
                                case 'css':
                                    $iframeSrcValue .= '<link rel="stylesheet" type="text/css" href="' . $externalResource . '"/>';
                                    break;
                                case 'js':
                                    $iframeSrcValue .= '<script type="text/javascript" src="' . $externalResource . '"></script>';
                                    break;
                            }
                        }


                        // WebConsole style sheet
                        $iframeSrcValue .= '<link rel="stylesheet" type="text/css" href="' . PluginUtility::getResourceBaseUrl() . '/webcode/webcode-iframe.css?ver=' . self::WEB_CSS_VERSION . '"/>';

                        // A little margin to make it neater
                        // that can be overwritten via cascade
                        $iframeSrcValue .= '<style>body { margin:10px } /* default margin */</style>';

                        // The css
                        if (array_key_exists('css', $codes)) {
                            $iframeSrcValue .= '<!-- The CSS code -->';
                            $iframeSrcValue .= '<style>' . $codes['css'] . '</style>';
                        };
                        $iframeSrcValue .= '</head><body>';
                        if (array_key_exists('html', $codes)) {
                            $iframeSrcValue .= '<!-- The HTML code -->';
                            $iframeSrcValue .= $codes['html'];
                        }
                        // The javascript console area is based at the end of the HTML document
                        $useConsole = $data[self::USE_CONSOLE_ATTRIBUTE];
                        if ($useConsole) {
                            $iframeSrcValue .= '<!-- WebCode Console -->';
                            $iframeSrcValue .= '<div><p class="webConsoleTitle">Console Output:</p>';
                            $iframeSrcValue .= '<div id="webCodeConsole"></div>';
                            $iframeSrcValue .= '<script type="text/javascript" src="' . PluginUtility::getResourceBaseUrl() . '/webcode/webcode-console.js?ver=' . self::WEB_CONSOLE_JS_VERSION . '"></script>';
                            $iframeSrcValue .= '</div>';
                        }
                        // The javascript comes at the end because it may want to be applied on previous HTML element
                        // as the page load in the IO order, javascript must be placed at the end
                        if (array_key_exists('javascript', $codes)) {
                            $iframeSrcValue .= '<!-- The Javascript code -->';
                            $iframeSrcValue .= '<script type="text/javascript">' . $codes['javascript'] . '</script>';
                        }
                        if (array_key_exists('babel', $codes)) {
                            $iframeSrcValue .= '<!-- The Babel code -->';
                            $iframeSrcValue .= '<script type="text/babel">' . $codes['babel'] . '</script>';
                        }
                        $iframeSrcValue .= '</body></html>';
                        $iFrameAttributes->addHtmlAttributeValue("srcdoc", $iframeSrcValue);

                        // Code bar with button
                        $bar .= '<div class="webcode-bar-item">' . PluginUtility::getUrl(self::TAG, "Rendered by WebCode", false) . '</div>';
                        $bar .= '<div class="webcode-bar-item">' . $this->addJsFiddleButton($codes, $externalResources, $useConsole, $iFrameAttributes->getValue("name")) . '</div>';


                    }

                    /**
                     * If there is no height
                     */
                    if (!$iFrameAttributes->hasComponentAttribute(Dimension::HEIGHT_KEY)) {

                        /**
                         * Adjust the height attribute
                         * of the iframe element
                         * Any styling attribute would take over
                         */
                        PluginUtility::getSnippetManager()->attachJavascriptSnippetForBar(self::TAG);
                        /**
                         * CSS Height auto works when an image is loaded asynchronously but not
                         * when there is only text in the iframe
                         */
                        //$iFrameAttributes->addStyleDeclaration("height", "auto");
                        /**
                         * Due to margin at the bottom with css height=auto,
                         * we may see a scroll bar
                         * This block of code is to avoid scrolling,
                         * then scrolling = no if not set
                         */
                        if (!$iFrameAttributes->hasComponentAttribute("scrolling")) {
                            $iFrameAttributes->addHtmlAttributeValue("scrolling", "no");
                        }

                    }


                    PluginUtility::getSnippetManager()->attachCssSnippetForBar(self::TAG);

                    /**
                     * The iframe does not have any width
                     * By default, we set it to 100% and it can be
                     * constraint with the `width` attributes that will
                     * set a a max-width
                     */
                    $iFrameAttributes->addStyleDeclaration("width","100%");

                    $iFrameHtml = $iFrameAttributes->toHtmlEnterTag("iframe") . '</iframe>';
                    $bar .= '</div>'; // close the bar
                    $renderer->doc .= "<div class=\"webcode-wrapper\">" . $iFrameHtml . $bar . '</div>';


                    break;
            }

            return true;
        }
        return false;
    }

    /**
     * @param array $codes the array containing the codes
     * @param array $externalResources the attributes of a call (for now the externalResources)
     * @param bool $useConsole
     * @param string $snippetTitle
     * @return string the HTML form code
     *
     * Specification, see http://doc.jsfiddle.net/api/post.html
     */
    public function addJsFiddleButton($codes, $externalResources, $useConsole = false, $snippetTitle = null)
    {

        $postURL = "https://jsfiddle.net/api/post/library/pure/"; //No Framework


        if ($useConsole) {
            // If their is a console.log function, add the Firebug Lite support of JsFiddle
            // Seems to work only with the Edge version of jQuery
            // $postURL .= "edge/dependencies/Lite/";
            // The firebug logging is not working anymore because of 404
            // Adding them here
            $externalResources[] = 'The firebug resources for the console.log features';
            $externalResources[] = PluginUtility::getResourceBaseUrl() . '/firebug/firebug-lite.css';
            $externalResources[] = PluginUtility::getResourceBaseUrl() . '/firebug/firebug-lite-1.2.js';
        }

        // The below code is to prevent this JsFiddle bug: https://github.com/jsfiddle/jsfiddle-issues/issues/726
        // The order of the resources is not guaranteed
        // We pass then the resources only if their is one resources
        // Otherwise we pass them as a script element in the HTML.
        if (count($externalResources) <= 1) {
            $externalResourcesInput = '<input type="hidden" name="resources" value="' . implode(",", $externalResources) . '"/>';
        } else {
            $codes['html'] .= "\n\n\n\n\n<!-- The resources -->\n";
            $codes['html'] .= "<!-- They have been added here because their order is not guarantee through the API. -->\n";
            $codes['html'] .= "<!-- See: https://github.com/jsfiddle/jsfiddle-issues/issues/726 -->\n";
            foreach ($externalResources as $externalResource) {
                if ($externalResource != "") {
                    $extension = pathinfo($externalResource)['extension'];
                    switch ($extension) {
                        case "css":
                            $codes['html'] .= "<link href=\"" . $externalResource . "\" rel=\"stylesheet\">\n";
                            break;
                        case "js":
                            $codes['html'] .= "<script src=\"" . $externalResource . "\"></script>\n";
                            break;
                        default:
                            $codes['html'] .= "<!-- " . $externalResource . " -->\n";
                    }
                }
            }
        }

        $jsCode = $codes['javascript'];
        $jsPanel = 0; // language for the js specific panel (0 = JavaScript)
        if (array_key_exists('babel', $codes)) {
            $jsCode = $codes['babel'];
            $jsPanel = 3; // 3 = Babel
        }

        // Title and description
        global $ID;
        $pageTitle = tpl_pagetitle($ID, true);
        if (!$snippetTitle) {

            $snippetTitle = "Code from " . $pageTitle;
        }
        $description = "Code from the page '" . $pageTitle . "' \n" . wl($ID, $absolute = true);
        return '<form  method="post" action="' . $postURL . '" target="_blank">' .
            '<input type="hidden" name="title" value="' . htmlentities($snippetTitle) . '"/>' .
            '<input type="hidden" name="description" value="' . htmlentities($description) . '"/>' .
            '<input type="hidden" name="css" value="' . htmlentities($codes['css']) . '"/>' .
            '<input type="hidden" name="html" value="' . htmlentities("<!-- The HTML -->" . $codes['html']) . '"/>' .
            '<input type="hidden" name="js" value="' . htmlentities($jsCode) . '"/>' .
            '<input type="hidden" name="panel_js" value="' . htmlentities($jsPanel) . '"/>' .
            '<input type="hidden" name="wrap" value="b"/>' .  //javascript no wrap in body
            $externalResourcesInput .
            '<button>Try the code</button>' .
            '</form>';

    }

    /**
     * @param $codes the array containing the codes
     * @param $attributes the attributes of a call (for now the externalResources)
     * @return string the HTML form code
     */
    public function addCodePenButton($codes, $attributes)
    {
        // TODO
        // http://blog.codepen.io/documentation/api/prefill/
    }


}
