<?php

use ComboStrap\Page;

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

class action_plugin_combo_autofrontmatter extends DokuWiki_Action_Plugin
{

    const CONF_AUTOFRONTMATTER_ENABLE = "autoFrontMatterEnable";

    public function register(Doku_Event_Handler $controller)
    {
        /**
         * Called when new page is created
         * In order to set its content
         * https://www.dokuwiki.org/devel:event:common_pagetpl_load
         */
        if ($this->getConf(self::CONF_AUTOFRONTMATTER_ENABLE)) {
            $controller->register_hook('COMMON_PAGETPL_LOAD', 'BEFORE', $this, 'handle_new_page', array());
        }
    }

    public function handle_new_page(Doku_Event $event, $param){

        $page = Page::createPageFromCurrentId();
        $canonical = $page->getCanonical();
        $event->data["tpl"] = <<<EOF
---json
{
    "canonical":"{$canonical}",
    "title":"A [[https://combostrap.com/frontmatter|frontmatter]] title shown on the Search Engine Result Pages",
    "description":"A [[https://combostrap.com/frontmatter|frontmatter]] description shown on the Search Engine Result Pages"
}
---
EOF;


    }
}



