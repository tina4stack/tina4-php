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


class Resources
{

    /**
     * Where are all resources
     */
    const RESOURCES_DIRECTORY_NAME = "resources";
    const SNIPPET_DIRECTORY_NAME = "snippet";
    const IMAGES_DIRECTORY_NAME = 'images';


    public static function getImagesDirectory()
    {
        return self::getAbsoluteResourcesDirectory() . '/' . self::IMAGES_DIRECTORY_NAME;
    }

    public static function getSnippetResourceDirectory()
    {
        return self::getAbsoluteResourcesDirectory() . "/snippet";
    }

    public static function getAbsoluteResourcesDirectory()
    {
        return DOKU_PLUGIN . self::getRelativeResourceDirectory();
    }

    /**
     * Relative to Plugin
     * @return string
     */
    public static function getRelativeImagesDirectory()
    {
        return self::getRelativeResourceDirectory() . '/' . self::IMAGES_DIRECTORY_NAME;
    }

    private static function getRelativeResourceDirectory()
    {
        return PluginUtility::PLUGIN_BASE_NAME . "/" . self::RESOURCES_DIRECTORY_NAME;
    }

    public static function getConfResourceDirectory()
    {
        return self::getAbsoluteResourcesDirectory() . "/conf";
    }

    public static function getComboHome()
    {
        return DOKU_PLUGIN . PluginUtility::PLUGIN_BASE_NAME;
    }

}
