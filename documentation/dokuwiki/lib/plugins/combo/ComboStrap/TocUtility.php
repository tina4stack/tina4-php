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


use Doku_Renderer;
use DokuWiki_Admin_Plugin;

class TocUtility
{

    public static function renderToc($renderer)
    {
        global $conf;
        global $TOC;

        // If the TOC is null (The toc may be initialized by a plugin)
        if (!is_array($TOC) or count($TOC) == 0) {
            $TOC = $renderer->toc;
        }

        if (count($TOC) > $conf['tocminheads']) {
            return tpl_toc($return = true);
        } else {
            return "";
        }
    }

    /**
     * @param Doku_Renderer $renderer
     * @return bool if the toc need to be shown
     *
     * From {@link Doku_Renderer::notoc()}
     * $this->info['toc'] = false;
     * when
     * ~~NOTOC~~
     */
    public static function showToc($renderer)
    {

        global $ACT;


        /**
         * Search page, no toc
         */
        if ($ACT == 'search') {

            return false;

        }


        /**
         * If this is another template such as Dokuwiki, we get two TOC.
         */
        if (!Site::isStrapTemplate()){
            return false;
        }

        /**
         * On the admin page
         */
        if ($ACT == 'admin') {

            global $INPUT;
            $plugin = null;
            $class = $INPUT->str('page');
            if (!empty($class)) {

                $pluginList = plugin_list('admin');

                if (in_array($class, $pluginList)) {
                    // attempt to load the plugin
                    /** @var $plugin DokuWiki_Admin_Plugin */
                    $plugin = plugin_load('admin', $class);
                }

                if ($plugin !== null) {
                    global $TOC;
                    if (!is_array($TOC)) $TOC = $plugin->getTOC(); //if TOC wasn't requested yet
                    if (!is_array($TOC)) {
                        return false;
                    } else {
                        return true;
                    }

                }

            }

        }


        if (isset($renderer->info['toc'])){
            return $renderer->info['toc'];
        } else {
            return true;
        }

    }
}
