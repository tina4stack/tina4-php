<?php
/**
 * DokuWiki Syntax Plugin Combostrap.
 * Implementation of https://getbootstrap.com/docs/5.0/content/typography/#blockquotes
 *
 */

use ComboStrap\Bootstrap;
use ComboStrap\Call;
use ComboStrap\CallStack;
use ComboStrap\Dimension;
use ComboStrap\LinkUtility;
use ComboStrap\PluginUtility;
use ComboStrap\StringUtility;
use ComboStrap\Tag;
use ComboStrap\TagAttributes;

if (!defined('DOKU_INC')) {
    die();
}

require_once(__DIR__ . '/../class/PluginUtility.php');
require_once(__DIR__ . '/../class/StringUtility.php');
require_once(__DIR__ . '/../class/Tag.php');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 *
 * The name of the class must follow a pattern (don't change it)
 * ie:
 *    syntax_plugin_PluginName_ComponentName
 */
class syntax_plugin_combo_blockquote extends DokuWiki_Syntax_Plugin
{

    const TAG = "blockquote";

    /**
     * When the blockquote is a tweet
     */
    const TWEET = "tweet";
    const TWEET_SUPPORTED_LANG = array("en", "ar", "bn", "cs", "da", "de", "el", "es", "fa", "fi", "fil", "fr", "he", "hi", "hu", "id", "it", "ja", "ko", "msa", "nl", "no", "pl", "pt", "ro", "ru", "sv", "th", "tr", "uk", "ur", "vi", "zh-cn", "zh-tw");
    const CONF_TWEET_WIDGETS_THEME = "twitter:widgets:theme";
    const CONF_TWEET_WIDGETS_BORDER = "twitter:widgets:border-color";


    /**
     * @var mixed|string
     */
    static public $type = "card";


    /**
     * How Dokuwiki will add P element
     *
     *  * 'normal' - The plugin can be used inside paragraphs (inline)
     *  * 'block'  - Open paragraphs need to be closed before plugin output - block should not be inside paragraphs
     *  * 'stack'  - Special case. Plugin wraps other paragraphs. - Stacks can contain paragraphs
     *
     * @see DokuWiki_Syntax_Plugin::getPType()
     * @see https://www.dokuwiki.org/devel:syntax_plugins#ptype
     */
    function getType()
    {
        return 'container';
    }

    /**
     * @return array
     * Allow which kind of plugin inside
     *
     * ***************
     * This function has no effect because {@link syntax_plugin_combo_blockquote::accepts()}
     * is used
     * ***************
     */
    public function getAllowedTypes()
    {
        return array('container', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs');
    }

    /**
     * @param string $mode
     * @return bool
     * Allowed type
     */
    public function accepts($mode)
    {
        /**
         * header mode is disable to take over
         * and replace it with {@link syntax_plugin_combo_headingwiki}
         */
        if ($mode == "header") {
            return false;
        }

        return syntax_plugin_combo_preformatted::disablePreformatted($mode);

    }


    /**
     * How Dokuwiki will add P element
     *
     * * 'normal' - The plugin can be used inside paragraphs
     *  * 'block'  - Open paragraphs need to be closed before plugin output - block should not be inside paragraphs
     *  * 'stack'  - Special case. Plugin wraps other paragraphs. - Stacks can contain paragraphs
     *
     * @see DokuWiki_Syntax_Plugin::getPType()
     */
    function getPType()
    {
        return 'stack';
    }

    /**
     * @see Doku_Parser_Mode::getSort()
     * the mode with the lowest sort number will win out
     */
    function getSort()
    {
        return 200;
    }


    /**
     * Create a pattern that will called this plugin
     *
     * @param string $mode
     * @see Doku_Parser_Mode::connectTo()
     */
    function connectTo($mode)
    {

        $pattern = PluginUtility::getContainerTagPattern(self::TAG);
        $this->Lexer->addEntryPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));

    }

    public function postConnect()
    {

        $this->Lexer->addExitPattern('</' . self::TAG . '>', PluginUtility::getModeFromTag($this->getPluginComponent()));


    }

    /**
     *
     * The handle function goal is to parse the matched syntax through the pattern function
     * and to return the result for use in the renderer
     * This result is always cached until the page is modified.
     * @param string $match
     * @param int $state
     * @param int $pos - byte position in the original source file
     * @param Doku_Handler $handler
     * @return array|bool
     * @see DokuWiki_Syntax_Plugin::handle()
     *
     */
    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_ENTER:
                // Suppress the component name
                $defaultAttributes = array("type" => "card");
                $tagAttributes = PluginUtility::getTagAttributes($match);
                $tagAttributes = PluginUtility::mergeAttributes($tagAttributes, $defaultAttributes);
                $tag = new Tag(self::TAG, $tagAttributes, $state, $handler);
                $context = null;
                if ($tag->hasParent()) {
                    $context = $tag->getParent()->getName();
                }

                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $tagAttributes,
                    PluginUtility::CONTEXT => $context
                );


            case DOKU_LEXER_UNMATCHED :

                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);

            case DOKU_LEXER_EXIT :

                $callStack = CallStack::createFromHandler($handler);

                /**
                 * Check and add a scroll toggle if the
                 * blockquote is constrained by height
                 */
                Dimension::addScrollToggleOnClickIfNoControl($callStack);


                /**
                 * Pre-parsing:
                 *    Cite: A cite should be wrapped into a {@link syntax_plugin_combo_footer}
                 *          This should happens before the p processing because we
                 *          are adding a {@link syntax_plugin_combo_footer} which is a stack
                 *    Tweet blockquote: If a link has tweet link status, this is a tweet blockquote
                 */
                $callStack->moveToEnd();
                $openingTag = $callStack->moveToPreviousCorrespondingOpeningCall();
                $tweetUrlFound = false;
                while ($actualCall = $callStack->next()) {
                    if ($actualCall->getTagName() == syntax_plugin_combo_cite::TAG) {
                        switch ($actualCall->getState()) {
                            case DOKU_LEXER_ENTER:
                                // insert before
                                $callStack->insertBefore(Call::createComboCall(
                                    syntax_plugin_combo_footer::TAG,
                                    DOKU_LEXER_ENTER,
                                    array("class" => "blockquote-footer")
                                ));
                                break;
                            case DOKU_LEXER_EXIT:
                                // insert after
                                $callStack->insertAfter(Call::createComboCall(
                                    syntax_plugin_combo_footer::TAG,
                                    DOKU_LEXER_EXIT
                                ));
                                break;
                        }
                    }
                    if(
                        $actualCall->getTagName()==syntax_plugin_combo_link::TAG
                        && $actualCall->getState()==DOKU_LEXER_ENTER
                    ){
                        $ref = $actualCall->getAttribute(LinkUtility::ATTRIBUTE_REF);
                        if (StringUtility::match($ref, "https:\/\/twitter.com\/[^\/]*\/status\/.*")) {
                            $tweetUrlFound = true;
                        }
                    }
                }
                if ($tweetUrlFound){
                    $context = syntax_plugin_combo_blockquote::TWEET;
                    $type = $context;
                    $openingTag->setType($context);
                    $openingTag->setContext($context);
                }

                /**
                 * Because we can change the type above to tweet
                 * we set them after
                 */
                $type = $openingTag->getType();
                $context = $openingTag->getContext();
                if ($context == null) {
                    $context = $type;
                }
                $attributes = $openingTag->getAttributes();

                /**
                 * Create the paragraph
                 */
                $callStack->moveToPreviousCorrespondingOpeningCall();
                $callStack->insertEolIfNextCallIsNotEolOrBlock(); // eol is mandatory to have a paragraph if there is only content
                $paragraphAttributes["class"] = "blockquote-text";
                if ($type == "typo") {
                    $bootstrapVersion = Bootstrap::getBootStrapMajorVersion();
                    if($bootstrapVersion==Bootstrap::BootStrapFourMajorVersion) {
                        // As seen here https://getbootstrap.com/docs/4.0/content/typography/#blockquotes
                        $paragraphAttributes["class"] .= " mb-0";
                        // not on 5 https://getbootstrap.com/docs/5.0/content/typography/#blockquotes
                    }
                }
                $callStack->processEolToEndStack($paragraphAttributes);

                /**
                 * Wrap the blockquote into a card
                 *
                 * In a blockquote card, a blockquote typo is wrapped around a card
                 *
                 * We add then:
                 *   * at the body location: a card body start and a blockquote typo start
                 *   * at the end location: a card end body and a blockquote end typo
                 */
                if ($type == "card") {

                    $callStack->moveToPreviousCorrespondingOpeningCall();
                    $callEnterTypeCall = Call::createComboCall(
                        self::TAG,
                        DOKU_LEXER_ENTER,
                        array(TagAttributes::TYPE_KEY => "typo"),
                        $context
                    );
                    $cardBodyEnterCall = Call::createComboCall(
                        syntax_plugin_combo_cardbody::TAG,
                        DOKU_LEXER_ENTER
                    );
                    $firstChild = $callStack->moveToFirstChildTag();

                    if ($firstChild !== false) {
                        if ($firstChild->getTagName() == syntax_plugin_combo_header::TAG) {
                            $callStack->moveToNextSiblingTag();
                        }
                        // Head: Insert card body
                        $callStack->insertBefore($cardBodyEnterCall);
                        // Head: Insert Blockquote typo
                        $callStack->insertBefore($callEnterTypeCall);

                    } else {
                        // No child
                        // Move back
                        $callStack->moveToEnd();;
                        $callStack->moveToPreviousCorrespondingOpeningCall();
                        // Head: Insert Blockquote typo
                        $callStack->insertAfter($callEnterTypeCall);
                        // Head: Insert card body
                        $callStack->insertAfter($cardBodyEnterCall);
                    }


                    /**
                     * End
                     */
                    // Insert the card body exit
                    $callStack->moveToEnd();
                    $callStack->insertBefore(
                        Call::createComboCall(
                            self::TAG,
                            DOKU_LEXER_EXIT,
                            array("type" => "typo"),
                            $context
                        )
                    );
                    $callStack->insertBefore(
                        Call::createComboCall(
                            syntax_plugin_combo_cardbody::TAG,
                            DOKU_LEXER_EXIT
                        )
                    );
                }


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

                    /**
                     * Add the CSS
                     */
                    $snippetManager = PluginUtility::getSnippetManager();
                    $snippetManager->attachCssSnippetForBar(self::TAG);

                    /**
                     * Create the HTML
                     */
                    $blockquoteAttributes = $data[PluginUtility::ATTRIBUTES];
                    $tagAttributes = TagAttributes::createFromCallStackArray($blockquoteAttributes, self::TAG);
                    $type = $tagAttributes->getType();
                    switch ($type) {
                        case "typo":

                            $tagAttributes->addClassName("blockquote");
                            $cardTags = [syntax_plugin_combo_card::TAG, syntax_plugin_combo_cardcolumns::TAG];
                            if (in_array($data[PluginUtility::CONTEXT], $cardTags)) {
                                // As seen here: https://getbootstrap.com/docs/5.0/components/card/#header-and-footer
                                // A blockquote in a card
                                // This context is added dynamically when the blockquote is a card type
                                $tagAttributes->addClassName("mb-0");
                            }
                            $renderer->doc .= $tagAttributes->toHtmlEnterTag("blockquote") . DOKU_LF;
                            break;

                        case self::TWEET:

                            PluginUtility::getSnippetManager()->upsertTagsForBar(self::TWEET,
                                array("script" =>
                                    array(
                                        array(
                                            "id" => "twitter-wjs",
                                            "type" => "text/javascript",
                                            "aysnc" => true,
                                            "src" => "https://platform.twitter.com/widgets.js",
                                            "defer" => true
                                        ))));


                            $tagAttributes->addClassName("twitter-tweet");

                            $tweetAttributesNames = ["cards", "dnt", "conversation", "align", "width", "theme", "lang"];
                            foreach ($tweetAttributesNames as $tweetAttributesName) {
                                if ($tagAttributes->hasComponentAttribute($tweetAttributesName)) {
                                    $value = $tagAttributes->getValueAndRemove($tweetAttributesName);
                                    $tagAttributes->addHtmlAttributeValue("data-" . $tweetAttributesName, $value);
                                }
                            }

                            $renderer->doc .= $tagAttributes->toHtmlEnterTag("blockquote");
                            break;
                        case "card":
                        default:

                            /**
                             * Wrap with column
                             */
                            $context = $data[PluginUtility::CONTEXT];
                            syntax_plugin_combo_cardcolumns::addColIfBootstrap5AndCardColumns($renderer, $context);

                            /**
                             * Starting the card
                             */
                            $tagAttributes->addClassName("card");
                            $renderer->doc .= $tagAttributes->toHtmlEnterTag("div") . DOKU_LF;
                            /**
                             * The card body and blockquote body
                             * of the example (https://getbootstrap.com/docs/4.0/components/card/#header-and-footer)
                             * are added via call at
                             * the {@link DOKU_LEXER_EXIT} state of {@link syntax_plugin_combo_blockquote::handle()}
                             */
                            break;
                    }
                    break;

                case DOKU_LEXER_UNMATCHED:
                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;

                case DOKU_LEXER_EXIT:
                    // Because we can have several unmatched on a line we don't know if
                    // there is a eol
                    StringUtility::addEolCharacterIfNotPresent($renderer->doc);
                    $tagAttributes = TagAttributes::createFromCallStackArray($data[PluginUtility::ATTRIBUTES]);
                    $type = $tagAttributes->getValue(TagAttributes::TYPE_KEY);
                    switch ($type) {
                        case "card":
                            $renderer->doc .= "</div>" . DOKU_LF;
                            break;
                        case self::TWEET:
                        case "typo":
                        default:
                            $renderer->doc .= "</blockquote>" . DOKU_LF;
                            break;
                    }

                    /**
                     * Closing the masonry column
                     * (Only if this is a card blockquote)
                     */
                    if ($type == syntax_plugin_combo_card::TAG) {
                        $context = $data[PluginUtility::CONTEXT];
                        syntax_plugin_combo_cardcolumns::endColIfBootstrap5AnCardColumns($renderer, $context);
                    }

                    break;


            }
            return true;
        }
        return true;
    }


}
