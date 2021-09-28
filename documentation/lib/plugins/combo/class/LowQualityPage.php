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


require_once('PluginUtility.php');

/**
 * Class LowQualityPage
 * @package ComboStrap
 *
 */
class LowQualityPage
{

    const LOW_QUALITY_PROTECTION_ACRONYM = "LQPP"; // low quality page protection
    const CONF_LOW_QUALITY_PAGE_PROTECTION_ENABLE = "lowQualityPageProtectionEnable";

    /**
     *
     */
    const CONF_LOW_QUALITY_PAGE_PROTECTION_MODE = "lowQualityPageProtectionMode";

    const CONF_LOW_QUALITY_PAGE_LINK_TYPE = "lowQualityPageLinkType";
    const CLASS_NAME = "low-quality-page";

    public static function getLowQualityProtectionMode()
    {
        if (PluginUtility::getConfValue(LowQualityPage::CONF_LOW_QUALITY_PAGE_PROTECTION_ENABLE, true)) {
            return PluginUtility::getConfValue(LowQualityPage::CONF_LOW_QUALITY_PAGE_PROTECTION_MODE, PageProtection::CONF_VALUE_ACL);
        } else {
            return false;
        }
    }

    /**
     * The protection does not occur on the HTML
     * because the created page is valid for a anonymous or logged-in user
     * @return mixed|null
     */
    public static function isProtectionEnabled()
    {

        return PluginUtility::getConfValue(LowQualityPage::CONF_LOW_QUALITY_PAGE_PROTECTION_ENABLE, true);

    }

    public static function getLowQualityLinkType()
    {

        return PluginUtility::getConfValue(LowQualityPage::CONF_LOW_QUALITY_PAGE_LINK_TYPE, PageProtection::PAGE_PROTECTION_LINK_NORMAL);

    }

}
