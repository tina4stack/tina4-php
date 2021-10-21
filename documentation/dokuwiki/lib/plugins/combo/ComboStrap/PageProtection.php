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


use syntax_plugin_combo_tooltip;

/**
 * Class PageProtection handles the protection of page against
 * public agent such as low quality page or late publication
 *
 * @package ComboStrap
 *
 * The test are in two separate classes {@link \LowQualityPageTest}
 * and {@link \PublicationTest}
 */
class PageProtection
{

    const NAME = "page-protection";
    const ACRONYM = "pp";

    /**
     * The possible values
     */
    const CONF_VALUE_ACL = "acl";
    const CONF_VALUE_HIDDEN = "hidden";
    const CONF_VALUE_ROBOT = "robot";
    const CONF_VALUE_FEED = "feed";


    const PAGE_PROTECTION_LINK_WARNING = "warning";
    const PAGE_PROTECTION_LINK_NORMAL = "normal";
    const PAGE_PROTECTION_LINK_LOGIN = "login";


    /**
     * Add the Javascript snippet
     * We have created only one because a page
     * may be of low quality and with a late publication.
     * To resolve conflict between the two protections, the parameters are the same
     * and the late publication takes precedence on low quality page
     */
    public static function addPageProtectionSnippet()
    {
        syntax_plugin_combo_tooltip::addToolTipSnippetIfNeeded();
        PluginUtility::getSnippetManager()->attachJavascriptSnippetForBar(self::NAME);
    }

}
