<?php


use ComboStrap\Background;
use ComboStrap\Call;
use ComboStrap\CallStack;
use ComboStrap\DokuPath;
use ComboStrap\FsWikiUtility;
use ComboStrap\LogUtility;
use ComboStrap\Page;
use ComboStrap\PluginUtility;
use ComboStrap\TagAttributes;
use ComboStrap\TemplateUtility;

require_once(__DIR__ . '/../ComboStrap/PluginUtility.php');


/**
 * Class syntax_plugin_combo_pageexplorer
 * Implementation of an explorer for pages
 *
 *
 *
 * https://getbootstrap.com/docs/4.0/components/scrollspy/#example-with-list-group
 * https://getbootstrap.com/docs/4.0/components/scrollspy/#example-with-nested-nav
 *
 *
 *
 */
class syntax_plugin_combo_pageexplorer extends DokuWiki_Syntax_Plugin
{

    /**
     * Tag in Dokuwiki cannot have a `-`
     * This is the last part of the class
     */
    const TAG = "pageexplorer";

    /**
     * Page canonical and tag pattern
     */
    const CANONICAL = "page-explorer";
    const COMBO_TAG_PATTERNS = ["ntoc", self::CANONICAL];

    /**
     * Namespace attribute
     * that contains scope information
     * (ie
     *   * a namespace path
     *   * or current, for the namespace of the current requested page
     */
    const ATTR_NAMESPACE = "ns";


    /**
     * Attributes on the home node
     */
    const LIST_TYPE = "list";
    const TYPE_TREE = "tree";

    /**
     * A counter/index that keeps
     * the order of the namespace tree node
     * to create a unique id
     * in order to be able to collapse
     * the good HTML node
     * @var string $namespaceCounter
     */
    private $namespaceCounter = 0;

    /**
     * @param $namespacePath
     * @return string the last part with a uppercase letter and where underscore became a space
     */
    private static function toNamespaceName($namespacePath)
    {
        $sepPosition = strrpos($namespacePath, DokuPath::PATH_SEPARATOR);
        if ($sepPosition !== false) {
            $namespaceName = ucfirst(trim(str_replace("_", " ", substr($namespacePath, $sepPosition + 1))));
        } else {
            $namespaceName = $namespacePath;
        }
        return $namespaceName;
    }


    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in $PARSER_MODES in parser.php
     * @see https://www.dokuwiki.org/devel:syntax_plugins#syntax_types
     * @see DokuWiki_Syntax_Plugin::getType()
     */
    function getType()
    {
        return 'container';
    }

    /**
     * How Dokuwiki will add P element
     *
     *  * 'normal' - The plugin can be used inside paragraphs (inline or inside)
     *  * 'block'  - Open paragraphs need to be closed before plugin output (box) - block should not be inside paragraphs
     *  * 'stack'  - Special case. Plugin wraps other paragraphs. - Stacks can contain paragraphs
     *
     * @see DokuWiki_Syntax_Plugin::getPType()
     * @see https://www.dokuwiki.org/devel:syntax_plugins#ptype
     */
    function getPType()
    {
        return 'block';
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
        return array('container', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs');
    }

    function getSort()
    {
        return 201;
    }

    public function accepts($mode)
    {
        return syntax_plugin_combo_preformatted::disablePreformatted($mode);
    }


    function connectTo($mode)
    {

        foreach (self::COMBO_TAG_PATTERNS as $tag) {
            $pattern = PluginUtility::getContainerTagPattern($tag);
            $this->Lexer->addEntryPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));
        }

    }


    public function postConnect()
    {
        foreach (self::COMBO_TAG_PATTERNS as $tag) {
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
     * @param int $pos - byte position in the original source file
     * @param Doku_Handler $handler
     * @return array|bool
     * @throws Exception
     * @see DokuWiki_Syntax_Plugin::handle()
     *
     */
    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_ENTER :

                $default = [
                    TagAttributes::TYPE_KEY => self::LIST_TYPE
                ];
                $tagAttributes = TagAttributes::createFromTagMatch($match, $default);

                $type = $tagAttributes->getType();
                $renderedPage = Page::createPageFromCurrentId();

                /**
                 * nameSpacePath determination
                 */
                if (!$tagAttributes->hasComponentAttribute(self::ATTR_NAMESPACE)) {
                    switch ($type) {
                        case self::LIST_TYPE:
                            $requestedPage = Page::createRequestedPageFromEnvironment();
                            $namespacePath = $requestedPage->getNamespacePath();
                            $scope = Page::SCOPE_CURRENT_VALUE;
                            break;
                        case self::TYPE_TREE:
                            $namespacePath = $renderedPage->getNamespacePath();
                            $scope = $namespacePath;
                            break;
                        default:
                            // Should never happens but yeah
                            LogUtility::msg("The type of the page explorer ($type) is unknown");
                            $namespacePath = $renderedPage->getNamespacePath();
                            $scope = $namespacePath;
                            break;
                    }
                } else {
                    $namespacePath = $tagAttributes->getValueAndRemove(self::ATTR_NAMESPACE);
                    $scope = $namespacePath;
                }

                /**
                 * Set the namespace location of the cache for this run
                 * if this is a sidebar
                 *
                 * Side slots cache management
                 * https://combostrap.com/sideslots
                 *
                 */
                if ($renderedPage->isStrapSideSlot()) {
                    p_set_metadata($renderedPage->getId(), [Page::SCOPE_KEY => $scope]);
                }

                /**
                 * Set the wiki-id of the namespace
                 * (Needed by javascript)
                 */
                $namespaceId = DokuPath::absolutePathToId($namespacePath);
                if ($namespaceId == "") {
                    // root namespace id is the empty string
                    $tagAttributes->addEmptyComponentAttributeValue(TagAttributes::WIKI_ID);
                } else {
                    $tagAttributes->addComponentAttributeValue(TagAttributes::WIKI_ID, $namespaceId);
                }
                $callStackArray = $tagAttributes->toCallStackArray();
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $callStackArray
                );

            case DOKU_LEXER_UNMATCHED :

                // We should not ever come here but a user does not not known that
                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);

            case DOKU_LEXER_MATCHED :

                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => PluginUtility::getTagAttributes($match),
                    PluginUtility::PAYLOAD => PluginUtility::getTagContent($match),
                    PluginUtility::TAG => PluginUtility::getTag($match)
                );

            case DOKU_LEXER_EXIT :

                $callStack = CallStack::createFromHandler($handler);

                /**
                 * Capture the instructions for
                 * {@link syntax_plugin_combo_pageexplorerpage}
                 * {@link syntax_plugin_combo_pageexplorernamespace}
                 * {@link syntax_plugin_combo_pageexplorernamehome}
                 */
                $openingTag = $callStack->moveToPreviousCorrespondingOpeningCall();
                /**
                 * @var Call[] $templateNamespaceInstructions
                 * @var array $namespaceAttributes
                 */
                $templateNamespaceInstructions = null;
                $namespaceAttributes = null;
                /**
                 * @var Call[] $templatePageInstructions
                 * @var array $pageAttributes
                 */
                $templatePageInstructions = null;
                $pageAttributes = null;
                /**
                 * @var Call[] $templateHomeInstructions
                 * @var array $homeAttributes
                 */
                $templateHomeInstructions = null;
                $homeAttributes = null;
                /**
                 * The instructions for the parent item in a page explorer list
                 * if any
                 * @var Call[] $parentInstructions
                 * @var array $parentAttributes
                 */
                $parentInstructions = null;
                $parentAttributes = null;
                /**
                 * @var Call[] $actualInstructionsStack
                 */
                $actualInstructionsStack = [];
                while ($callStack->next()) {
                    $actualCall = $callStack->getActualCall();
                    $tagName = $actualCall->getTagName();
                    switch ($actualCall->getState()) {
                        case DOKU_LEXER_ENTER:
                            switch ($tagName) {
                                case syntax_plugin_combo_pageexplorerpage::TAG:
                                    $pageAttributes = $actualCall->getAttributes();
                                    continue 3;
                                case syntax_plugin_combo_pageexplorernamespace::TAG:
                                    $namespaceAttributes = $actualCall->getAttributes();
                                    continue 3;
                                case syntax_plugin_combo_pageexplorerhome::TAG:
                                    $homeAttributes = $actualCall->getAttributes();
                                    continue 3;
                                case syntax_plugin_combo_pageexplorerparent::TAG:
                                    $parentAttributes = $actualCall->getAttributes();
                                    continue 3;
                                default:
                                    $actualInstructionsStack[] = $actualCall;
                                    continue 3;
                            }
                        case DOKU_LEXER_EXIT:
                            switch ($tagName) {
                                case syntax_plugin_combo_pageexplorerpage::TAG:
                                    $templatePageInstructions = $actualInstructionsStack;
                                    $actualInstructionsStack = [];
                                    continue 3;
                                case syntax_plugin_combo_pageexplorernamespace::TAG:
                                    $templateNamespaceInstructions = $actualInstructionsStack;
                                    $actualInstructionsStack = [];
                                    continue 3;
                                case syntax_plugin_combo_pageexplorerhome::TAG:
                                    $templateHomeInstructions = $actualInstructionsStack;
                                    $actualInstructionsStack = [];
                                    continue 3;
                                case syntax_plugin_combo_pageexplorerparent::TAG:
                                    $parentInstructions = $actualInstructionsStack;
                                    $actualInstructionsStack = [];
                                    continue 3;
                                default:
                                    $actualInstructionsStack[] = $actualCall;
                                    continue 3;

                            }
                        default:
                            $actualInstructionsStack[] = $actualCall;
                            break;

                    }
                }
                /**
                 * Remove all callstack from the opening tag
                 */
                $callStack->deleteAllCallsAfter($openingTag);

                /**
                 * Get the root Namespace of the command
                 * and
                 * Class Prefix
                 */
                $openingTagAttributes = $openingTag->getAttributes();
                $tagAttributes = TagAttributes::createFromCallStackArray($openingTagAttributes, self::CANONICAL);
                $wikiId = $tagAttributes->getValue(TagAttributes::WIKI_ID);
                $nameSpacePath = DokuPath::IdToAbsolutePath($wikiId);
                $type = $tagAttributes->getType();
                $componentClassPrefix = self::CANONICAL . "-$type";

                /**
                 * Default template
                 * if null, no node page present
                 */
                if ($pageAttributes == null) {
                    // attributes are mandatory as array
                    $pageAttributes = [];
                    // default template instructions
                    if ($templatePageInstructions === null) {
                        $templatePageInstructions = [];
                        $templatePageInstructions[] = Call::createComboCall(
                            syntax_plugin_combo_link::TAG,
                            DOKU_LEXER_ENTER,
                            ["ref" => "\$path"],
                            syntax_plugin_combo_pageexplorerpage::TAG,
                            "[[\$path"
                        )->addClassName($componentClassPrefix . "-page-combo");
                        $templatePageInstructions[] = Call::createComboCall(
                            syntax_plugin_combo_pipeline::TAG,
                            DOKU_LEXER_SPECIAL,
                            [PluginUtility::PAYLOAD => ""],
                            "",
                            "<pipeline>\"\$name\" | replace(\"_\",\" \") | capitalize()</pipeline>"
                        );
                        $templatePageInstructions[] = Call::createComboCall(
                            syntax_plugin_combo_link::TAG,
                            DOKU_LEXER_EXIT,
                            ["ref" => "\$path"],
                            syntax_plugin_combo_pageexplorerpage::TAG,
                            "]]"
                        );
                    }
                }

                /**
                 * Home instruction
                 * If unset, set it with the pages
                 */
                if ($homeAttributes == null) {
                    $homeAttributes = [];
                    if ($templateHomeInstructions === null) {
                        $templateHomeInstructions = $templatePageInstructions;
                    }
                }

                /**
                 * Creating the callstack
                 */
                switch ($type) {
                    default:
                    case self::LIST_TYPE:

                        /**
                         * Shortcut
                         */
                        $contentListTag = syntax_plugin_combo_contentlist::DOKU_TAG;
                        $contentListItemTag = syntax_plugin_combo_contentlistitem::DOKU_TAG;

                        /**
                         * Css
                         */
                        PluginUtility::getSnippetManager()->attachCssSnippetForBar($componentClassPrefix);

                        /**
                         * Create the enter content list tag
                         */
                        $tagAttributes->addClassName(self::CANONICAL . "-combo");
                        $tagAttributes->addClassName($componentClassPrefix . "-combo");
                        $tagAttributes->removeAttributeIfPresent(TagAttributes::TYPE_KEY);
                        $tagAttributes->removeAttributeIfPresent(TagAttributes::WIKI_ID);
                        $callStack->appendCallAtTheEnd(
                            Call::createComboCall(
                                $contentListTag,
                                DOKU_LEXER_ENTER,
                                $tagAttributes->toCallStackArray()
                            )
                        );


                        /**
                         * Home
                         */
                        $currentHomePagePath = FsWikiUtility::getHomePagePath($nameSpacePath);
                        if ($currentHomePagePath != null && $templateHomeInstructions !== null) {

                            /**
                             * Enter tag
                             */
                            $callStack->appendCallAtTheEnd(
                                Call::createComboCall($contentListItemTag,
                                    DOKU_LEXER_ENTER,
                                    $homeAttributes
                                )->addClassName($componentClassPrefix . "-home-combo")
                            );
                            /**
                             * Content
                             */
                            $callStack->appendInstructionsFromNativeArray(TemplateUtility::renderInstructionsTemplateFromDataPage($templateHomeInstructions, $currentHomePagePath));
                            /**
                             * End home tag
                             */
                            $callStack->appendCallAtTheEnd(
                                Call::createComboCall($contentListItemTag,
                                    DOKU_LEXER_EXIT
                                )
                            );
                        }

                        /**
                         * Parent ?
                         */
                        if ($parentAttributes == null) {
                            $parentAttributes = [];
                            // default template instructions
                            if ($parentInstructions === null) {
                                $parentInstructions = [];
                                $parentInstructions[] = Call::createComboCall(
                                    syntax_plugin_combo_link::TAG,
                                    DOKU_LEXER_ENTER,
                                    ["ref" => "\$path"],
                                    syntax_plugin_combo_pageexplorerparent::TAG,
                                    "[[\$path"
                                )->addClassName($componentClassPrefix . "-parent-combo");
                                /**
                                 * To not hurt the icon
                                 * server and to get
                                 * stable test
                                 */
                                if (!PluginUtility::isTest()) {
                                    $parentIconName = "arrow-left-box";
                                    $parentInstructions[] = Call::createComboCall(
                                        syntax_plugin_combo_icon::TAG,
                                        DOKU_LEXER_SPECIAL,
                                        ["name" => $parentIconName],
                                        syntax_plugin_combo_pageexplorerparent::TAG,
                                        "<icon name=\"$parentIconName\"/>"
                                    );
                                }
                                $parentInstructions[] = Call::createComboCall(
                                    syntax_plugin_combo_link::TAG,
                                    DOKU_LEXER_UNMATCHED,
                                    [],
                                    syntax_plugin_combo_link::TAG,
                                    " ... ",
                                    " ... "
                                );
                                $parentInstructions[] = Call::createComboCall(
                                    syntax_plugin_combo_pipeline::TAG,
                                    DOKU_LEXER_SPECIAL,
                                    [PluginUtility::PAYLOAD => ""],
                                    "",
                                    "<pipeline>\"\$name\" | replace(\"_\",\" \") | capitalize()</pipeline>"
                                );
                                $parentInstructions[] = Call::createComboCall(
                                    syntax_plugin_combo_link::TAG,
                                    DOKU_LEXER_EXIT,
                                    ["ref" => "\$path"],
                                    syntax_plugin_combo_pageexplorerparent::TAG,
                                    "]]"
                                );
                            }
                        }
                        $parentPagePath = FsWikiUtility::getParentPagePath($nameSpacePath);
                        if ($parentPagePath != null && $parentInstructions !== null) {
                            /**
                             * Enter parent tag
                             */
                            $callStack->appendCallAtTheEnd(
                                Call::createComboCall($contentListItemTag,
                                    DOKU_LEXER_ENTER,
                                    $parentAttributes
                                )
                            );
                            /**
                             * Content
                             */
                            $parentInstructionsInstance = TemplateUtility::renderInstructionsTemplateFromDataPage($parentInstructions, $parentPagePath);
                            $callStack->appendInstructionsFromNativeArray($parentInstructionsInstance);
                            /**
                             * End parent tag
                             */
                            $callStack->appendCallAtTheEnd(
                                Call::createComboCall($contentListItemTag,
                                    DOKU_LEXER_EXIT,
                                    $parentAttributes
                                )
                            );
                        }

                        /**
                         * children (Namespaces/Pages)
                         */
                        if ($namespaceAttributes == null) {
                            $namespaceAttributes = [];
                            // default template instructions
                            if ($templateNamespaceInstructions === null) {
                                $templateNamespaceInstructions = [];
                                $templateNamespaceInstructions[] = Call::createComboCall(
                                    syntax_plugin_combo_link::TAG,
                                    DOKU_LEXER_ENTER,
                                    ["ref" => "\$path"],
                                    syntax_plugin_combo_pageexplorerparent::TAG,
                                    "[[\$path"
                                )->addClassName($componentClassPrefix . "-namespace-combo");
                                /**
                                 * To not hurt
                                 * the icon server in test
                                 * and to get stable test
                                 */
                                if (!PluginUtility::isTest()) {
                                    $templateNamespaceInstructions[] = Call::createComboCall(
                                        syntax_plugin_combo_icon::TAG,
                                        DOKU_LEXER_SPECIAL,
                                        ["name" => "folder"],
                                        syntax_plugin_combo_pageexplorerparent::TAG,
                                        "<icon name=\"folder\"/>"
                                    );
                                }
                                $templateNamespaceInstructions[] = Call::createComboCall(
                                    syntax_plugin_combo_link::TAG,
                                    DOKU_LEXER_UNMATCHED,
                                    [],
                                    syntax_plugin_combo_link::TAG,
                                    " ",
                                    " "
                                );
                                $templateNamespaceInstructions[] = Call::createComboCall(
                                    syntax_plugin_combo_pipeline::TAG,
                                    DOKU_LEXER_SPECIAL,
                                    [PluginUtility::PAYLOAD => ""],
                                    "",
                                    "<pipeline>\"\$name\" | replace(\"_\",\" \") | capitalize()</pipeline>"
                                );
                                $templateNamespaceInstructions[] = Call::createComboCall(
                                    syntax_plugin_combo_link::TAG,
                                    DOKU_LEXER_EXIT,
                                    ["ref" => "\$path"],
                                    syntax_plugin_combo_pageexplorerparent::TAG,
                                    "]]"
                                );
                            }
                        }
                        $pageOrNamespaces = FsWikiUtility::getChildren($nameSpacePath);
                        $pageNum = 0;
                        foreach ($pageOrNamespaces as $pageOrNamespace) {

                            $pageOrNamespacePath = DokuPath::IdToAbsolutePath($pageOrNamespace['id']);


                            if ($pageOrNamespace['type'] == "d") {

                                // Namespace
                                if (!empty($templateNamespaceInstructions)) {
                                    $subNamespacePagePath = FsWikiUtility::getHomePagePath($pageOrNamespacePath);
                                    if ($subNamespacePagePath != null) {
                                        /**
                                         * SubNamespace Enter tag
                                         */
                                        $callStack->appendCallAtTheEnd(
                                            Call::createComboCall($contentListItemTag,
                                                DOKU_LEXER_ENTER,
                                                $namespaceAttributes
                                            )
                                        );
                                        /**
                                         * SubNamespace Content
                                         */
                                        $namespaceInstructionsInstance = TemplateUtility::renderInstructionsTemplateFromDataPage($templateNamespaceInstructions, $subNamespacePagePath);
                                        $callStack->appendInstructionsFromNativeArray($namespaceInstructionsInstance);
                                        /**
                                         * SubNamespace Exit tag
                                         */
                                        $callStack->appendCallAtTheEnd(
                                            Call::createComboCall($contentListItemTag,
                                                DOKU_LEXER_EXIT,
                                                $namespaceAttributes
                                            )
                                        );
                                    }
                                }

                            } else {

                                if (!empty($templatePageInstructions)) {
                                    $pageNum++;
                                    if ($pageOrNamespacePath != $currentHomePagePath) {
                                        /**
                                         * Page Enter tag
                                         */
                                        $callStack->appendCallAtTheEnd(
                                            Call::createComboCall($contentListItemTag,
                                                DOKU_LEXER_ENTER,
                                                $pageAttributes
                                            )
                                        );
                                        /**
                                         * Page Content
                                         */
                                        $pageInstructions = TemplateUtility::renderInstructionsTemplateFromDataPage($templatePageInstructions, $pageOrNamespacePath);
                                        $callStack->appendInstructionsFromNativeArray($pageInstructions);
                                        /**
                                         * Page Exit tag
                                         */
                                        $callStack->appendCallAtTheEnd(
                                            Call::createComboCall($contentListItemTag,
                                                DOKU_LEXER_EXIT,
                                                $pageAttributes
                                            )
                                        );
                                    }
                                }
                            }

                        }

                        /**
                         * End container tag
                         */
                        $callStack->appendCallAtTheEnd(
                            Call::createComboCall($contentListTag,
                                DOKU_LEXER_EXIT
                            )
                        );


                        break;
                    case self::TYPE_TREE:

                        if ($namespaceAttributes == null) {
                            if ($templateNamespaceInstructions === null) {
                                $templateNamespaceInstructions = [];
                                $templateNamespaceInstructions[] = Call::createComboCall(
                                    syntax_plugin_combo_pipeline::TAG,
                                    DOKU_LEXER_SPECIAL,
                                    [PluginUtility::PAYLOAD => ""],
                                    "",
                                    "<pipeline>\"\$name\" | replace(\"_\",\" \") | capitalize()</pipeline>"
                                );
                            }
                        }
                        /**
                         * Printing the tree
                         *
                         * (Move to the end is not really needed, but yeah)
                         */
                        $callStack->moveToEnd();
                        self::treeProcessSubNamespace($callStack, $nameSpacePath, $templateNamespaceInstructions, $templatePageInstructions, $templateHomeInstructions);

                        break;

                }


                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $openingTag->getAttributes()
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

                    $tagAttributes = TagAttributes::createFromCallStackArray($data[PluginUtility::ATTRIBUTES], self::CANONICAL);
                    $type = $tagAttributes->getType();
                    switch ($type) {
                        case self::TYPE_TREE:
                            /**
                             * data-wiki-id, needed for the
                             * javascript that open the tree
                             * to the actual page
                             */
                            $namespaceId = $tagAttributes->getValueAndRemove(TagAttributes::WIKI_ID);
                            if (!empty($namespaceId)) { // not root
                                $tagAttributes->addHtmlAttributeValue("data-wiki-id", $namespaceId);
                            } else {
                                $tagAttributes->addEmptyHtmlAttributeValue("data-wiki-id");
                            }
                            /**
                             * No ns
                             */
                            $tagAttributes->removeAttributeIfPresent(self::ATTR_NAMESPACE);

                            $snippetId = self::CANONICAL . "-" . $type;
                            /**
                             * Open the tree until the current page
                             * and make it active
                             */
                            PluginUtility::getSnippetManager()->attachJavascriptSnippetForBar($snippetId);
                            /**
                             * Styling
                             */
                            PluginUtility::getSnippetManager()->attachCssSnippetForBar($snippetId);
                            $renderer->doc .= $tagAttributes->toHtmlEnterTag("nav") . DOKU_LF;
                            $renderer->doc .= "<ul>" . DOKU_LF;
                            break;
                        case self::LIST_TYPE:
                            /**
                             * The {@link syntax_plugin_combo_contentlist} syntax
                             * output the HTML
                             */
                            break;
                    }

                    break;
                case DOKU_LEXER_UNMATCHED :
                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;

                case DOKU_LEXER_EXIT :


                    $tagAttributes = TagAttributes::createFromCallStackArray($data[PluginUtility::ATTRIBUTES]);
                    $type = $tagAttributes->getType();
                    switch ($type) {
                        case self::TYPE_TREE:
                            $renderer->doc .= "</ul>" . DOKU_LF;
                            $renderer->doc .= "</nav>" . DOKU_LF;
                            break;
                        case self::LIST_TYPE:
                            /**
                             * The {@link syntax_plugin_combo_contentlist} syntax
                             * output the HTML
                             */
                            break;
                    }


                    break;
            }
            return true;
        }

        // unsupported $mode
        return false;
    }

    /**
     * Process the
     * @param CallStack $callStack - the callstack
     * @param string $nameSpacePath
     * @param array $namespaceTemplateInstructions
     * @param array $pageTemplateInstructions
     */
    public
    function treeProcessSubNamespace(&$callStack, $nameSpacePath, $namespaceTemplateInstructions = [], $pageTemplateInstructions = [], $homeTemplateInstructions = [])
    {


        $pageExplorerSubNamespaceTag = syntax_plugin_combo_pageexplorertreesubnamespace::TAG;
        $pageExplorerTreeButtonTag = syntax_plugin_combo_pageexplorernamespace::TAG;
        $pageExplorerTreeListTag = syntax_plugin_combo_pageexplorertreesubnamespacelist::TAG;


        /**
         * Home Page first
         */
        $homePage = null;
        $childDirectoryIds = [];
        $nonHomePages = [];

        /**
         * Scanning the directory to
         * categorize the children as home, page or namesapce
         */
        $childPagesOrNamespaces = FsWikiUtility::getChildren($nameSpacePath);
        foreach ($childPagesOrNamespaces as $pageOrNamespace) {

            $actualNamespaceId = $pageOrNamespace['id'];
            $actualPageOrNamespacePath = DokuPath::IdToAbsolutePath($actualNamespaceId);

            /**
             * Namespace
             */
            if ($pageOrNamespace['type'] == "d") {

                $childDirectoryIds[] = $actualNamespaceId;

            } else {
                /**
                 * Page
                 */
                $page = Page::createPageFromQualifiedPath($actualPageOrNamespacePath);
                if ($page->isHomePage()) {
                    $homePage = $page;
                } else {
                    $nonHomePages[] = $page;
                }

            }

        }

        /**
         * First the home page
         */
        if ($homePage != null) {
            self::treeProcessLeaf($callStack, $homePage->getAbsolutePath(), $homeTemplateInstructions);
        }

        /**
         * The the subdirectories
         */
        foreach ($childDirectoryIds as $childDirectoryId) {
            $childDirectoryPath = DokuPath::IdToAbsolutePath($childDirectoryId);
            $subHomePagePath = FsWikiUtility::getHomePagePath($childDirectoryPath);
            if ($subHomePagePath != null) {
                if ($namespaceTemplateInstructions !== null) {
                    // Translate TODO
                    $actualNamespaceInstructions = TemplateUtility::renderInstructionsTemplateFromDataPage($namespaceTemplateInstructions, $subHomePagePath);
                } else {
                    $actualNamespaceInstructions = [Call::createNativeCall("cdata", [$subHomePagePath])->toCallArray()];
                }
            } else {
                $namespaceName = self::toNamespaceName($childDirectoryPath);
                $actualNamespaceInstructions = [Call::createNativeCall("cdata", [$namespaceName])->toCallArray()];
            }

            $this->namespaceCounter++;
            $targetIdAtt = syntax_plugin_combo_pageexplorernamespace::TARGET_ID_ATT;
            $id = PluginUtility::toHtmlId("page-explorer-{$childDirectoryId}-{$this->namespaceCounter}-combo");

            /**
             * Entering: Creating in instructions form
             * the same as in markup form
             *
             * <$pageExplorerTreeTag>
             *    <$pageExplorerTreeButtonTag $targetIdAtt="$id">
             *      $buttonInstructions
             *    </$pageExplorerTreeButtonTag>
             *    <$pageExplorerTreeListTag id="$id">
             *    ...
             */
            $callStack->appendCallAtTheEnd(
                Call::createComboCall($pageExplorerSubNamespaceTag, DOKU_LEXER_ENTER)
            );
            $callStack->appendCallAtTheEnd(
                Call::createComboCall($pageExplorerTreeButtonTag, DOKU_LEXER_ENTER, [
                    $targetIdAtt => $id,
                    TagAttributes::WIKI_ID => $childDirectoryId
                ])
            );
            $callStack->appendInstructionsFromNativeArray($actualNamespaceInstructions);
            $callStack->appendCallAtTheEnd(
                Call::createComboCall($pageExplorerTreeButtonTag, DOKU_LEXER_EXIT)
            );
            $callStack->appendCallAtTheEnd(
                Call::createComboCall($pageExplorerTreeListTag, DOKU_LEXER_ENTER, [TagAttributes::ID_KEY => "$id"])
            );


            /**
             * Recursion
             */
            self::treeProcessSubNamespace($callStack, $childDirectoryPath, $namespaceTemplateInstructions, $pageTemplateInstructions, $homeTemplateInstructions);

            /**
             * Closing: Creating in instructions form
             * the same as in markup form
             *
             *   </$pageExplorerTreeListTag>
             * </$pageExplorerTreeTag>
             */
            $callStack->appendCallAtTheEnd(
                Call::createComboCall($pageExplorerTreeListTag, DOKU_LEXER_EXIT)
            );
            $callStack->appendCallAtTheEnd(
                Call::createComboCall($pageExplorerSubNamespaceTag, DOKU_LEXER_EXIT)
            );
        }

        /**
         * Then the other pages
         */
        foreach ($nonHomePages as $page) {
            self::treeProcessLeaf($callStack, $page->getAbsolutePath(), $pageTemplateInstructions);
        }


    }

    /**
     * @param CallStack $callStack
     * @param $pageOrNamespacePath
     * @param array $pageTemplateInstructions
     */
    private
    static function treeProcessLeaf(&$callStack, $pageOrNamespacePath, $pageTemplateInstructions = [])
    {
        $leafTag = syntax_plugin_combo_pageexplorerpage::TAG;
        if ($pageTemplateInstructions !== null) {
            $actualPageInstructions = TemplateUtility::renderInstructionsTemplateFromDataPage($pageTemplateInstructions, $pageOrNamespacePath);
        } else {
            $actualPageInstructions = [Call::createNativeCall("cdata", [$pageOrNamespacePath])->toCallArray()];
        }

        /**
         * In callstack instructions
         * <$leafTag>
         *   $instructions
         * </$leafTag>
         */
        $callStack->appendCallAtTheEnd(
            Call::createComboCall($leafTag, DOKU_LEXER_ENTER)
        );
        $callStack->appendInstructionsFromNativeArray($actualPageInstructions);
        $callStack->appendCallAtTheEnd(
            Call::createComboCall($leafTag, DOKU_LEXER_EXIT)
        );


    }
}

