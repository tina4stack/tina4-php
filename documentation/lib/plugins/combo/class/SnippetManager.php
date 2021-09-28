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

namespace ComboStrap;

require_once(__DIR__ . '/Snippet.php');

/**
 * @package ComboStrap
 * A component to manage the extra HTML that
 * comes from components and that should come in the head HTML node
 *
 * The snippet manager handles two scope of snippet
 * All function with the suffix
 *   * `ForBar` are snippets for a bar (ie page, sidebar, ...) - cached
 *   * `ForRequests` are snippets added for the HTTP request - not cached. Example of request component: message, anchor
 *
 */
class SnippetManager
{

    const COMBO_CLASS_SUFFIX = "combo";

    /**
     * If a snippet is critical, it should not be deferred
     *
     * By default:
     *   * all css are critical (except animation or background stylesheet)
     *   * all javascript are not critical
     *
     * This attribute is passed in the dokuwiki array
     * The value is stored in the {@link Snippet::getCritical()}
     */
    const CRITICAL_ATTRIBUTE = "critical";


    /**
     *
     * The scope is used for snippet that are not added
     * by the syntax plugin but by the actions plugin
     * It's also used in the cache because not all bars
     * may render at the same time due to the other been cached.
     *
     * There is two scope:
     *   * {@link SnippetManager::$snippetsByBarScope}
     *   * or {@link SnippetManager::$snippetsByRequestScope}
     */

    /**
     * @var array all snippets scope to the bar level
     */
    private $snippetsByBarScope = array();

    /**
     * @var array heads that are unique on a request scope
     */
    private $snippetsByRequestScope = array();

    /**
     * Just an utility variable to get the bar processed that needs snippet.
     * @var array the processed bar
     */
    private $barsProcessed = array();

    public static function init()
    {
        global $componentScript;
        $componentScript = new SnippetManager();
    }


    /**
     * @param $tag
     * @return string
     * @deprecated create a {@link Snippet} instead and use the {@link Snippet::getClass()} function instead
     */
    public static function getClassFromSnippetId($tag)
    {
        $snippet = Snippet::createUnknownSnippet($tag);
        return $snippet->getClass();
    }


    /**
     * @param $snippetId
     * @param string|null $css - the css
     *   if null, the file $snippetId.css is searched in the `style` directory
     * @return Snippet
     * @deprecated use {@link SnippetManager::attachCssSnippetForBar()} instead
     */
    public function &upsertCssSnippetForBar($snippetId, $css = null)
    {
        $snippet = &$this->attachCssSnippetForBar($snippetId);
        if ($css != null) {
            $snippet->setContent($css);
        }
        return $snippet;

    }

    /**
     * @param $snippetId
     * @param $script - javascript code if null, it will search in the js directory
     * @return Snippet
     * @deprecated use {@link SnippetManager::attachJavascriptSnippetForBar()} instead
     */
    public function &upsertJavascriptForBar($snippetId, $script = null)
    {
        $snippet = &$this->attachJavascriptSnippetForBar($snippetId);
        if ($script != null) {
            $snippet->setContent($script);
        }
        return $snippet;
    }

    /**
     * @param $snippetId
     * @param array $tags - an array of tags without content where the key is the node type and the value a array of attributes array
     * @return Snippet
     */
    public function &upsertTagsForBar($snippetId, $tags)
    {
        $snippet = &$this->attachTagsForBar($snippetId);
        $snippet->setTags($tags);
        return $snippet;
    }


    /**
     * @return SnippetManager - the global reference
     * that is set for every run at the end of this fille
     */
    public static function get()
    {
        global $componentScript;
        if (empty($componentScript)) {
            SnippetManager::init();
        }
        return $componentScript;
    }


    /**
     * @return array of node type and an array of array of html attributes
     */
    public function getSnippets()
    {
        /**
         *
         * Delete the bar, page scope
         */
        $distinctSnippetIdByType = array();
        $allSnippets = array(
            "bar" => $this->snippetsByBarScope,
            "page" => $this->snippetsByRequestScope
        );
        foreach ($allSnippets as $snippetsScoped) {
            foreach ($snippetsScoped as $barOrPageId => $snippetTypes) {
                foreach ($snippetTypes as $snippetType => $snippetId) {
                    $snippetIdsInArray = &$distinctSnippetIdByType[$snippetType];
                    if (isset($snippetIdsInArray)) {
                        $snippetIdsInArray = array_merge($snippetIdsInArray, $snippetId);
                    } else {
                        $snippetIdsInArray = $snippetId;
                    }
                }
            }
        }

        /**
         * Transform in dokuwiki format
         * We collect the separately head that have content
         * from the head that refers to external resources
         * because the content will depends on the resources
         * and should then come in the last position
         */
        $dokuWikiHeadsFormatContent = array();
        $dokuWikiHeadsSrc = array();
        foreach ($distinctSnippetIdByType as $snippetType => $snippetBySnippetId) {
            switch ($snippetType) {
                case Snippet::TYPE_JS:
                    foreach ($snippetBySnippetId as $snippetId => $snippet) {
                        /**
                         * Bug (Quick fix)
                         */
                        if (is_string($snippet)) {
                            LogUtility::msg("The snippet ($snippetId) is a string ($snippet) and not a snippet object", LogUtility::LVL_MSG_ERROR);
                            $content = $snippet;
                        } else {
                            $content = $snippet->getContent();
                        }
                        /** @var Snippet $snippet */
                        $dokuWikiHeadsFormatContent["script"][] = array(
                            "class" => $snippet->getClass(),
                            "_data" => $content
                        );
                    }
                    break;
                case Snippet::TYPE_CSS:
                    /**
                     * CSS inline in script tag
                     * They are all critical
                     */
                    foreach ($snippetBySnippetId as $snippetId => $snippet) {
                        /**
                         * Bug (Quick fix)
                         */
                        if (is_string($snippet)) {
                            LogUtility::msg("The snippet ($snippetId) is a string ($snippet) and not a snippet object", LogUtility::LVL_MSG_ERROR);
                            $content = $snippet;
                        } else {
                            /**
                             * @var Snippet $snippet
                             */
                            $content = $snippet->getContent();
                        }
                        $snippetArray = array(
                            "class" => $snippet->getClass(),
                            "_data" => $content
                        );
                        /** @var Snippet $snippet */
                        $dokuWikiHeadsFormatContent["style"][] = $snippetArray;
                    }
                    break;
                case Snippet::TAG_TYPE:
                    foreach ($snippetBySnippetId as $snippetId => $tagsSnippet) {
                        /** @var Snippet $tagsSnippet */
                        foreach ($tagsSnippet->getTags() as $snippetType => $heads) {
                            $classFromSnippetId = self::getClassFromSnippetId($snippetId);
                            foreach ($heads as $head) {
                                if (isset($head["class"])) {
                                    $head["class"] = $head["class"] . " " . $classFromSnippetId;
                                } else {
                                    $head["class"] = $classFromSnippetId;
                                }
                                /**
                                 * Critical is only treated by strap
                                 */
                                if (Site::isStrapTemplate()) {
                                    $head[self::CRITICAL_ATTRIBUTE] = $tagsSnippet->getCritical();
                                }
                                $dokuWikiHeadsSrc[$snippetType][] = $head;
                            }
                        }
                    }
                    break;
            }
        }

        /**
         * Merge the content head node at the last position of the head ref node
         */
        foreach ($dokuWikiHeadsFormatContent as $headsNodeType => $headsData) {
            foreach ($headsData as $heads) {
                $dokuWikiHeadsSrc[$headsNodeType][] = $heads;
            }
        }
        return $dokuWikiHeadsSrc;
    }

    /**
     * Empty the snippets
     * This is used to render the snippet only once
     * The snippets renders first in the head
     * and otherwise at the end of the document
     * if the user are using another template or are in edit mode
     */
    public function close()
    {
        $this->snippetsByBarScope = array();
        $this->snippetsByRequestScope = array();
        $this->barsProcessed = array();
    }


    /**
     * @param $snippetId
     * @param array $tags - upsert a tag each time that this function is called
     */
    public function upsertHeadTagForRequest($snippetId, array $tags)
    {
        $id = PluginUtility::getPageId();
        $snippet = &$this->snippetsByRequestScope[$id][Snippet::TAG_TYPE][$snippetId];
        if (!isset($snippet)) {
            $snippet = new Snippet($snippetId, Snippet::TAG_TYPE);
        }
        $snippet->setTags($tags);
    }

    /**
     * Keep track of the parsed bar (ie page in page)
     * @param $pageId
     * @param $cached
     */
    public function addBar($pageId, $cached)
    {
        $this->barsProcessed[$pageId] = $cached;
    }

    public function getBarsOfPage()
    {
        return $this->barsProcessed;
    }

    /**
     * A function to be able to add snippets from the snippets cache
     * when a bar was served from the cache
     * @param $bar
     * @param $snippets
     */
    public function addSnippetsFromCacheForBar($bar, $snippets)
    {

        if (!isset($this->snippetsByBarScope[$bar])) {
            $this->snippetsByBarScope[$bar] = $snippets;
        } else {
            if (PluginUtility::isDebug()) {
                // When we edit a sidebar
                // The sidebar and the page competes
                $barPage = new Page($bar);
                if (!$barPage->isSlot()) {
                    /**
                     * For what ever reason, this happens
                     * but it works
                     * We still don't know yet why
                     */
                    $data = var_export($this->snippetsByBarScope[$bar], true);
                    LogUtility::msg("Internal error: Snippets for the bar ($bar) have been added while the bar was cached. The snippets added are ($data). This snippet should be added at the request level", LogUtility::LVL_MSG_ERROR);
                }
            }
        }
    }

    public function getSnippetsForBar($bar)
    {
        if (isset($this->snippetsByBarScope[$bar])) {
            return $this->snippetsByBarScope[$bar];
        } else {
            return null;
        }

    }

    /**
     * Add a javascript snippet at a request level
     * (Meaning that it should never be cached)
     * @param $snippetId
     * @param $script
     * @return Snippet
     */
    public function &upsertJavascriptSnippetForRequest($snippetId, $script = null)
    {
        $snippet = &$this->attachJavascriptSnippetForRequest($snippetId);
        if ($script != null) {
            $snippet->setContent($script);
        }
        return $snippet;

    }

    /**
     * @param $snippetId
     * @param null $script
     * @return Snippet
     */
    public function &upsertCssSnippetForRequest($snippetId, $script = null)
    {
        $snippet = &$this->attachCssSnippetForRequest($snippetId, $script);
        return $snippet;
    }

    /**
     * @param $snippetId
     * @param string $script - the css snippet to add, otherwise it takes the file
     * @return Snippet a snippet scoped at the bar level
     */
    public function &attachCssSnippetForBar($snippetId, $script = null)
    {
        $snippet = $this->attachSnippetFromBar($snippetId, Snippet::TYPE_CSS);
        if ($script != null) {
            $snippet->setContent($script);
        }
        return $snippet;
    }

    /**
     * @param $snippetId
     * @param string $script -  the css if any, otherwise the css file will be taken
     * @return Snippet a snippet scoped at the request scope
     */
    public function &attachCssSnippetForRequest($snippetId, $script = null)
    {
        $snippet = $this->attachSnippetFromRequest($snippetId, Snippet::TYPE_CSS);
        if ($script != null) {
            $snippet->setContent($script);
        }
        return $snippet;
    }

    /**
     * @param $snippetId
     * @param null $script
     * @return Snippet a snippet scoped at the bar level
     */
    public function &attachJavascriptSnippetForBar($snippetId, $script = null)
    {
        $snippet =  $this->attachSnippetFromBar($snippetId, Snippet::TYPE_JS);
        if ($script != null) {
            $snippet->setContent($script);
        }
        return $snippet;
    }

    /**
     * @param $snippetId
     * @return Snippet a snippet scoped at the request level
     */
    public function &attachJavascriptSnippetForRequest($snippetId)
    {
        return $this->attachSnippetFromRequest($snippetId, Snippet::TYPE_JS);
    }

    private function &attachSnippetFromBar($snippetId, $type)
    {
        global $ID;
        $bar = $ID;
        $snippetFromArray = &$this->snippetsByBarScope[$bar][$type][$snippetId];
        if (!isset($snippetFromArray)) {
            $snippet = new Snippet($snippetId, $type);
            $snippetFromArray = $snippet;
        }
        return $snippetFromArray;
    }

    private function &attachSnippetFromRequest($snippetId, $type)
    {
        global $ID;
        $bar = $ID;
        $snippetFromArray = &$this->snippetsByRequestScope[$bar][$type][$snippetId];
        if (!isset($snippetFromArray)) {
            $snippet = new Snippet($snippetId, $type);
            $snippetFromArray = $snippet;
        }
        return $snippetFromArray;
    }

    public function &attachTagsForBar($snippetId)
    {
        global $ID;
        $bar = $ID;
        $heads = &$this->snippetsByBarScope[$bar][Snippet::TAG_TYPE][$snippetId];
        if (!isset($heads)) {
            $heads = new Snippet($snippetId, Snippet::TAG_TYPE);
        }
        return $heads;
    }

    public function getCssSnippetContent($string)
    {

    }


}


