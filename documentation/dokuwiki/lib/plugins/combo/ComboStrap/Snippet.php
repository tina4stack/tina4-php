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


class Snippet
{
    /**
     * The head in css format
     * We need to add the style node
     */
    const TYPE_CSS = "css";

    /**
     * The snippet is attached to a bar (main, sidebar, ...) or to the page (request)
     */
    const SCOPE_BAR = "bar";
    const SCOPE_PAGE = "page";
    /**
     * The head in javascript
     * We need to wrap it in a script node
     */
    const TYPE_JS = "js";
    /**
     * A tag head in array format
     * No need
     */
    const TAG_TYPE = "tag";

    private $snippetId;
    private $scope;
    private $type;

    /**
     * @var bool
     */
    private $critical = false;

    /**
     * @var string the text script / style (may be null if it's an external resources)
     */
    private $content;
    /**
     * @var array
     */
    private $headsTags;

    /**
     * Snippet constructor.
     */
    public function __construct($snippetId, $snippetType)
    {
        $this->snippetId = $snippetId;
        $this->type = $snippetType;
        if ($this->type == self::TYPE_CSS) {
            // All CSS should be loaded first
            // The CSS animation / background can set this to false
            $this->critical = true;
        }
    }

    public static function createJavascriptSnippet($snippetId)
    {
         return new Snippet($snippetId,self::TYPE_JS);
    }

    public static function createCssSnippet($snippetId)
    {
        return new Snippet($snippetId,self::TYPE_CSS);
    }

    /**
     * @deprecated You should create a snippet with a known type, this constructor was created for refactoring
     * @param $snippetId
     * @return Snippet
     */
    public static function createUnknownSnippet($snippetId)
    {
        return new Snippet($snippetId,"unknwon");
    }


    /**
     * @param $bool - if the snippet is critical, it would not be deferred or preloaded
     * @return Snippet for chaining
     * All css that are for animation or background for instance
     * should not be set as critical as they are not needed to paint
     * exactly the page
     */
    public function setCritical($bool)
    {
        $this->critical = $bool;
        return $this;
    }

    /**
     * @param $content - Set an inline content for a script or stylesheet
     * @return Snippet for chaining
     */
    public function setContent($content)
    {
        $this->content = $content;
        return $this;
    }

    /**
     * @return string
     */
    public function getContent()
    {
        if ($this->content == null) {
            switch ($this->type) {
                case self::TYPE_CSS:
                    $this->content = $this->getCssRulesFromFile($this->snippetId);
                    break;
                case self::TYPE_JS:
                    $this->content = $this->getJavascriptContentFromFile($this->snippetId);
                    break;
                default:
                    LogUtility::msg("The snippet ($this) has no content", LogUtility::LVL_MSG_ERROR, "support");
            }
        }
        return $this->content;
    }

    /**
     * @param $tagName
     * @return false|string - the css content of the css file
     */
    private function getCssRulesFromFile($tagName)
    {

        $path = Resources::getSnippetResourceDirectory() . "/style/" . strtolower($tagName) . ".css";
        if (file_exists($path)) {
            return file_get_contents($path);
        } else {
            LogUtility::msg("The css file ($path) was not found", LogUtility::LVL_MSG_WARNING, $tagName);
            return "";
        }

    }

    /**
     * @param $tagName - the tag name
     * @return false|string - the specific javascript content for the tag
     */
    private function getJavascriptContentFromFile($tagName)
    {

        $path = Resources::getSnippetResourceDirectory() . "/js/" . strtolower($tagName) . ".js";
        if (file_exists($path)) {
            return file_get_contents($path);
        } else {
            LogUtility::msg("The javascript file ($path) was not found", LogUtility::LVL_MSG_WARNING, $tagName);
            return "";
        }

    }

    public function __toString()
    {
        return $this->snippetId."-".$this->type;
    }

    /**
     * Set all tags at once.
     * @param array $tags
     * @return Snippet
     */
    public function setTags(array $tags)
    {
        $this->headsTags = $tags;
        return $this;
    }

    public function getTags()
    {
        return $this->headsTags;
    }

    public function getCritical()
    {
        return $this->critical;
    }

    public function getClass()
    {
        /**
         * The class for the snippet is just to be able to identify them
         *
         * The `snippet` prefix was added to be sure that the class
         * name will not conflict with a css class
         * Example: if you set the class to `combo-list`
         * and that you use it in a inline `style` tag with
         * the same class name, the inline `style` tag is not applied
         *
         */
        return "snippet-" . $this->snippetId . "-" . SnippetManager::COMBO_CLASS_SUFFIX;

    }

    /**
     * @return string the HTML of the tag (works for now only with CSS content)
     */
    public function getHtmlStyleTag()
    {
        $content = $this->getContent();
        $class = $this->getClass();
        return <<<EOF
<style class="$class">
$content
</style>
EOF;

    }

    public function getId()
    {
        return $this->snippetId;
    }


}
