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
 * This one support background loading
 * https://github.com/ApoorvSaxena/lozad.js
 *
 *
 * TODO: implement no script pattern ? the https://github.com/aFarkas/lazysizes#the-noscript-pattern
 *
 */
class LazyLoad
{

    const CONF_LAZY_LOADING_PLACEHOLDER_COLOR = "lazyLoadingPlaceholderColor";

    /**
     * Lozad was choosen because
     * it was easier to add svg injection
     * it supports background image
     * it's most used (JsDelivr stats)
     */
    const ACTIVE = self::LOZAD_ID;

    /**
     * The id of the lazy loaders
     */
    const LAZY_SIDE_ID = "lazy-sizes";
    const LOZAD_ID = "lozad";


    const CANONICAL = "lazy";
    const DEFAULT_COLOR = "#cbf1ea";

    /**
     * Used to select all lazy loaded
     * resources and load them before print
     */
    const LAZY_CLASS = "lazy-combo";


    public static function addSnippet()
    {
        switch (self::ACTIVE) {
            case self::LAZY_SIDE_ID:
                LazyLoad::addLazySizesSnippet();
                break;
            case self::LOZAD_ID:
                LazyLoad::addLozadSnippet();
                break;
            default:
                throw new \RuntimeException("The active lazy loaded is unknown (" . self::ACTIVE . ")");
        }

    }

    /**
     * Add the lazy sizes snippet
     */
    private static function addLazySizesSnippet()
    {

        $snippetManager = PluginUtility::getSnippetManager();

        $snippetManager->upsertTagsForBar(self::LAZY_SIDE_ID,
            array(
                'script' => [
                    array(
                        "src" => "https://cdn.jsdelivr.net/npm/lazysizes@5.3.1/lazysizes.min.js",
                        "integrity" => "sha256-bmG+LzdKASJRACVXiUC69++Nu8rz7MX1U1z8gb0c/Tk=",
                        "crossorigin" => "anonymous"
                    )
                ]
            )
        );
        /**
         * The Spinner effect
         * lazysizes adds the class lazy loading while the images are loading
         * and the class lazyloaded as soon as the image is loaded.
         */
        $snippetManager->attachCssSnippetForBar(self::LAZY_SIDE_ID);

    }

    /**
     * @param TagAttributes $attributes
     */
    public static function addPlaceholderBackground(&$attributes)
    {
        // https://github.com/ApoorvSaxena/lozad.js#large-image-improvment
        $placeholderColor = LazyLoad::getPlaceholderColor();
        if ($attributes->hasComponentAttribute(Background::BACKGROUND_COLOR)) {
            $placeholderColor = $attributes->getValueAndRemove(Background::BACKGROUND_COLOR);
        }
        $attributes->addHtmlAttributeValue("data-placeholder-background", "$placeholderColor");


    }

    /**
     * Add lozad
     * Support background image
     * https://github.com/ApoorvSaxena/lozad.js
     */
    public static function addLozadSnippet()
    {

        $snippetManager = PluginUtility::getSnippetManager();

        // https://www.jsdelivr.com/package/npm/lozad
        $snippetManager->upsertTagsForBar(self::LOZAD_ID,
            array(
                'script' => [
                    array(
                        "src" => "https://cdn.jsdelivr.net/npm/lozad@1.16.0/dist/lozad.min.js",
                        "integrity" => "sha256-mOFREFhqmHeQbXpK2lp4nA3qooVgACfh88fpJftLBbc=",
                        "crossorigin" => "anonymous"

                    )
                ]
            )
        );

        /**
         * Add the fading effect
         */
        $snippetId = "lazy-load-fade";
        $snippetManager->attachCssSnippetForBar($snippetId);


        /**
         * Snippet to download the image before print
         *
         * The others javascript snippet to download lazy load depend on the image type
         * and features and was therefore added in the code for svg or raster
         */
        $snippetManager->attachJavascriptSnippetForBar("lozad-print");


    }

    /**
     * Class selector to identify the element to lazy load
     */
    public static function getClass()
    {
        switch (self::ACTIVE) {
            case self::LAZY_SIDE_ID:
                return "lazyload";
            case self::LOZAD_ID:
                return "lozad";
            default:
                throw new \RuntimeException("The active lazy loaded is unknown (" . self::ACTIVE . ")");
        }
    }

    /**
     * @return string - the lazy loading placeholder color
     */
    public static function getPlaceholderColor()
    {
        return PluginUtility::getConfValue(self::CONF_LAZY_LOADING_PLACEHOLDER_COLOR, self::DEFAULT_COLOR);
    }

    /**
     * The placeholder is not mandatory
     * but if present, it should have the same intrinsic ratio of the image
     *
     * This function is documenting this fact.
     *
     * @param null $imgTagWidth
     * @param null $imgTagHeight
     * @return string
     *

     *
     * Src is always set, this is the default
     * src attribute is served to browsers that do not take the srcset attribute into account.
     * When lazy loading, we set the srcset to a transparent image to not download the image in the src
     *
     */

    public static function getPlaceholder($imgTagWidth = null, $imgTagHeight = null)
    {
        if ($imgTagWidth != null) {
            $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 $imgTagWidth $imgTagHeight'></svg>";
            /**
             * We encode it to be able to
             * use it in a `srcset` attribute that does not
             * want any space in the image definition
             */
            $svgBase64 = base64_encode($svg);
            $image = "data:image/svg+xml;base64,$svgBase64";
        } else {
            /**
             * Base64 transparent gif
             * 1x1 image, it will produce a square
             */
            $image = "data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==";
        }
        return $image;
    }
}
