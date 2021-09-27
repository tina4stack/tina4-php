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


/**
 *
 * A class with all functions about publication
 * @package ComboStrap
 *
 */
class Publication
{

    /**
     * The key that contains the published date
     */
    const DATE_PUBLISHED = "date_published";
    const OLD_META_KEY = "published";

    /**
     * Late publication protection
     */
    const LATE_PUBLICATION_PROTECTION_ACRONYM = "lpp";
    const CONF_LATE_PUBLICATION_PROTECTION_MODE = "latePublicationProtectionMode";
    const CONF_LATE_PUBLICATION_PROTECTION_ENABLE = "latePublicationProtectionEnable";
    const LATE_PUBLICATION_CLASS_NAME = "late-publication";
    const CANONICAL = "published";



    public static function getLatePublicationProtectionMode()
    {

        if (PluginUtility::getConfValue(Publication::CONF_LATE_PUBLICATION_PROTECTION_ENABLE, true)) {
            return PluginUtility::getConfValue(Publication::CONF_LATE_PUBLICATION_PROTECTION_MODE);
        } else {
            return false;
        }

    }

    public static function isLatePublicationProtectionEnabled()
    {
        return PluginUtility::getConfValue(Publication::CONF_LATE_PUBLICATION_PROTECTION_ENABLE, true);
    }


}
