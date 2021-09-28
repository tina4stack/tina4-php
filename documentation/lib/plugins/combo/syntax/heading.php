<?php


use ComboStrap\Analytics;
use ComboStrap\Bootstrap;
use ComboStrap\Call;
use ComboStrap\CallStack;
use ComboStrap\LogUtility;
use ComboStrap\PluginUtility;
use ComboStrap\TagAttributes;


if (!defined('DOKU_INC')) die();

/**
 * Class syntax_plugin_combo_heading
 * Heading HTML super set
 *
 * It contains also all heading utility class
 *
 */
class syntax_plugin_combo_heading extends DokuWiki_Syntax_Plugin
{


    const TAG = "heading";
    const OLD_TITLE_TAG = "title"; // old tag
    const TAGS = [self::TAG, self::OLD_TITLE_TAG];

    const LEVEL = 'level';
    const DISPLAY_BS_4_RESPONSIVE_SNIPPET_ID = "display-bs-4";
    const ALL_TYPES = ["h1", "h2", "h3", "h4", "h5", "h6", "d1", "d2", "d3", "d4", "d5", "d6"];
    const DISPLAY_TYPES = ["d1", "d2", "d3", "d4", "d5", "d6"];
    const DISPLAY_TYPES_ONLY_BS_5 = ["d5", "d6"]; // only available in 5

    /**
     * An heading may be printed
     * as outline and should be in the toc
     */
    const TYPE_OUTLINE = "outline";
    const HEADING_TYPES = ["h1", "h2", "h3", "h4", "h5", "h6"];
    /**
     * The attribute that holds only the text of the heading
     * (used to create the id and the text in the toc)
     */
    const HEADING_TEXT_ATTRIBUTE = "heading_text";
    const TYPE_TITLE = "title";

    const CANONICAL = "heading";
    const SYNTAX_TYPE = 'baseonly';
    const SYNTAX_PTYPE = 'block';

    /**
     * The default level if not set
     * Not level 1 because this is the top level heading
     * Not level 2 because this is the most used level and we can confound with it
     */
    const DEFAULT_LEVEL = "3";

    /**
     * A common function used to handle exit of headings
     * @param CallStack $callStack
     * @return array
     */
    public static function handleExit(CallStack $callStack)
    {
        /**
         * Delete the last space if any
         */
        $callStack->moveToEnd();
        $previous = $callStack->previous();
        if ($previous->getState() == DOKU_LEXER_UNMATCHED) {
            $previous->setPayload(rtrim($previous->getCapturedContent()));
        }
        $callStack->next();

        /**
         * Get context data
         */
        $openingTag = $callStack->moveToPreviousCorrespondingOpeningCall();
        $openingAttributes = $openingTag->getAttributes(); // for level
        $context = $openingTag->getContext(); // for sectioning

        return array(
            PluginUtility::STATE => DOKU_LEXER_EXIT,
            PluginUtility::ATTRIBUTES => $openingAttributes,
            PluginUtility::CONTEXT => $context
        );
    }

    public static function processHeadingMetadataH1($level, $text)
    {
        /**
         * Capture the h1
         */
        if ($level == 1) {

            global $ID;
            p_set_metadata($ID, array(Analytics::H1 => trim($text)));

        }
    }


    public static function processHeadingMetadata($data, $renderer)
    {

        $state = $data[PluginUtility::STATE];
        if ($state == DOKU_LEXER_ENTER) {
            /**
             * Only outline heading metadata
             * Not component heading
             */
            $context = $data[PluginUtility::CONTEXT];
            if ($context == self::TYPE_OUTLINE) {
                $callStackArray = $data[PluginUtility::ATTRIBUTES];
                $tagAttributes = TagAttributes::createFromCallStackArray($callStackArray);
                $text = trim($tagAttributes->getValue(syntax_plugin_combo_heading::HEADING_TEXT_ATTRIBUTE));
                $level = $tagAttributes->getValue(syntax_plugin_combo_heading::LEVEL);
                self::processHeadingMetadataH1($level, $text);
                $renderer->header($text, $level, null);
            }
        }

    }

    public static function processMetadataAnalytics(array $data, renderer_plugin_combo_analytics $renderer)
    {
        $state = $data[PluginUtility::STATE];
        if ($state == DOKU_LEXER_ENTER) {
            /**
             * Only outline heading metadata
             * Not component heading
             */
            $context = $data[PluginUtility::CONTEXT];
            if ($context == self::TYPE_OUTLINE) {
                $callStackArray = $data[PluginUtility::ATTRIBUTES];
                $tagAttributes = TagAttributes::createFromCallStackArray($callStackArray);
                $text = $tagAttributes->getValue(syntax_plugin_combo_heading::HEADING_TEXT_ATTRIBUTE);
                $level = $tagAttributes->getValue(syntax_plugin_combo_heading::LEVEL);
                $renderer->header($text, $level, null);
            }
        }
    }


    /**
     * @param CallStack $callStack
     * @return string
     */
    public static function getContext($callStack)
    {

        /**
         * If the heading is inside a component,
         * it's a title heading, otherwise it's a outline heading
         * (Except for {@link syntax_plugin_combo_webcode} that can wrap outline heading)
         *
         * When the parent is empty, a section_open (ie another outline heading)
         * this is a outline
         */
        $parent = $callStack->moveToParent();
        if ($parent!=false && $parent->getTagName() == syntax_plugin_combo_webcode::TAG){
            $parent = $callStack->moveToParent();
        }
        if ($parent != false && $parent->getComponentName() != "section_open") {
            $headingType = self::TYPE_TITLE;
        } else {
            $headingType = self::TYPE_OUTLINE;
        }

        switch ($headingType) {
            case syntax_plugin_combo_heading::TYPE_TITLE:

                $context = $parent->getTagName();
                break;

            case syntax_plugin_combo_heading::TYPE_OUTLINE:

                $context = syntax_plugin_combo_heading::TYPE_OUTLINE;
                break;

            default:
                LogUtility::msg("The heading type ($headingType) is unknown");
                $context = "";
                break;
        }
        return $context;
    }

    /**
     * Reduce the end of the input string
     * to the first opening tag without the ">"
     * and returns the closing tag
     *
     * @param $input
     * @return array - the heading attributes as a string
     */
    public static function reduceToFirstOpeningTagAndReturnAttributes(&$input)
    {
        // the variable that will capture the attribute string
        $headingStartTagString = "";
        // Set to true when the heading tag has completed
        $endHeadingParsed = false;
        // The closing character `>` indicator of the start and end tag
        // true when found
        $endTagClosingCharacterParsed = false;
        $startTagClosingCharacterParsed = false;
        // We start from the edn
        $position = strlen($input) - 1;
        while ($position > 0) {
            $character = $input[$position];

            if ($character == "<") {
                if (!$endHeadingParsed) {
                    // We are at the beginning of the ending tag
                    $endHeadingParsed = true;
                } else {
                    // We have delete all character until the heading start tag
                    // add the last one and exit
                    $headingStartTagString = $character . $headingStartTagString;
                    break;
                }
            }

            if ($character == ">") {
                if (!$endTagClosingCharacterParsed) {
                    // We are at the beginning of the ending tag
                    $endTagClosingCharacterParsed = true;
                } else {
                    // We have delete all character until the heading start tag
                    $startTagClosingCharacterParsed = true;
                }
            }

            if ($startTagClosingCharacterParsed) {
                $headingStartTagString = $character . $headingStartTagString;
            }


            // position --
            $position--;

        }
        $input = substr($input, 0, $position);

        if (!empty($headingStartTagString)) {
            return PluginUtility::getTagAttributes($headingStartTagString);
        } else {
            LogUtility::msg("The attributes of the heading are empty and this should not be possible");
            return [];
        }


    }

    /**
     * @param string $context
     * @param TagAttributes $tagAttributes
     * @param Doku_Renderer_xhtml $renderer
     * @param integer $pos
     */
    public static function renderOpeningTag($context, $tagAttributes, &$renderer, $pos)
    {

        /**
         * Variable
         */
        $type = $tagAttributes->getType();


        /**
         * Level
         */
        $level = $tagAttributes->getValueAndRemove(syntax_plugin_combo_heading::LEVEL);


        /**
         * Display Heading
         * https://getbootstrap.com/docs/5.0/content/typography/#display-headings
         */
        if (in_array($type, self::DISPLAY_TYPES)) {

            $displayClass = "display-$level";

            if (Bootstrap::getBootStrapMajorVersion() == "4") {
                /**
                 * Make Bootstrap display responsive
                 */
                PluginUtility::getSnippetManager()->attachCssSnippetForBar(syntax_plugin_combo_heading::DISPLAY_BS_4_RESPONSIVE_SNIPPET_ID);

                if (in_array($type, self::DISPLAY_TYPES_ONLY_BS_5)) {
                    $displayClass = "display-4";
                    LogUtility::msg("Bootstrap 4 does not support the type ($type). Switch to " . PluginUtility::getUrl(Bootstrap::CANONICAL, "bootstrap 5") . " if you want to use it. The display type was set to `d4`", LogUtility::LVL_MSG_WARNING, self::CANONICAL);
                }

            }
            $tagAttributes->addClassName($displayClass);

        }

        /**
         * Heading class
         * https://getbootstrap.com/docs/5.0/content/typography/#headings
         * Works on 4 and 5
         */
        if (in_array($type, self::HEADING_TYPES)) {
            $tagAttributes->addClassName($type);
        }

        /**
         * Card title Context class
         * TODO: should move to card
         */
        if (in_array($context, [syntax_plugin_combo_blockquote::TAG, syntax_plugin_combo_card::TAG])) {
            $tagAttributes->addClassName("card-title");
        }

        if ($context == self::TYPE_OUTLINE) {

            /**
             * Calling the {@link Doku_Renderer_xhtml::header()}
             * with the captured text to be Dokuwiki Template compatible
             * It will create the toc and the section editing
             */
            if ($tagAttributes->hasComponentAttribute(self::HEADING_TEXT_ATTRIBUTE)) {
                $tocText = $tagAttributes->getValueAndRemove(self::HEADING_TEXT_ATTRIBUTE);
                if (empty($tocText)) {
                    LogUtility::msg("The heading text should be not null on the enter tag");
                }
                if (trim(strtolower($tocText)) == "articles related") {
                    $tagAttributes->addClassName("d-print-none");
                }
            } else {
                $tocText = "Heading Text Not found";
                LogUtility::msg("The heading text attribute was not found for the toc");
            }
            // The exact position because we does not capture any EOL
            // and therefore the section should start at the first captured character
            $renderer->header($tocText, $level, $pos);
            $attributes = syntax_plugin_combo_heading::reduceToFirstOpeningTagAndReturnAttributes($renderer->doc);
            foreach ($attributes as $key => $value) {
                $tagAttributes->addComponentAttributeValue($key, $value);
            }

        }


        /**
         * Printing
         */
        $renderer->doc .= $tagAttributes->toHtmlEnterTag("h$level");

    }

    /**
     * @param TagAttributes $tagAttributes
     * @return string
     */
    public static function renderClosingTag($tagAttributes)
    {
        $level = $tagAttributes->getValueAndRemove(syntax_plugin_combo_heading::LEVEL);
        if ($level == null) {
            LogUtility::msg("The level is mandatory when closing a heading", self::CANONICAL);
        }
        return "</h$level>" . DOKU_LF;
    }


    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in $PARSER_MODES in parser.php
     * @see DokuWiki_Syntax_Plugin::getType()
     */
    function getType()
    {
        return self::SYNTAX_TYPE;
    }

    /**
     *
     * How Dokuwiki will add P element
     *
     *  * 'normal' - The plugin can be used inside paragraphs (inline)
     *  * 'block'  - Open paragraphs need to be closed before plugin output - block should not be inside paragraphs
     *  * 'stack'  - Special case. Plugin wraps other paragraphs. - Stacks can contain paragraphs
     *
     * @see DokuWiki_Syntax_Plugin::getPType()
     *
     * This is the equivalent of inline or block for css
     */
    function getPType()
    {
        return self::SYNTAX_PTYPE;
    }

    /**
     * @return array
     * Allow which kind of plugin inside
     *
     * No one of array('baseonly','container', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs')
     * because we manage self the content and we call self the parser
     *
     * Return an array of one or more of the mode types {@link $PARSER_MODES} in Parser.php
     */
    function getAllowedTypes()
    {
        return array('formatting', 'substition', 'protected', 'disabled', 'paragraphs');
    }

    /**
     *
     * @return int
     */
    function getSort()
    {
        return 50;
    }


    function connectTo($mode)
    {

        /**
         * Heading tag
         */
        foreach (self::TAGS as $tag) {
            $this->Lexer->addEntryPattern(PluginUtility::getContainerTagPattern($tag), $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));
        }

    }

    public function postConnect()
    {
        foreach (self::TAGS as $tag) {
            $this->Lexer->addExitPattern(PluginUtility::getEndTagPattern($tag), PluginUtility::getModeFromTag($this->getPluginComponent()));
        }
    }


    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {


            case DOKU_LEXER_ENTER :

                $tagAttributes = TagAttributes::createFromTagMatch($match);

                /**
                 * Level is mandatory (for the closing tag)
                 */
                $level = $tagAttributes->getValue(syntax_plugin_combo_heading::LEVEL);
                if ($level == null) {

                    /**
                     * Old title type
                     * from 1 to 4 to set the display heading
                     */
                    $type = $tagAttributes->getType();
                    if (is_numeric($type) && $type != 0) {
                        $level = $type;
                        $tagAttributes->setType("d$level");
                    }
                    /**
                     * Still null, check the type
                     */
                    if ($level == null) {
                        if (in_array($type, self::ALL_TYPES)) {
                            $level = substr($type, 1);
                        }
                    }
                    /**
                     * Still null, default level
                     */
                    if ($level == null) {
                        $level = self::DEFAULT_LEVEL;
                    }
                    /**
                     * Set the level
                     */
                    $tagAttributes->addComponentAttributeValue(self::LEVEL, $level);
                }

                /**
                 * Context determination
                 */
                $callStack = CallStack::createFromHandler($handler);
                $context = self::getContext($callStack);

                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $tagAttributes->toCallStackArray(),
                    PluginUtility::CONTEXT => $context,
                    PluginUtility::POSITION => $pos
                );

            case DOKU_LEXER_UNMATCHED :

                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);

            case DOKU_LEXER_EXIT :

                $callStack = CallStack::createFromHandler($handler);


                /**
                 * Get enter attributes and content
                 */
                $callStack->moveToEnd();
                $openingTag = $callStack->moveToPreviousCorrespondingOpeningCall();
                $context = $openingTag->getContext(); // for sectioning
                $attributes = $openingTag->getAttributes(); // for the level

                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::CONTEXT => $context,
                    PluginUtility::ATTRIBUTES => $attributes
                );


        }
        return array();

    }

    /**
     * Render the output
     * @param string $format
     * @param Doku_Renderer $renderer
     * @param array $data - what the function handle() return'ed
     * @return boolean - rendered correctly? (however, returned value is not used at the moment)
     * @see DokuWiki_Syntax_Plugin::render()
     *
     *
     */
    function render($format, Doku_Renderer $renderer, $data)
    {

        if ($format == 'xhtml') {

            /** @var Doku_Renderer_xhtml $renderer */
            $state = $data[PluginUtility::STATE];
            switch ($state) {

                case DOKU_LEXER_ENTER:
                    $parentTag = $data[PluginUtility::CONTEXT];
                    $attributes = $data[PluginUtility::ATTRIBUTES];
                    $pos = $data[PluginUtility::POSITION];
                    $tagAttributes = TagAttributes::createFromCallStackArray($attributes, syntax_plugin_combo_heading::TAG);
                    self::renderOpeningTag($parentTag, $tagAttributes, $renderer, $pos);
                    break;
                case DOKU_LEXER_UNMATCHED:
                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;
                case DOKU_LEXER_EXIT:
                    $attributes = $data[PluginUtility::ATTRIBUTES];
                    $tagAttributes = TagAttributes::createFromCallStackArray($attributes);
                    $renderer->doc .= self::renderClosingTag($tagAttributes);
                    break;

            }
        } else if ($format == renderer_plugin_combo_analytics::RENDERER_FORMAT) {

            /**
             * @var renderer_plugin_combo_analytics $renderer
             */
            syntax_plugin_combo_heading::processMetadataAnalytics($data, $renderer);

        } else if ($format == "metadata") {

            /**
             * @var Doku_Renderer_metadata $renderer
             */
            syntax_plugin_combo_heading::processHeadingMetadata($data, $renderer);

        }
        // unsupported $mode
        return false;
    }


}

