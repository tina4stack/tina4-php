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


class ConfUtility
{

    /**
     * Return a plugin configuration
     * @param $key
     * @return mixed
     */
    public static function getConf($key)
    {
        global $conf;

        if (isset($conf['plugin'][PluginUtility::PLUGIN_BASE_NAME][$key])) {

            return $conf['plugin'][PluginUtility::PLUGIN_BASE_NAME][$key];

        } else {

            // May be not loaded
            $path = DOKU_PLUGIN . PluginUtility::PLUGIN_BASE_NAME . '/conf/';
            $conf = array();
            if (file_exists($path . 'default.php')) {
                include($path . 'default.php');
            }

            foreach ($conf as $key => $value) {
                if (isset($conf['plugin'][PluginUtility::PLUGIN_BASE_NAME][$key])) continue;
                $conf['plugin'][PluginUtility::PLUGIN_BASE_NAME][$key] = $value;
            }

        }

        if (isset($conf['plugin'][PluginUtility::PLUGIN_BASE_NAME][$key])) {
            return $conf['plugin'][PluginUtility::PLUGIN_BASE_NAME][$key];
        } else {
            LogUtility::msg("Unable to find the plugin configuration ($key)");
            return false;
        }


    }
}
