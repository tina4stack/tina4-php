<?php
/**
 * Front Matter implementation to add metadata
 *
 *
 * that enhance the metadata dokuwiki system
 * https://www.dokuwiki.org/metadata
 * that use the Dublin Core Standard
 * http://dublincore.org/
 * by adding the front matter markup specification
 * https://gerardnico.com/markup/front-matter
 *
 * Inspiration
 * https://github.com/dokufreaks/plugin-meta/blob/master/syntax.php
 * https://www.dokuwiki.org/plugin:semantic
 *
 * See also structured plugin
 * https://www.dokuwiki.org/plugin:data
 * https://www.dokuwiki.org/plugin:struct
 *
 */

use ComboStrap\LogUtility;
use ComboStrap\Page;
use ComboStrap\PluginUtility;

require_once(__DIR__ . '/../class/PluginUtility.php');

if (!defined('DOKU_INC')) {
    die();
}

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 *
 * For a list of meta, see also https://ghost.org/docs/publishing/#api-data
 */
class syntax_plugin_combo_frontmatter extends DokuWiki_Syntax_Plugin
{
    const PARSING_STATE_EMPTY = "empty";
    const PARSING_STATE_ERROR = "error";
    const PARSING_STATE_SUCCESSFUL = "successful";
    const STATUS = "status";
    const CANONICAL = "frontmatter";
    const CONF_ENABLE_SECTION_EDITING = 'enableFrontMatterSectionEditing';

    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in $PARSER_MODES in parser.php
     * @see https://www.dokuwiki.org/devel:syntax_plugins#syntax_types
     *
     * Return an array of one or more of the mode types {@link $PARSER_MODES} in Parser.php
     *
     * baseonly - run only in the base
     */
    function getType()
    {
        return 'baseonly';
    }

    public function getPType()
    {
        /**
         * This element create a section
         * element that is a div
         * that should not be in paragraph
         *
         * We make it a block
         */
        return "block";
    }


    /**
     * @see Doku_Parser_Mode::getSort()
     * Higher number than the teaser-columns
     * because the mode with the lowest sort number will win out
     */
    function getSort()
    {
        return 99;
    }

    /**
     * Create a pattern that will called this plugin
     *
     * @param string $mode
     * @see Doku_Parser_Mode::connectTo()
     */
    function connectTo($mode)
    {
        if ($mode == "base") {
            // only from the top
            $this->Lexer->addSpecialPattern('---json.*?---', $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));
        }
    }

    /**
     *
     * The handle function goal is to parse the matched syntax through the pattern function
     * and to return the result for use in the renderer
     * This result is always cached until the page is modified.
     * @param string $match
     * @param int $state
     * @param int $pos
     * @param Doku_Handler $handler
     * @return array|bool
     * @see DokuWiki_Syntax_Plugin::handle()
     *
     */
    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        if ($state == DOKU_LEXER_SPECIAL) {

            // strip
            //   from start `---json` + eol = 8
            //   from end   `---` + eol = 4
            $jsonString = substr($match, 7, -3);

            // Empty front matter
            if (trim($jsonString) == "") {
                $this->deleteKnownMetaThatAreNoMorePresent();
                return array(self::STATUS => self::PARSING_STATE_EMPTY);
            }

            // Otherwise you get an object ie $arrayFormat-> syntax
            $arrayFormat = true;
            $jsonArray = json_decode($jsonString, $arrayFormat);

            $result = [];
            // Decodage problem
            if ($jsonArray == null) {
                $result[self::STATUS] = self::PARSING_STATE_ERROR;
                $result[PluginUtility::PAYLOAD] = $match;
            } else {
                $result[self::STATUS] = self::PARSING_STATE_SUCCESSFUL;
                $result[PluginUtility::ATTRIBUTES] = $jsonArray;
            }

            /**
             * End position is the length of the match + 1 for the newline
             */
            $newLine = 1;
            $endPosition = $pos + strlen($match) + $newLine;
            $result[PluginUtility::POSITION] = [$pos, $endPosition];

            return $result;
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

        switch ($format) {
            case 'xhtml':
                global $ID;
                /** @var Doku_Renderer_xhtml $renderer */

                $state = $data[self::STATUS];
                if ($state == self::PARSING_STATE_ERROR) {
                    $json = $data[PluginUtility::PAYLOAD];
                    LogUtility::msg("Front Matter: The json object for the page ($ID) is not valid. See the errors it by clicking on <a href=\"https://jsonformatter.curiousconcept.com/?data=" . urlencode($json) . "\">this link</a>.", LogUtility::LVL_MSG_ERROR);
                }

                /**
                 * Section
                 */
                list($startPosition, $endPosition) = $data[PluginUtility::POSITION];
                if (PluginUtility::getConfValue(self::CONF_ENABLE_SECTION_EDITING, 1)) {
                    $position = $startPosition;
                    $name = self::CANONICAL;
                    PluginUtility::startSection($renderer, $position, $name);
                    $renderer->finishSectionEdit($endPosition);
                }
                break;
            case renderer_plugin_combo_analytics::RENDERER_FORMAT:

                if ($data[self::STATUS] != self::PARSING_STATE_SUCCESSFUL) {
                    return false;
                }

                /** @var renderer_plugin_combo_analytics $renderer */
                $jsonArray = $data[PluginUtility::ATTRIBUTES];
                if (array_key_exists("description", $jsonArray)) {
                    $renderer->setMeta("description", $jsonArray["description"]);
                }
                if (array_key_exists(Page::CANONICAL_PROPERTY, $jsonArray)) {
                    $renderer->setMeta(Page::CANONICAL_PROPERTY, $jsonArray[Page::CANONICAL_PROPERTY]);
                }
                if (array_key_exists(Page::TITLE_PROPERTY, $jsonArray)) {
                    $renderer->setMeta(Page::TITLE_PROPERTY, $jsonArray[Page::TITLE_PROPERTY]);
                }
                if (array_key_exists(Page::LOW_QUALITY_PAGE_INDICATOR, $jsonArray)) {
                    $renderer->setMeta(Page::LOW_QUALITY_PAGE_INDICATOR, $jsonArray[Page::LOW_QUALITY_PAGE_INDICATOR]);
                }
                break;
            case "metadata":

                if ($data[self::STATUS] != self::PARSING_STATE_SUCCESSFUL) {
                    return false;
                }

                global $ID;
                $jsonArray = $data[PluginUtility::ATTRIBUTES];


                $notModifiableMeta = [
                    "date",
                    "user",
                    "last_change",
                    "creator",
                    "contributor"
                ];

                foreach ($jsonArray as $key => $value) {

                    $lowerCaseKey = trim(strtolower($key));

                    // Not modifiable metadata
                    if (in_array($lowerCaseKey, $notModifiableMeta)) {
                        LogUtility::msg("Front Matter: The metadata ($lowerCaseKey) is a protected metadata and cannot be modified", LogUtility::LVL_MSG_WARNING);
                        continue;
                    }

                    switch ($lowerCaseKey) {

                        case Page::DESCRIPTION_PROPERTY:
                            /**
                             * Overwrite also the actual description
                             */
                            p_set_metadata($ID, array(Page::DESCRIPTION_PROPERTY => array(
                                "abstract" => $value,
                                "origin" => syntax_plugin_combo_frontmatter::CANONICAL
                            )));
                            /**
                             * Continue because
                             * the description value was already stored
                             * We don't want to override it
                             * And continue 2 because continue == break in a switch
                             */
                            continue 2;


                        // Canonical should be lowercase
                        case Page::CANONICAL_PROPERTY:
                            $value = strtolower($value);
                            break;

                    }
                    // Set the value persistently
                    p_set_metadata($ID, array($lowerCaseKey => $value));

                }

                $this->deleteKnownMetaThatAreNoMorePresent($jsonArray);

                break;

        }
        return true;
    }

    /**
     *
     * @param array $json - The Json
     * Delete the controlled meta that are no more present if they exists
     * @return bool
     */
    public
    function deleteKnownMetaThatAreNoMorePresent(array $json = array())
    {
        global $ID;

        /**
         * The managed meta with the exception of
         * the {@link action_plugin_combo_metadescription::DESCRIPTION_META_KEY description}
         * because it's already managed by dokuwiki in description['abstract']
         */
        $managedMeta = [
            Page::CANONICAL_PROPERTY,
            action_plugin_combo_metatitle::TITLE_META_KEY,
            syntax_plugin_combo_disqus::META_DISQUS_IDENTIFIER
        ];
        $meta = p_read_metadata($ID);
        foreach ($managedMeta as $metaKey) {
            if (!array_key_exists($metaKey, $json)) {
                if (isset($meta['persistent'][$metaKey])) {
                    unset($meta['persistent'][$metaKey]);
                }
            }
        }
        return p_save_metadata($ID, $meta);
    }


}

