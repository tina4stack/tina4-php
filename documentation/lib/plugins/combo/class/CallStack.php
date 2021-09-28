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


use Doku_Handler;
use dokuwiki\Extension\SyntaxPlugin;
use dokuwiki\Parsing\Parser;
use syntax_plugin_combo_media;

/**
 * Class CallStack
 * @package ComboStrap
 *
 * This is a class that manipulate the call stack.
 *
 * A call stack is composed of call (ie array)
 * A tag is a call that has a state {@link DOKU_LEXER_ENTER} or {@link DOKU_LEXER_SPECIAL}
 * An opening call is a call with the {@link DOKU_LEXER_ENTER}
 * An closing call is a call with the {@link DOKU_LEXER_EXIT}
 *
 * You can move on the stack with the function:
 *   * {@link CallStack::next()}
 *   * {@link CallStack::previous()}
 *   * `MoveTo`. example: {@link CallStack::moveToPreviousCorrespondingOpeningCall()}
 *
 *
 */
class CallStack
{

    const TAG_STATE = [DOKU_LEXER_SPECIAL, DOKU_LEXER_ENTER];

    const CANONICAL = "support";

    /**
     * The type of callstack
     *   * main is the normal
     *   * writer is when there is a temporary call stack from the writer
     */
    const CALLSTACK_WRITER = "writer";
    const CALLSTACK_MAIN = "main";

    private $handler;

    /**
     * The max key of the calls
     * @var int|null
     */
    private $maxIndex;
    /**
     * @var array the call stack
     */
    private $callStack;

    /**
     * A pointer to keep the information
     * if we have gone to far in the stack
     * (because you lost the fact that you are outside
     * the boundary, ie you can do a {@link \prev}` after that a {@link \next} return false
     * @var bool
     * If true, we are at the offset: end of th array + 1
     */
    private $endWasReached = false;
    /**
     * If true, we are at the offset: start of th array - 1
     * You can use {@link CallStack::next()}
     * @var bool
     */
    private $startWasReached = false;

    /**
     * @var string the type of callstack
     */
    private $callStackType;

    /**
     * A callstack is a pointer implementation to manipulate
     * the {@link Doku_Handler::$calls call stack of the handler}
     *
     * When you create a callstack object, the pointer
     * is located at the end.
     *
     * If you want to reset the pointer, you need
     * to call the {@link CallStack::closeAndResetPointer()} function
     *
     * @param \Doku_Handler
     */
    public function __construct(&$handler)
    {
        $this->handler = $handler;

        /**
         * A temporary Call stack is created in the writer
         * for list, table, blockquote
         */
        $writerCalls = &$handler->getCallWriter()->calls;
        if (!empty($writerCalls)) {
            $this->callStack = &$writerCalls;
            $this->callStackType = self::CALLSTACK_WRITER;
        } else {
            $this->callStack = &$handler->calls;
            $this->callStackType = self::CALLSTACK_MAIN;
        }

        $this->maxIndex = ArrayUtility::array_key_last($this->callStack);
        $this->moveToEnd();

    }

    public
    static function createFromMarkup($marki)
    {

        $modes = p_get_parsermodes();
        $handler = new Doku_Handler();
        $parser = new Parser($handler);

        //add modes to parser
        foreach ($modes as $mode) {
            $parser->addMode($mode['mode'], $mode['obj']);
        }
        $parser->parse($marki);
        return self::createFromHandler($handler);

    }

    /**
     * Reset the pointer
     */
    public
    function closeAndResetPointer()
    {
        reset($this->callStack);
    }

    /**
     * Delete from the call stack
     * @param $calls
     * @param $start
     * @param $end
     */
    public
    static function deleteCalls(&$calls, $start, $end)
    {
        for ($i = $start; $i <= $end; $i++) {
            unset($calls[$i]);
        }
    }

    /**
     * @param array $calls
     * @param integer $position
     * @param array $callStackToInsert
     */
    public
    static function insertCallStackUpWards(&$calls, $position, $callStackToInsert)
    {

        array_splice($calls, $position, 0, $callStackToInsert);

    }

    /**
     * A callstack pointer based implementation
     * that starts at the end
     * @param Doku_Handler $handler
     * @return CallStack
     */
    public
    static function createFromHandler(\Doku_Handler &$handler)
    {
        return new CallStack($handler);
    }


    /**
     * Process the EOL call to the end of stack
     * replacing them with paragraph call
     *
     * A sort of {@link Block::process()} but only from a tag
     * to the end of the current stack
     *
     * This function is used basically in the {@link DOKU_LEXER_EXIT}
     * state of {@link SyntaxPlugin::handle()} to create paragraph
     * with the class given as parameter
     *
     * @param $attributes - the attributes in an callstack array form passed to the paragraph
     */
    public
    function processEolToEndStack($attributes = [])
    {

        \syntax_plugin_combo_para::fromEolToParagraphUntilEndOfStack($this, $attributes);

    }

    /**
     * Delete the call where the pointer is
     * And go to the previous position
     *
     * This function can be used in a next loop
     *
     * @return Call the deleted call
     */
    public
    function deleteActualCallAndPrevious()
    {

        $actualCall = $this->getActualCall();

        $offset = $this->getActualOffset();
        array_splice($this->callStack, $offset, 1, []);

        /**
         * Move to the next element (array splice reset the pointer)
         * if there is a eol as, we delete it
         * otherwise we may end up with two eol
         * and this is an empty paragraph
         */
        $this->moveToOffset($offset);
        if (!$this->isPointerAtEnd()) {
            if ($this->getActualCall()->getTagName() == 'eol') {
                array_splice($this->callStack, $offset, 1, []);
            }
        }

        /**
         * Move to the previous element
         */
        $this->moveToOffset($offset - 1);

        return $actualCall;

    }

    /**
     * @return Call - get a reference to the actual call
     * This function returns a {@link Call call} object
     * by reference, meaning that every update will also modify the element
     * in the stack
     */
    public
    function getActualCall()
    {
        if ($this->endWasReached) {
            LogUtility::msg("The actual call cannot be ask because the end of the stack was reached", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
            return null;
        }
        if ($this->startWasReached) {
            LogUtility::msg("The actual call cannot be ask because the start of the stack was reached", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
            return null;
        }
        $actualCallKey = key($this->callStack);
        $actualCallArray = &$this->callStack[$actualCallKey];
        return new Call($actualCallArray, $actualCallKey);

    }

    /**
     * put the pointer one position further
     * false if at the end
     * @return false|Call
     */
    public
    function next()
    {
        if ($this->startWasReached) {
            $this->startWasReached = false;
            reset($this->callStack);
            return $this->getActualCall();
        } else {
            $next = next($this->callStack);
            if ($next === false) {
                $this->endWasReached = true;
                return $next;
            } else {
                return $this->getActualCall();
            }
        }

    }

    /**
     *
     * From an exit call, move the corresponding Opening call
     *
     * This is used mostly in {@link SyntaxPlugin::handle()} from a {@link DOKU_LEXER_EXIT}
     * to retrieve the {@link DOKU_LEXER_ENTER} call
     *
     * @return bool|Call
     */
    public
    function moveToPreviousCorrespondingOpeningCall()
    {

        if (!$this->endWasReached) {
            $actualCall = $this->getActualCall();
            $actualState = $actualCall->getState();
            if ($actualState != DOKU_LEXER_EXIT) {
                /**
                 * Check if we are at the end of the stack
                 */
                LogUtility::msg("You are not at the end of stack and you are not on a opening tag, you can't ask for the opening tag." . $actualState, LogUtility::LVL_MSG_ERROR, "support");
                return false;
            }
        }
        $level = 0;
        while ($actualCall = $this->previous()) {

            $state = $actualCall->getState();
            switch ($state) {
                case DOKU_LEXER_ENTER:
                    $level++;
                    break;
                case DOKU_LEXER_EXIT:
                    $level--;
                    break;
            }
            if ($level > 0) {
                break;
            }

        }
        if ($level > 0) {
            return $actualCall;
        } else {
            return false;
        }
    }


    /**
     * @return Call|false the previous call or false if there is no more previous call
     */
    public
    function previous()
    {
        if ($this->endWasReached) {
            $this->endWasReached = false;
            $return = end($this->callStack);
            if ($return == false) {
                // empty array (first call on the stack)
                return false;
            } else {
                return $this->getActualCall();
            }
        } else {
            $prev = prev($this->callStack);
            if ($prev === false) {
                $this->startWasReached = true;
                return $prev;
            } else {
                return $this->getActualCall();
            }
        }

    }

    /**
     * Return the first enter or special child call (ie a tag)
     * @return Call|false
     */
    public
    function moveToFirstChildTag()
    {
        $found = false;
        while ($this->next()) {

            $actualCall = $this->getActualCall();
            $state = $actualCall->getState();
            switch ($state) {
                case DOKU_LEXER_ENTER:
                case DOKU_LEXER_SPECIAL:
                    $found = true;
                    break 2;
                case DOKU_LEXER_EXIT:
                    break 2;
            }

        }
        if ($found) {
            return $this->getActualCall();
        } else {
            return false;
        }


    }

    /**
     * The end is the one after the last element
     */
    public
    function moveToEnd()
    {
        if ($this->startWasReached) {
            $this->startWasReached = false;
        }
        end($this->callStack);
        $this->next();
    }

    /**
     * On the same level
     */
    public
    function moveToNextSiblingTag()
    {
        $actualCall = $this->getActualCall();
        $actualState = $actualCall->getState();
        if (!in_array($actualState, CallStack::TAG_STATE)) {
            LogUtility::msg("A next sibling can be asked only from a tag call. The state is " . $actualState, LogUtility::LVL_MSG_ERROR, "support");
            return false;
        }
        $level = 0;
        while ($this->next()) {

            $actualCall = $this->getActualCall();
            $state = $actualCall->getState();
            switch ($state) {
                case DOKU_LEXER_ENTER:
                case DOKU_LEXER_SPECIAL:
                    $level++;
                    break;
                case DOKU_LEXER_EXIT:
                    $level--;
                    break;
            }

            if ($level == 0 && in_array($state, self::TAG_STATE)) {
                break;
            }
        }
        if ($level == 0 && !$this->endWasReached) {
            return $this->getActualCall();
        } else {
            return false;
        }
    }

    /**
     * @param Call $call
     * @return Call the inserted call
     */
    public
    function insertBefore($call)
    {
        if ($this->endWasReached) {

            $this->callStack[] = $call->toCallArray();

        } else {

            $offset = $this->getActualOffset();
            array_splice($this->callStack, $offset, 0, [$call->toCallArray()]);
            // array splice reset the pointer
            // we move it to the actual element (ie the key is offset +1)
            $this->moveToOffset($offset + 1);

        }
        return $call;
    }

    /**
     * Move pointer by offset
     * @param $offset
     */
    private
    function moveToOffset($offset)
    {
        $this->resetPointer();
        for ($i = 0; $i < $offset; $i++) {
            $result = $this->next();
            if ($result === false) {
                break;
            }
        }
    }

    /**
     * Move pointer by key
     * @param $targetKey
     */
    private
    function moveToKey($targetKey)
    {
        $this->resetPointer();
        for ($i = 0; $i < $targetKey; $i++) {
            next($this->callStack);
        }
        $actualKey = key($this->callStack);
        if ($actualKey != $targetKey) {
            LogUtility::msg("The target key ($targetKey) is not equal to the actual key ($actualKey). The moveToKey was not successful");
        }
    }

    /**
     * Insert After. The pointer stays at the current state.
     * If you don't need to process the call that you just
     * inserted, you may want to call {@link CallStack::next()}
     * @param Call $call
     */
    public
    function insertAfter($call)
    {
        $actualKey = key($this->callStack);
        if ($actualKey == null) {
            if ($this->endWasReached == true) {
                $this->callStack[] = $call->toCallArray();
            } else {
                LogUtility::msg("Callstack: Actual key is null, we can't insert after null");
            }
        } else {
            $offset = array_search($actualKey, array_keys($this->callStack), true);
            array_splice($this->callStack, $offset + 1, 0, [$call->toCallArray()]);
            // array splice reset the pointer
            // we move it to the actual element
            $this->moveToKey($actualKey);
        }
    }

    public
    function getActualKey()
    {
        return key($this->callStack);
    }

    /**
     * Insert an EOL call if the next call is not an EOL
     * This is to enforce an new paragraph
     */
    public
    function insertEolIfNextCallIsNotEolOrBlock()
    {
        if (!$this->isPointerAtEnd()) {
            $nextCall = $this->next();
            if ($nextCall != false) {
                if ($nextCall->getTagName() != "eol" && $nextCall->getDisplay() != "block") {
                    $this->insertBefore(
                        Call::createNativeCall("eol")
                    );
                    // move on the eol
                    $this->previous();
                }
                // move back
                $this->previous();
            }
        }
    }

    private
    function isPointerAtEnd()
    {
        return $this->endWasReached;
    }

    public
    function &getHandler()
    {
        return $this->handler;
    }

    /**
     * Return The offset (not the key):
     *   * starting at 0 for the first element
     *   * 1 for the second ...
     *
     * @return false|int|string
     */
    private
    function getActualOffset()
    {
        $actualKey = key($this->callStack);
        return array_search($actualKey, array_keys($this->callStack), true);
    }

    private
    function resetPointer()
    {
        reset($this->callStack);
        $this->endWasReached = false;
    }

    public
    function moveToStart()
    {
        $this->resetPointer();
        $this->previous();
    }

    /**
     * @return Call|false the parent call or false if there is no parent
     */
    public function moveToParent()
    {

        /**
         * Case when we start from the exit state element
         * We go first to the opening tag
         * because the algorithm is level based.
         *
         * When the end is reached, there is no call
         * (this not the {@link end php end} but one further
         */
        if (!$this->endWasReached && !$this->startWasReached && $this->getActualCall()->getState() == DOKU_LEXER_EXIT) {

            $this->moveToPreviousCorrespondingOpeningCall();

        }


        /**
         * We are in a parent when the tree level is negative
         */
        $treeLevel = 0;
        while ($actualCall = $this->previous()) {

            /**
             * Add
             * would become a parent on its enter state
             */
            $actualCallState = $actualCall->getState();
            switch ($actualCallState) {
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
            if ($treeLevel < 0) {
                break;
            }

        }
        return $actualCall;


    }

    /**
     * Delete the anchor link to the image (ie the lightbox)
     *
     * This is used in navigation and for instance
     * in heading
     */
    public function processNoLinkOnImageToEndStack()
    {
        while ($this->next()) {
            $actualCall = $this->getActualCall();
            if ($actualCall->getTagName() == syntax_plugin_combo_media::TAG) {
                $actualCall->addAttribute(MediaLink::LINKING_KEY, MediaLink::LINKING_NOLINK_VALUE);
            }
        }
    }

    /**
     * Append instructions to the callstack (ie at the end)
     * @param $instructions
     */
    public function appendInstructions($instructions)
    {
        array_splice($this->callStack, count($this->callStack), 0, $instructions);
    }

    /**
     * @param Call $call
     */
    public function appendCallAtTheEnd($call)
    {
        $this->callStack[] = $call->toCallArray();
    }

    public function moveToPreviousSiblingTag()
    {
        if (!$this->endWasReached) {
            $actualCall = $this->getActualCall();
            $actualState = $actualCall->getState();
            if (!in_array($actualState, CallStack::TAG_STATE)) {
                LogUtility::msg("A previous sibling can be asked only from a tag call. The state is " . $actualState, LogUtility::LVL_MSG_ERROR, "support");
                return false;
            }
        }
        $level = 0;
        while ($this->previous()) {

            $actualCall = $this->getActualCall();
            $state = $actualCall->getState();
            switch ($state) {
                case DOKU_LEXER_ENTER:
                case DOKU_LEXER_SPECIAL:
                    $level++;
                    break;
                case DOKU_LEXER_EXIT:
                    $level--;
                    break;
            }

            if ($level == 0 && in_array($state, self::TAG_STATE)) {
                break;
            }
        }
        if ($level == 0 && !$this->startWasReached) {
            return $this->getActualCall();
        } else {
            return false;
        }
    }

    /**
     * Delete all calls after the passed call
     *
     * It's used in syntax generator that:
     *   * capture the children callstack at the end,
     *   * delete it
     *   * and use it to generate more calls.
     *
     * @param Call $call
     */
    public function deleteAllCallsAfter(Call $call)
    {
        $key = $call->getKey();
        $offset = array_search($key, array_keys($this->callStack), true);
        if ($offset !== false) {
            /**
             * We delete from the next
             * {@link array_splice()} delete also the given offset
             */
            array_splice($this->callStack, $offset + 1);
        } else {
            LogUtility::msg("The call ($call) could not be found in the callStack. We couldn't therefore delete the calls after");
        }

    }


}
