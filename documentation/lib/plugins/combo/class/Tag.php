<?php
/**
 * Copyright (c) 2020. ComboStrap, Inc. and its affiliates. All Rights Reserved.
 *
 * This source code is licensed under the GPL license found in the
 * COPYING  file in the root directory of this source tree.
 *
 * @license  GPL 3 (https://www.gnu.org/licenses/gpl-3.0.en.html)
 * @author   ComboStrap <support@combostrap.com>
 *
 */

namespace ComboStrap;


require_once(__DIR__ . '/Call.php');

use Doku_Handler;
use dokuwiki\Extension\SyntaxPlugin;
use dokuwiki\Parsing\Handler\CallWriter;
use Exception;
use RuntimeException;


/**
 * Class Tag
 * @package ComboStrap
 * This is class that have tree like function on tag level
 * to match what's called a {@link Doku_Handler::$calls call}
 *
 * It's just a wrapper that adds tree functionality above the {@link CallStack}
 *
 * @deprecated Move to the {@link CallStack::createFromHandler()}
 */
class Tag
{

    const CANONICAL = "support";

    /**
     * Invisible Content tag (ie p (p_open/p_close))
     * They are not seen in the content
     * and are automatically generated
     */
    const INVISIBLE_CONTENT_TAG = ["p"];

    /**
     * The {@link Doku_Handler::$calls} or {@link CallWriter::$calls}
     * @var
     */
    private $calls;

    /**
     * The parsed attributes for the tag
     * @var
     */
    private $attributes;
    /**
     * The name of the tag
     * @var
     */
    private $name;
    /**
     * The lexer state
     * @var
     */
    private $state;
    /**
     * The position is the call stack
     * @var int
     */
    private $actualPosition;
    /**
     * @var Doku_Handler
     */
    private $handler;
    /**
     *
     * @var Call
     * The tag call may be null if this is the actual tag
     * in the handler process (ie not yet created in the stack)
     */
    private $tagCall;
    /**
     * The maximum key number position of the stack
     * @var int|string|null
     */
    private $maxPosition;
    /**
     * @var string
     */
    private $callStackType;


    /**
     * Token constructor
     * A token represent a call of {@link \Doku_Handler}
     * It can be seen as a the counter part of the HTML tag.
     *
     * It has a state of:
     *   * {@link DOKU_LEXER_ENTER} (open),
     *   * {@link DOKU_LEXER_UNMATCHED} (unmatched content),
     *   * {@link DOKU_LEXER_EXIT} (closed)
     *
     * @param $name
     * @param $attributes
     * @param $state
     * @param Doku_Handler $handler - A reference to the dokuwiki handler
     * @param null $position - The key (position) in the call stack of null if it's the HEAD tag (The tag that is created from the data of the {@link SyntaxPlugin::render()}
     */
    public function __construct($name, $attributes, $state, &$handler, $position = null, $callStackType = null)
    {
        $this->name = $name;
        $this->state = $state;

        /**
         * Callstack
         */
        $writerCalls = &$handler->getCallWriter()->calls;
        if ($position == null) {
            /**
             * A temporary Call stack is created in the writer
             * for list, table, blockquote
             */
            if (!empty($writerCalls)) {
                $this->calls = &$writerCalls;
                $this->callStackType = CallStack::CALLSTACK_WRITER;
            } else {
                $this->calls = &$handler->calls;
                $this->callStackType = CallStack::CALLSTACK_MAIN;
            }
        } else {
            if ($callStackType == null) {
                LogUtility::msg("When the position is set, the callstack type should be given", LogUtility::LVL_MSG_ERROR);
            }
            $this->callStackType = $callStackType;
            if ($callStackType == CallStack::CALLSTACK_MAIN) {
                $this->calls = &$handler->calls;
            } else {
                $this->calls = &$writerCalls;
            }
        }

        $this->handler = &$handler;
        $this->maxPosition = ArrayUtility::array_key_last($this->calls);
        if ($position !== null) {
            $this->actualPosition = $position;
            /**
             * A shortcut access variable to the call of the tag
             */
            if (isset($this->calls[$this->actualPosition])) {
                $this->tagCall = $this->createCallObjectFromIndex($this->actualPosition);
                $this->attributes = $this->tagCall->getAttributes();
            }

        } else {
            // The tag is not yet in the stack

            // Get the last position
            // We use get_last and not sizeof
            // because some plugin may delete element of the stack
            $this->actualPosition = ArrayUtility::array_key_last($this->calls) + 1;
            if ($attributes == null) {
                $this->attributes = array();
            } else {
                $this->attributes = $attributes;
            }
        }


    }

    public static function createDocumentStartFromHandler(Doku_Handler &$handler)
    {
        return new Tag("document_start", [], DOKU_LEXER_ENTER, $handler, 0);
    }

    private function createCallObjectFromIndex($i)
    {
        if (isset($this->calls[$i])) {
            return new Call($this->calls[$i]);
        } else {
            LogUtility::msg("There is no call at the index ($i)", LogUtility::LVL_MSG_ERROR);
            return null;
        }

    }


    /**
     * The lexer state
     *   DOKU_LEXER_ENTER = 1
     *   DOKU_LEXER_MATCHED = 2
     *   DOKU_LEXER_UNMATCHED = 3
     *   DOKU_LEXER_EXIT = 4
     *   DOKU_LEXER_SPECIAL = 5
     * @return mixed
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * From a call position to a tag
     * @param $position - the position in the call stack (ie in the array)
     * @return Tag
     */
    public function createFromCall($position)
    {

        if (!isset($this->calls[$position])) {
            LogUtility::msg("The index ($position) does not exist in the call stack, cannot create a call", LogUtility::LVL_MSG_ERROR);
            return null;
        }

        $callArray = &$this->calls[$position];
        $call = new Call($callArray);

        $attributes = $call->getAttributes();
        $name = $call->getTagName();
        $state = $call->getState();

        /**
         * Getting attributes
         * If we don't have already the attributes
         * in the returned array of the handler,
         * (ie the full HTML was given for instance)
         * we parse the match again
         */
        if ($attributes == null
            && $call->getState() == DOKU_LEXER_ENTER
            && $name != 'preformatted' // preformatted does not have any attributes
        ) {
            $match = $call->getCapturedContent();
            /**
             * If this is not a combo element, we got no match
             */
            if ($match != null) {
                $attributes = PluginUtility::getTagAttributes($match);
            }
        }

        return new Tag($name, $attributes, $state, $this->handler, $position, $this->callStackType);
    }

    public function isChildOf($tag)
    {
        $componentNode = $this->getParent();
        return $componentNode !== false ? $componentNode->getName() === $tag : false;
    }

    /**
     * To determine if there is no content
     * between the child and the parent
     * @return bool
     */
    public function hasSiblings()
    {
        if ($this->getPreviousSibling() === null) {
            return false;
        } else {
            return true;
        }

    }

    /**
     * Return the parent node or false if root
     *
     * The node should not be in an exit node
     * (If this is the case, use the function {@link Tag::getOpeningTag()} first
     *
     * @return bool|Tag
     */
    public function getParent()
    {
        /**
         * Case when we start from the exit state element
         * We go first to the opening tag
         * because the algorithm is level based.
         */
        if ($this->state == DOKU_LEXER_EXIT) {

            return $this->getOpeningTag()->getParent();

        } else {

            /**
             * Start to the actual call minus one
             */
            $callStackPosition = $this->actualPosition - 1;
            $treeLevel = 0;

            /**
             * We are in a parent when the tree level is negative
             */
            while ($callStackPosition >= 0) {

                $callStackPosition = $this->getPreviousPositionNonEmpty($callStackPosition);
                if ($callStackPosition < 0) {
                    break;
                }

                /**
                 * Get the previous call
                 */
                $previousCallArray = $this->calls[$callStackPosition];
                $previousCall = new Call($previousCallArray);
                $parentCallState = $previousCall->getState();

                /**
                 * Add
                 * would become a parent on its enter state
                 */
                switch ($parentCallState) {
                    case DOKU_LEXER_ENTER:
                        $treeLevel = $treeLevel - 1;
                        break;
                    case DOKU_LEXER_EXIT:
                        /**
                         * When the tag has a sibling with an exit tag
                         */
                        $treeLevel = $treeLevel + 1;
                        break;
                }

                /**
                 * The breaking statement
                 */
                if ($treeLevel >= 0) {
                    $callStackPosition = $callStackPosition - 1;
                    unset($previousCallArray);
                } else {
                    break;
                }


            }
            if (isset($previousCallArray)) {
                return $this->createFromCall($callStackPosition);
            } else {
                return false;
            }
        }
    }

    /**
     * Return an attribute of the node or null if it does not exist
     * @param string $name
     * @return string the attribute value
     */
    public function getAttribute($name)
    {
        if (isset($this->attributes)) {
            return $this->attributes[$name];
        } else {
            return null;
        }

    }

    /**
     * Return all attributes
     * @return string[] the attributes
     */
    public function getAttributes()
    {

        return $this->attributes;

    }

    /**
     * @return mixed - the name of the element (ie the opening tag)
     */
    public function getName()
    {
        if ($this->tagCall != null) {
            return $this->tagCall->getTagName();
        } else {
            return $this->name;
        }
    }


    /**
     * @return string - the type attribute of the opening tag
     */
    public function getType()
    {

        if ($this->getState() == DOKU_LEXER_UNMATCHED) {
            LogUtility::msg("The unmatched tag (" . $this->name . ") does not have any attributes. Get its parent if you want the type", LogUtility::LVL_MSG_ERROR);
            return null;
        } else {
            return $this->getAttribute("type");
        }
    }

    /**
     * @param $tag
     * @return int
     */
    public function isDescendantOf($tag)
    {

        for ($i = sizeof($this->calls) - 1; $i >= 0; $i--) {
            if (isset($this->calls[$i])) {
                $call = $this->createCallObjectFromIndex($i);
                if ($call->getTagName() == "$tag") {
                    return true;
                }
            }
        }
        return false;

    }

    /**
     *
     * @return null|Tag - the sibling tag (in ascendant order) or null
     */
    public function getPreviousSibling()
    {

        if ($this->actualPosition == 1) {
            return null;
        }

        $counter = $this->actualPosition - 1;
        $treeLevel = 0;
        while ($counter > 0) {

            $callArray = $this->calls[$counter];
            $call = new Call($callArray);
            $state = $call->getState();

            /**
             * Edge case
             */
            if ($state == null) {
                if ($call->getTagName() == "acronym") {
                    // Acronym does not have an enter/exit state
                    //  this is a sibling
                    break;
                }
            }

            /**
             * Before the breaking condition
             * to take the case when the first call is an exit
             */
            switch ($state) {
                case DOKU_LEXER_ENTER:
                    $treeLevel = $treeLevel - 1;
                    break;
                case DOKU_LEXER_EXIT:
                    /**
                     * When the tag has a sibling with an exit tag
                     */
                    $treeLevel = $treeLevel + 1;
                    break;
                case DOKU_LEXER_UNMATCHED:
                    if (empty(trim($call->getCapturedContent()))) {
                        // An empty unmatched is not considered a sibling
                        // state = null will continue the loop
                        // we can't use a continue statement in a switch
                        $state = null;
                    }
                    break;
            }

            /*
             * Breaking conditions
             * If we get above or on the same level
             */
            if ($treeLevel <= 0
                && $state != null // eol state, strong close or empty tag
            ) {
                break;
            } else {
                $counter = $counter - 1;
                unset($callArray);
            }

        }
        /**
         * Because we don't count tag without an state such as eol,
         * the tree level may be negative which means
         * that there is no sibling
         */
        if ($treeLevel == 0) {
            return $this->createFromCall($counter);
        }
        return null;


    }

    public function hasParent()
    {
        return $this->getParent() !== false;
    }


    /**
     * @return bool|Tag
     * @deprecated use {@link CallStack::moveToPreviousCorrespondingOpeningCall()} instead
     * @date 2021-05-13 deprecation date
     */
    public function getOpeningTag()
    {
        $descendantCounter = sizeof($this->calls) - 1;
        while ($descendantCounter > 0) {

            $previousCallArray = $this->calls[$descendantCounter];
            $previousCall = new Call($previousCallArray);
            $parentTagName = $previousCall->getTagName();
            $state = $previousCall->getState();
            if ($state === DOKU_LEXER_ENTER && $parentTagName === $this->getName()) {
                break;
            } else {
                $descendantCounter = $descendantCounter - 1;
                unset($previousCallArray);
            }

        }
        if (isset($previousCallArray)) {
            return $this->createFromCall($descendantCounter);
        } else {
            return false;
        }
    }

    /**
     * @return bool
     * @throws Exception - if the tag is not an exit tag
     */
    public function hasDescendants()
    {

        if (sizeof($this->getDescendants()) > 0) {
            return true;
        } else {
            return false;
        }
    }


    /**
     *
     * @return Tag the first descendant that is not whitespace
     * @deprecated use {@link CallStack::moveToFirstChildTag()}
     * @date 2021-05-13
     */
    public function getFirstMeaningFullDescendant()
    {
        $descendants = $this->getDescendants();
        $firstDescendant = $descendants[0];
        if ($firstDescendant->getState() == DOKU_LEXER_UNMATCHED && trim($firstDescendant->getContentRecursively()) == "") {
            return $descendants[1];
        } else {
            return $firstDescendant;
        }
    }

    /**
     * Descendant can only be run on enter tag
     * @return Tag[]
     */
    public function getDescendants()
    {

        if ($this->state != DOKU_LEXER_ENTER) {
            throw new RuntimeException("Descendant should be called on enter tag. Get the opening tag first if you are in a exit tag");
        }
        if (isset($this->actualPosition)) {
            $index = $this->actualPosition + 1;
        } else {
            throw new RuntimeException("It seems that we are at the end of the stack because the position is not set");
        }
        $descendants = array();
        while ($index <= sizeof($this->calls) - 1) {

            $childCallArray = $this->calls[$index];
            $childCall = new Call($childCallArray);
            $childTagName = $childCall->getTagName();
            $state = $childCall->getState();

            /**
             * We break when got to the exit tag
             */
            if ($state === DOKU_LEXER_EXIT && $childTagName === $this->getName()) {
                break;
            } else {
                /**
                 * We don't take the end of line
                 */
                if ($childCallArray[0] != "eol") {
                    /**
                     * We don't take text
                     */
                    //if ($state!=DOKU_LEXER_UNMATCHED) {
                    $descendants[] = $this->createFromCall($index);
                    //}
                }
                /**
                 * Close
                 */
                $index = $index + 1;
                unset($childCallArray);
            }

        }
        return $descendants;
    }

    /**
     * @param string $requiredTagName
     * @return Tag|null
     */
    public function getDescendant($requiredTagName)
    {
        $tags = $this->getDescendants();
        foreach ($tags as $tag) {
            $currentTagName = $tag->getName();
            if ($currentTagName === $requiredTagName) {
                $currentTagState = $tag->getState();
                /**
                 * No unmatched tag
                 */
                if (
                    $currentTagState === DOKU_LEXER_ENTER
                    || $currentTagState === DOKU_LEXER_MATCHED
                    || $currentTagState === DOKU_LEXER_SPECIAL
                ) {
                    return $tag;
                } else {
                    /**
                     * Dokuwiki special match does not have any
                     * state
                     */
                    if ($currentTagName == "internalmedia") {
                        return $tag;
                    }
                }
            }
        }
        return null;
    }

    /**
     * @deprecated use {@link CallStack::deleteCall} instead
     */
    public function deleteCall()
    {
        /**
         * The current call in an handle method cannot be deleted
         * because it does not exist yet
         */
        if (!$this->isCurrent()) {
            unset($this->calls[$this->actualPosition]);
        } else {
            LogUtility::msg("Internal error: The current call cannot be deleted", LogUtility::LVL_MSG_WARNING, self::CANONICAL);
        }
    }

    /**
     * Returned the matched content for this tag
     */
    public function getMatchedContent()
    {
        if ($this->tagCall != null) {

            return $this->tagCall->getCapturedContent();
        } else {
            return null;
        }
    }

    /**
     *
     * @return array|mixed - the data
     */
    public function getData()
    {
        if ($this->tagCall != null) {
            return $this->tagCall->getPluginData();
        } else {
            return array();
        }
    }

    /**
     *
     * @return array|mixed - the context (generally a tag name)
     */
    public function getContext()
    {
        if ($this->tagCall != null) {
            $data = $this->tagCall->getPluginData();
            return $data[PluginUtility::CONTEXT];
        } else {
            return array();
        }
    }


    /**
     * Return the content of a tag (the string between this tag)
     * This function is generally called after a function that goes up on the stack
     * such as {@link getDescendant}
     * @return string
     */
    public function getContentRecursively()
    {
        $content = "";
        $state = $this->getState();
        switch ($state) {
            case DOKU_LEXER_ENTER:
                $index = $this->actualPosition + 1;
                while ($index <= sizeof($this->calls) - 1) {

                    $currentCallArray = $this->calls[$index];
                    $currentCall = new Call($currentCallArray);
                    if (
                        $currentCall->getTagName() == $this->getName()
                        &&
                        $currentCall->getState() == DOKU_LEXER_EXIT
                    ) {
                        break;
                    } else {
                        $content .= $currentCall->getCapturedContent();
                        $index++;
                    }
                }
                break;
            case DOKU_LEXER_UNMATCHED:
            default:
                $content = $this->getContent();
                break;
        }


        return $content;

    }

    /**
     * Return true if the tag is the first sibling
     *
     *
     * @return boolean - true if this is the first sibling
     */
    public function isFirstMeaningFullSibling()
    {
        $sibling = $this->getPreviousSibling();
        if ($sibling == null) {
            return true;
        } else {
            /** Whitespace string */
            if ($sibling->getState() == DOKU_LEXER_UNMATCHED && trim($sibling->getContentRecursively()) == "") {
                $sibling = $sibling->getPreviousSibling();
            }
            if ($sibling == null) {
                return true;
            } else {
                return false;
            }
        }

    }

    /**
     * @param $key
     * @param $value
     * @return $this
     */
    public function addAttribute($key, $value)
    {
        $call = $this->createCallObjectFromIndex($this->actualPosition);
        $call->addAttribute($key, $value);
        return $this;
    }

    /**
     * @param $value
     * @return $this
     */
    public function setContext($value)
    {
        $call = $this->createCallObjectFromIndex($this->actualPosition);
        $call->setContext($value);
        return $this;
    }

    /**
     * @param $key
     * @param $value
     * @return $this
     */
    public function setAttribute($key, $value)
    {
        $call = $this->createCallObjectFromIndex($this->actualPosition);
        $call->addAttribute($key,$value);
        return $this;
    }

    /**
     * @param $key
     * @return $this
     */
    public function unsetAttribute($key)
    {
        unset($this->calls[$this->actualPosition][1][1][PluginUtility::ATTRIBUTES][$key]);
        return $this;
    }

    /**
     * Add a class to an element
     * @param $value
     * @return $this
     */
    public function addClass($value)
    {
        $call = $this->createCallObjectFromIndex($this->actualPosition);
        $classValue = $call->getAttribute("class");

        if (empty($classValue)) {
            $call->addAttribute("class", $value);
        } else {
            $call->addAttribute("class", $classValue . " " . $value);
        }
        return $this;

    }

    /**
     * @param $value
     * @return $this
     */
    public function setType($value)
    {
        $call = $this->createCallObjectFromIndex($this->actualPosition);
        $call->addAttribute(TagAttributes::TYPE_KEY, $value);
        return $this;
    }

    public function getActualPosition()
    {
        return $this->actualPosition;
    }

    public function getContent()
    {
        if ($this->tagCall != null) {
            return $this->tagCall->getCapturedContent();
        } else {
            return null;
        }
    }

    public function getDocumentStartTag()
    {
        if (sizeof($this->calls) > 0) {
            return $this->createFromCall(0);
        } else {
            throw new RuntimeException("The stack is empty, there is no root tag");
        }
    }

    /**
     * The current call is the call being created
     * in a {@link SyntaxPlugin::handle()}
     * It does not exist yet in the call stack
     * and cannot be deleted
     * @return bool
     */
    private function isCurrent()
    {
        return $this->actualPosition == sizeof($this->calls);
    }

    public function removeAttributes()
    {
        if (!$this->isCurrent()) {
            $call = $this->createCallObjectFromIndex($this->actualPosition);
            $call->removeAttributes();
        } else {
            LogUtility::msg("Internal error: This is not logic to remove the attributes of the current node because it's not yet created, not yet in the stack. Don't add them to the constructor signature.", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
        }
    }

    public function getNextOpeningTag($tagName)
    {
        $position = $this->actualPosition;
        while ($this->toNextPositionNonEmpty($position)) {

            $call = new Call($this->handler->calls[$position]);
            if ($call->getTagName() == $tagName && $call->getState() == DOKU_LEXER_ENTER) {
                return $this->createFromCall($position);
            }

        }
        return null;
    }

    /**
     *
     * If element were deleted,
     * the previous calls may be empty
     * make sure that we got something
     *
     * @param $callStackPosition
     * @return int|mixed
     */
    public function getPreviousPositionNonEmpty($callStackPosition)
    {

        while (!isset($this->calls[$callStackPosition]) && $callStackPosition > 0) {
            $callStackPosition = $callStackPosition - 1;
        }
        return $callStackPosition;
    }

    /**
     *
     * Increment the callStackPosition to a non-empty call stack position
     *
     * Array index are sequence number that may be deleted. We get then empty gap.
     * This function increments the pointer and return false if the end of the stack was reached
     *
     * @param $callStackPointer
     * @return true|false - If this is the end of the stack, return false, otherwise return true
     */
    public function toNextPositionNonEmpty(&$callStackPointer)
    {
        $callStackPointer = $callStackPointer + 1;
        while (!isset($this->calls[$callStackPointer]) && $callStackPointer < $this->maxPosition) {
            $callStackPointer = $callStackPointer + 1;
        }
        if (!isset($this->calls[$callStackPointer])) {
            return false;
        } else {
            return true;
        }
    }

    public function getNextClosingTag($tagName)
    {
        $position = $this->actualPosition;
        while ($this->toNextPositionNonEmpty($position)) {

            $call = new Call($this->handler->calls[$position]);
            if ($call->getTagName() == $tagName && $call->getState() == DOKU_LEXER_EXIT) {
                return $this->createFromCall($position);
            }

        }
        return null;
    }

    /**
     * @return Tag|null the next children or null - the tag should be an enter tag
     */
    public function getNextChild()
    {
        if ($this->getState() !== DOKU_LEXER_ENTER) {
            // The tag ($this) is not an enter tag and has therefore no children."
            return null;
        }
        $position = $this->actualPosition;
        $result = $this->toNextPositionNonEmpty($position);
        if ($result === false) {
            return null;
        } else {
            return $this->createFromCall($position);
        }

    }

    /**
     * Children are tag
     * @return array|null
     */
    public function getChildren()
    {
        if ($this->getState() !== DOKU_LEXER_ENTER) {
            // The tag ($this) is not an enter tag and has therefore no children."
            return null;
        }
        $children = [];
        $level = 0;
        $position = $this->actualPosition;
        while ($this->toNextPositionNonEmpty($position)) {

            $call = new Call($this->handler->calls[$position]);
            $state = $call->getState();
            switch ($state) {
                case DOKU_LEXER_ENTER:
                    $level += 1;
                    break;
                case DOKU_LEXER_EXIT:
                    $level -= 1;
                    break;
            }
            if ($level < 0) {
                break;
            } else {
                if ($state == DOKU_LEXER_ENTER && !in_array($call->getTagName(), Tag::INVISIBLE_CONTENT_TAG)) {
                    $children[] = $this->createFromCall($position);
                }
            }
        }
        return $children;
    }

    public function setAttributeIfNotPresent($key, $value)
    {
        if (!isset($this->calls[$this->actualPosition][1][1][PluginUtility::ATTRIBUTES][$key])) {
            $this->setAttribute($key, $value);
        }

    }

    public function getNextTag()
    {
        $position = $this->actualPosition;
        $this->toNextPositionNonEmpty($position);
        return $this->createFromCall($position);
    }


}
