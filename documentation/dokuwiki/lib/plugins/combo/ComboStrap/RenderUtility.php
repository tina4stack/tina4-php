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


use dokuwiki\Extension\SyntaxPlugin;

class RenderUtility
{

    /**
     * @param $content
     * @param bool $strip
     * @return string|null
     */
    public static function renderText2XhtmlAndStripPEventually($content, $strip=true)
    {
        $instructions = self::getInstructionsAndStripPEventually($content,$strip);
        return p_render('xhtml', $instructions, $info);
    }

    /**
     * @param $pageContent - the text (not the id)
     * @param bool $stripOpenAndEnd - to avoid the p element in test rendering
     * @return array
     */
    public static function getInstructionsAndStripPEventually($pageContent, $stripOpenAndEnd = true)
    {

        $instructions = p_get_instructions($pageContent);

        if ($stripOpenAndEnd) {

            /**
             * Delete the p added by {@link Block::process()}
             * if the plugin of the {@link SyntaxPlugin::getPType() normal} and not in a block
             *
             * p_open = document_start in renderer
             */
            if ($instructions[1][0] == 'p_open') {
                unset($instructions[1]);

                /**
                 * The last p position is not fix
                 * We may have other calls due for instance
                 * of {@link \action_plugin_combo_syntaxanalytics}
                 */
                $n = 1;
                while (($lastPBlockPosition = (sizeof($instructions) - $n)) >= 0) {

                    /**
                     * p_open = document_end in renderer
                     */
                    if ($instructions[$lastPBlockPosition][0] == 'p_close') {
                        unset($instructions[$lastPBlockPosition]);
                        break;
                    } else {
                        $n = $n + 1;
                    }
                }
            }

        }

        return $instructions;
    }

    /**
     * @param $pageId
     * @return string|null
     */
    public
    static function renderId2Xhtml($pageId)
    {
        $file = wikiFN($pageId);
        if (file_exists($file)) {
            $content = file_get_contents($file);
            return self::renderText2XhtmlAndStripPEventually($content);
        } else {
            return false;
        }
    }

    public
    static function renderId2Json($pageId)
    {
        return Analytics::processAndGetDataAsJson($pageId);
    }
}
