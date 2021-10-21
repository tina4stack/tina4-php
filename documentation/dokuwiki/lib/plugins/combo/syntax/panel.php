<?php
/**
 * DokuWiki Syntax Plugin Combostrap.
 *
 */

use ComboStrap\LogUtility;
use ComboStrap\PluginUtility;
use ComboStrap\Tag;
use ComboStrap\TagAttributes;

if (!defined('DOKU_INC')) {
    die();
}

require_once(__DIR__ . '/../ComboStrap/PluginUtility.php');

/**
 *
 * The name of the class must follow a pattern (don't change it)
 * ie:
 *    syntax_plugin_PluginName_ComponentName
 */
class syntax_plugin_combo_panel extends DokuWiki_Syntax_Plugin
{

    const TAG = 'panel';
    const OLD_TAB_PANEL_TAG = 'tabpanel';
    const STATE = 'state';
    const SELECTED = 'selected';

    /**
     * When the panel is alone in the edit due to the sectioning
     */
    const CONTEXT_PREVIEW_ALONE = "preview_alone";
    const CONTEXT_PREVIEW_ALONE_ATTRIBUTES = array(
        self::SELECTED => true,
        TagAttributes::ID_KEY => "alone",
        TagAttributes::TYPE_KEY => syntax_plugin_combo_tabs::ENCLOSED_TABS_TYPE
    );

    const CONF_ENABLE_SECTION_EDITING = "panelEnableSectionEditing";

    /**
     * @var int a counter to give an id to the accordion panel
     */
    private $accordionCounter = 0;
    private $tabCounter = 0;

    private $sectionCounter = 0;

    static function getSelectedValue(&$attributes)
    {

        if (isset($attributes[syntax_plugin_combo_panel::SELECTED])) {
            /**
             * Value may be false/true
             */
            $value = $attributes[syntax_plugin_combo_panel::SELECTED];
            unset($attributes[syntax_plugin_combo_panel::SELECTED]);
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);

        }
        if (isset($attributes[TagAttributes::TYPE_KEY])) {
            if (strtolower($attributes[TagAttributes::TYPE_KEY]) === "selected") {
                return true;
            }
        }
        return false;

    }

    private static function getTags()
    {
        return [self::TAG, self::OLD_TAB_PANEL_TAG];
    }


    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in $PARSER_MODES in parser.php
     * @see DokuWiki_Syntax_Plugin::getType()
     */
    function getType()
    {
        return 'container';
    }

    /**
     * @return array
     * Allow which kind of plugin inside
     * ************************
     * This function has no effect because {@link SyntaxPlugin::accepts()} is used
     * ************************
     */
    public function getAllowedTypes()
    {
        return array('container', 'base', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs');
    }

    public function accepts($mode)
    {
        /**
         * header mode is disable to take over
         * and replace it with {@link syntax_plugin_combo_heading}
         */
        if ($mode == "header") {
            return false;
        }
        return syntax_plugin_combo_preformatted::disablePreformatted($mode);

    }

    /**
     * How Dokuwiki will add P element
     *
     *  * 'normal' - The plugin can be used inside paragraphs
     *  * 'block'  - Open paragraphs need to be closed before plugin output - block should not be inside paragraphs
     *  * 'stack'  - Special case. Plugin wraps other paragraphs. - Stacks can contain paragraphs
     *
     * @see DokuWiki_Syntax_Plugin::getPType()
     */
    function getPType()
    {
        return 'block';
    }

    /**
     * @see Doku_Parser_Mode::getSort()
     *
     * the mode with the lowest sort number will win out
     * the container (parent) must then have a lower number than the child
     */
    function getSort()
    {
        return 100;
    }

    /**
     * Create a pattern that will called this plugin
     *
     * @param string $mode
     * @see Doku_Parser_Mode::connectTo()
     */
    function connectTo($mode)
    {

        /**
         * Only inside tabs and accordion
         * and tabpanels for history
         */
        $show = in_array($mode,
            [
                PluginUtility::getModeFromTag(syntax_plugin_combo_tabs::TAG),
                PluginUtility::getModeFromTag(syntax_plugin_combo_accordion::TAG),
                PluginUtility::getModeFromTag(syntax_plugin_combo_tabpanels::TAG)
            ]);

        /**
         * In preview, the panel may be alone
         * due to the section edit button
         */
        if (!$show) {
            global $ACT;
            if ($ACT === "preview") {
                $show = true;
            }
        }

        /**
         * Let's connect
         */
        if ($show) {
            foreach (self::getTags() as $tag) {
                $pattern = PluginUtility::getContainerTagPattern($tag);
                $this->Lexer->addEntryPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));
            }
        }

    }

    public function postConnect()
    {

        foreach (self::getTags() as $tag) {
            $this->Lexer->addExitPattern('</' . $tag . '>', PluginUtility::getModeFromTag($this->getPluginComponent()));
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
     * @throws Exception
     * @see DokuWiki_Syntax_Plugin::handle()
     *
     */
    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_ENTER:

                // tagname to check if this is the old tag name one
                $tagName = PluginUtility::getTag($match);

                // Context
                $tagAttributes = PluginUtility::getTagAttributes($match);
                $tag = new Tag(self::TAG, $tagAttributes, $state, $handler);
                $parent = $tag->getParent();
                if ($parent != null) {
                    $context = $parent->getName();
                } else {
                    /**
                     * The panel may be alone in preview
                     * due to the section edit button
                     */
                    global $ACT;
                    if ($ACT == "preview") {
                        $context = self::CONTEXT_PREVIEW_ALONE;
                    } else {
                        $context = $tagName;
                    }
                }


                if (!isset($tagAttributes["id"])) {
                    switch ($context) {
                        case syntax_plugin_combo_accordion::TAG:
                            $this->accordionCounter++;
                            $id = $context . $this->accordionCounter;
                            $tagAttributes["id"] = $id;
                            break;
                        case syntax_plugin_combo_tabs::TAG:
                            $this->tabCounter++;
                            $id = $context . $this->tabCounter;
                            $tagAttributes["id"] = $id;
                            break;
                        case self::CONTEXT_PREVIEW_ALONE:
                            $tagAttributes["id"] = "alone";
                            break;
                        default:
                            LogUtility::msg("An id should be given for the context ($context)", LogUtility::LVL_MSG_ERROR, self::TAG);

                    }
                } else {

                    $id = $tagAttributes["id"];
                }

                /**
                 * Old deprecated syntax
                 */
                if ($tagName == self::OLD_TAB_PANEL_TAG) {

                    $context = self::OLD_TAB_PANEL_TAG;

                    $siblingTag = $parent->getPreviousSibling();
                    if ($siblingTag != null) {
                        if ($siblingTag->getName() === syntax_plugin_combo_tabs::TAG) {
                            $descendants = $siblingTag->getDescendants();
                            $tagAttributes[self::SELECTED] = false;
                            foreach ($descendants as $descendant) {
                                $descendantName = $descendant->getName();
                                $descendantPanel = $descendant->getAttribute("panel");
                                $descendantSelected = $descendant->getAttribute(self::SELECTED);
                                if (
                                    $descendantName == syntax_plugin_combo_tab::TAG
                                    && $descendantPanel === $id
                                    && $descendantSelected === "true") {
                                    $tagAttributes[self::SELECTED] = true;
                                    break;
                                }
                            }
                        } else {
                            LogUtility::msg("The direct element above a " . self::OLD_TAB_PANEL_TAG . " should be a `tabs` and not a `" . $siblingTag->getName() . "`", LogUtility::LVL_MSG_ERROR, "tabs");
                        }
                    }
                }


                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $tagAttributes,
                    PluginUtility::CONTEXT => $context,
                    PluginUtility::POSITION => $pos
                );

            case DOKU_LEXER_UNMATCHED:

                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);


            case DOKU_LEXER_EXIT :

                $tag = new Tag(self::TAG, array(), $state, $handler);
                $openingTag = $tag->getOpeningTag();

                /**
                 * Label Mandatory check
                 * (Only the presence of at minimum 1 and not the presence in each panel)
                 */
                if ($match != "</" . self::OLD_TAB_PANEL_TAG . ">") {
                    $labelTag = $openingTag->getDescendant(syntax_plugin_combo_label::TAG);
                    if (empty($labelTag)) {
                        LogUtility::msg("No label was found in the panel (number " . $this->tabCounter . "). They are mandatory to create tabs or accordion", LogUtility::LVL_MSG_ERROR, self::TAG);
                    }
                }

                /**
                 * Section
                 * +1 to go at the line
                 */
                $endPosition = $pos + strlen($match) + 1;

                return
                    array(
                        PluginUtility::STATE => $state,
                        PluginUtility::CONTEXT => $openingTag->getContext(),
                        PluginUtility::POSITION => $endPosition
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

                case DOKU_LEXER_ENTER :

                    /**
                     * Section (Edit button)
                     */
                    if (PluginUtility::getConfValue(self::CONF_ENABLE_SECTION_EDITING, 1)) {
                        $position = $data[PluginUtility::POSITION];
                        $this->sectionCounter++;
                        $name = "section" . self::TAG . $this->sectionCounter;
                        PluginUtility::startSection($renderer, $position, $name);
                    }

                    $context = $data[PluginUtility::CONTEXT];
                    switch ($context) {
                        case syntax_plugin_combo_accordion::TAG:
                            // A panel in a accordion
                            $renderer->doc .= "<div class=\"card\">";
                            break;
                        case self::OLD_TAB_PANEL_TAG: // Old deprecated syntax
                        case syntax_plugin_combo_tabs::TAG: // new syntax

                            $attributes = $data[PluginUtility::ATTRIBUTES];

                            PluginUtility::addClass2Attributes("tab-pane fade", $attributes);

                            $selected = self::getSelectedValue($attributes);
                            if ($selected) {
                                PluginUtility::addClass2Attributes("show active", $attributes);
                            }
                            unset($attributes[self::SELECTED]);

                            $attributes["role"] = "tabpanel";
                            $attributes["aria-labelledby"] = $attributes["id"] . "-tab";

                            $renderer->doc .= '<div ' . PluginUtility::array2HTMLAttributesAsString($attributes) . '>';
                            break;
                        case self::CONTEXT_PREVIEW_ALONE:
                            $aloneAttributes = syntax_plugin_combo_panel::CONTEXT_PREVIEW_ALONE_ATTRIBUTES;
                            $renderer->doc .= syntax_plugin_combo_tabs::openTabPanelsElement($aloneAttributes);
                            break;
                        default:
                            LogUtility::log2FrontEnd("The context ($context) is unknown in enter rendering", LogUtility::LVL_MSG_ERROR, self::TAG);
                            break;
                    }

                    break;
                case DOKU_LEXER_EXIT :
                    $context = $data[PluginUtility::CONTEXT];
                    switch ($context) {
                        case syntax_plugin_combo_accordion::TAG:
                            $renderer->doc .= '</div>' . DOKU_LF . "</div>" . DOKU_LF;
                            break;
                        case self::CONTEXT_PREVIEW_ALONE:
                            $aloneVariable = syntax_plugin_combo_panel::CONTEXT_PREVIEW_ALONE_ATTRIBUTES;
                            $renderer->doc .= syntax_plugin_combo_tabs::closeTabPanelsElement($aloneVariable);
                            break;

                    }

                    /**
                     * End section
                     */
                    if (PluginUtility::getConfValue(self::CONF_ENABLE_SECTION_EDITING, 1)) {
                        $renderer->finishSectionEdit($data[PluginUtility::POSITION]);
                    }

                    /**
                     * End panel
                     */
                    $renderer->doc .= "</div>" . DOKU_LF;
                    break;
                case DOKU_LEXER_UNMATCHED:
                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;
            }
            return true;
        }
        return false;
    }


}
