<?php


// must be run within Dokuwiki
use ComboStrap\Background;
use ComboStrap\CacheMedia;
use ComboStrap\CallStack;
use ComboStrap\ColorUtility;
use ComboStrap\Dimension;
use ComboStrap\LinkUtility;
use ComboStrap\MediaLink;
use ComboStrap\PluginUtility;
use ComboStrap\Position;
use ComboStrap\TagAttributes;

if (!defined('DOKU_INC')) die();

/**
 * Implementation of a background
 *
 *
 * Cool calm example of moving square background
 * https://codepen.io/Lewitje/pen/BNNJjo
 * Particles.js
 * https://codepen.io/akey96/pen/oNgeQYX
 * Gradient positioning above a photo
 * https://codepen.io/uzoawili/pen/GypGOy
 * Fire flies
 * https://codepen.io/mikegolus/pen/Jegvym
 *
 * z-index:100 could also be on the front
 * https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_Positioning/Understanding_z_index/Stacking_without_z-index
 * https://getbootstrap.com/docs/5.0/layout/z-index/
 */
class syntax_plugin_combo_background extends DokuWiki_Syntax_Plugin
{

    const TAG = "background";
    const TAG_SHORT = "bg";
    const ERROR = "error";

    /**
     * Function used in the special and enter tag
     * @param $match
     * @return array
     */
    private static function getAttributesAndAddBackgroundPrefix($match)
    {

        $attributes = PluginUtility::getTagAttributes($match);
        if (isset($attributes[ColorUtility::COLOR])) {
            $attributes[Background::BACKGROUND_COLOR] = $attributes[ColorUtility::COLOR];
            unset($attributes[ColorUtility::COLOR]);
        }
        return $attributes;

    }


    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in {@link $PARSER_MODES} in parser.php
     * @see DokuWiki_Syntax_Plugin::getType()
     */
    function getType()
    {
        return 'container';
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
        /**
         * block to not create p_open calls
         */
        return 'block';
    }

    /**
     * @return array
     * Allow which kind of plugin inside
     *
     * Array('baseonly','container', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs')
     *
     * Return an array of one or more of the mode types {@link $PARSER_MODES} in Parser.php
     */
    function getAllowedTypes()
    {
        return array('baseonly', 'container', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs');
    }


    function getSort()
    {
        return 201;
    }


    function connectTo($mode)
    {

        foreach ($this->getTags() as $tag) {
            $pattern = PluginUtility::getContainerTagPattern($tag);
            $this->Lexer->addEntryPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));
            $emptyPattern = PluginUtility::getEmptyTagPattern($tag);
            $this->Lexer->addSpecialPattern($emptyPattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));
        }


    }


    function postConnect()
    {

        foreach ($this->getTags() as $tag) {
            $this->Lexer->addExitPattern('</' . $tag . '>', PluginUtility::getModeFromTag($this->getPluginComponent()));
        }

    }

    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_ENTER :

                /**
                 * Get and Add the background prefix
                 */
                $attributes = self::getAttributesAndAddBackgroundPrefix($match);
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $attributes
                );

            case DOKU_LEXER_SPECIAL :

                $attributes = self::getAttributesAndAddBackgroundPrefix($match);
                $callStack = CallStack::createFromHandler($handler);
                return $this->setAttributesToParentAndReturnData($callStack, $attributes, $state);

            case DOKU_LEXER_UNMATCHED :
                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);

            case DOKU_LEXER_EXIT :

                $callStack = CallStack::createFromHandler($handler);
                $openingTag = $callStack->moveToPreviousCorrespondingOpeningCall();
                $backgroundAttributes = $openingTag->getAttributes();

                /**
                 * if the media syntax of Combo is not used, try to retrieve the media of dokuwiki
                 */
                $imageTag = [syntax_plugin_combo_media::TAG, MediaLink::INTERNAL_MEDIA_CALL_NAME];

                /**
                 * Collect the image if any
                 */
                while ($actual = $callStack->next()) {

                    $tagName = $actual->getTagName();
                    if (in_array($tagName, $imageTag)) {
                        $imageAttribute = $actual->getAttributes();
                        if ($tagName == syntax_plugin_combo_media::TAG) {
                            $backgroundImageAttribute = Background::fromMediaToBackgroundImageStackArray($imageAttribute);
                        } else {
                            /**
                             * As seen in {@link Doku_Handler::media()}
                             */
                            $backgroundImageAttribute = [
                                MediaLink::MEDIA_DOKUWIKI_TYPE => MediaLink::INTERNAL_MEDIA_CALL_NAME,
                                MediaLink::PATH_ATTRIBUTE => $imageAttribute[0],
                                Dimension::WIDTH_KEY => $imageAttribute[3],
                                Dimension::HEIGHT_KEY => $imageAttribute[4],
                                CacheMedia::CACHE_KEY => $imageAttribute[5]
                            ];
                        }
                        $backgroundAttributes[Background::BACKGROUND_IMAGE] = $backgroundImageAttribute;
                        $callStack->deleteActualCallAndPrevious();
                    }
                }


                return $this->setAttributesToParentAndReturnData($callStack, $backgroundAttributes, $state);


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
                     * background is print via the {@link Background::processBackgroundAttributes()}
                     */
                    break;
                case DOKU_LEXER_EXIT :
                case DOKU_LEXER_SPECIAL :
                    /**
                     * Print any error
                     */
                    if (isset($data[self::ERROR])) {
                        $class = LinkUtility::TEXT_ERROR_CLASS;
                        $error = $data[self::ERROR];
                        $renderer->doc .= "<p class=\"$class\">$error</p>" . DOKU_LF;
                    }
                    break;
                case DOKU_LEXER_UNMATCHED:
                    /**
                     * In case anyone put text where it should not
                     */
                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;

            }
            return true;

        } else if ($format == "metadata") {

            /**
             * @var Doku_Renderer_metadata $renderer
             */
            $attributes = $data[PluginUtility::ATTRIBUTES];
            if (isset($attributes[Background::BACKGROUND_IMAGE])) {
                $image = $attributes[Background::BACKGROUND_IMAGE];
                syntax_plugin_combo_media::registerImageMeta($image, $renderer);
            }
        }

        // unsupported $mode
        return false;
    }

    private function getTags()
    {
        return [self::TAG, self::TAG_SHORT];
    }

    /**
     * @param CallStack $callStack
     * @param array $backgroundAttributes
     * @param $state
     * @return array
     */
    public function setAttributesToParentAndReturnData(CallStack $callStack, array $backgroundAttributes, $state)
    {

        /**
         * The data array
         */
        $data = array();

        /**
         * Set the backgrounds attributes
         * to the parent
         * There is two state (special and exit)
         * Go to the opening call if in exit
         */
        if($state== DOKU_LEXER_EXIT) {
            $callStack->moveToEnd();
            $callStack->moveToPreviousCorrespondingOpeningCall();
        }
        $parentCall = $callStack->moveToParent();

        if ($parentCall != false) {
            if ($parentCall->getTagName() == Background::BACKGROUNDS) {
                /**
                 * The backgrounds node
                 * (is already relative)
                 */
                $parentCall = $callStack->moveToParent();
            } else {
                /**
                 * Another parent node
                 * With a image background, the node should be relative
                 */
                if (isset($backgroundAttributes[Background::BACKGROUND_IMAGE])) {
                    $parentCall->addAttribute(Position::POSITION_ATTRIBUTE, "relative");
                }
            }
            $backgrounds = $parentCall->getAttribute(Background::BACKGROUNDS);
            if ($backgrounds == null) {
                $backgrounds = [$backgroundAttributes];
            } else {
                $backgrounds[] = $backgroundAttributes;
            }
            $parentCall->addAttribute(Background::BACKGROUNDS, $backgrounds);

        } else {
            $data[self::ERROR] = "A background should have a parent";
        }

        /**
         * Return state to keep the call stack structure
         * and the image data for the metadata
         */
        $data[PluginUtility::STATE] = $state;
        $data[PluginUtility::ATTRIBUTES] = $backgroundAttributes;
        return $data;

    }


}

