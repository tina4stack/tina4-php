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


/**
 * Class AdsUtility
 * @package ComboStrap
 *
 * TODO: Injection: Words Between Ads (https://wpadvancedads.com/manual/minimum-amount-of-words-between-ads/)
 */
class AdsUtility
{

    const CONF_ADS_MIN_LOCAL_LINE_DEFAULT = 2;
    const CONF_ADS_MIN_LOCAL_LINE_KEY = 'AdsMinLocalLine';
    const CONF_ADS_LINE_BETWEEN_DEFAULT = 13;
    const CONF_ADS_LINE_BETWEEN_KEY = 'AdsLineBetween';
    const CONF_ADS_MIN_SECTION_NUMBER_DEFAULT = 2;
    const CONF_ADS_MIN_SECTION_KEY = 'AdsMinSectionNumber';


    const ADS_NAMESPACE = ':combostrap:ads:';

    /**
     * All in-article should start with this prefix
     */
    const PREFIX_IN_ARTICLE_ADS = "inarticle";

    /**
     * Do we show a placeholder when there is no ad page
     * for a in-article
     */
    const CONF_IN_ARTICLE_PLACEHOLDER = 'AdsInArticleShowPlaceholder';
    const CONF_IN_ARTICLE_PLACEHOLDER_DEFAULT = 0;

    public static function showAds($sectionLineCount, $currentLineCountSinceLastAd, $sectionNumber, $adsCounter, $isLastSection)
    {
        global $ACT;
        global $ID;
        if (
            FsWikiUtility::isSideBar() == FALSE && // No display on the sidebar
            $ACT != 'admin' && // Not in the admin page
            isHiddenPage($ID) == FALSE && // No ads on hidden pages
            (
                (
                    $sectionLineCount > self::CONF_ADS_MIN_LOCAL_LINE_DEFAULT && // Doesn't show any ad if the section does not contains this minimum number of line
                    $currentLineCountSinceLastAd > self::CONF_ADS_LINE_BETWEEN_DEFAULT && // Every N line,
                    $sectionNumber > self::CONF_ADS_MIN_SECTION_NUMBER_DEFAULT // Doesn't show any ad before
                )
                or
                // Show always an ad after a number of section
                (
                    $adsCounter == 0 && // Still no ads
                    $sectionNumber > self::CONF_ADS_MIN_SECTION_NUMBER_DEFAULT && // Above the minimum number of section
                    $sectionLineCount > self::CONF_ADS_MIN_LOCAL_LINE_DEFAULT // Minimum line in the current section (to avoid a pub below a header)
                )
                or
                // Sometimes the last section (reference) has not so much line and it avoids to show an ads at the end
                // even if the number of line (space) was enough
                (
                    $isLastSection && // The last section
                    $currentLineCountSinceLastAd > self::CONF_ADS_LINE_BETWEEN_DEFAULT  // Every N line,
                )
            )) {
            return true;
        } else {
            return false;
        }
    }

    public static function showPlaceHolder()
    {
        return PluginUtility::getConfValue(self::CONF_IN_ARTICLE_PLACEHOLDER);
    }

    /**
     * Return the full page location
     * @param $name
     * @return string
     */
    public static function getAdPage($name)
    {
        return strtolower(self::ADS_NAMESPACE . $name);
    }

    /**
     * Return the id of the div that wrap the ad
     * @param $name - the name of the page ad
     * @return string|string[]
     */
    public static function getTagId($name)
    {
        return str_replace(":","-",substr(self::getAdPage($name),1));
    }

    public static function render(array $attributes)
    {
        $htmlAttributes = array();
        $html = "";
        if (!isset($attributes["name"])) {

            $name = "unknown";
            $html = "The name attribute is mandatory to render an ad";
            $htmlAttributes["color"] = "red";

        } else {

            $name = $attributes["name"];
            $adsPageId = AdsUtility::getAdPage($name);
            if (page_exists($adsPageId)) {
                $html .= RenderUtility::renderId2Xhtml($adsPageId);
            } else {
                if (!(strpos($name, self::PREFIX_IN_ARTICLE_ADS) === 0)) {
                    $html .= "The ad page (" . $adsPageId . ") does not exist";
                    $htmlAttributes["color"] = "red";
                } else {
                    if (AdsUtility::showPlaceHolder()) {

                        $html .= 'Ads Page Id (' . $adsPageId . ') not found. <br>' . DOKU_LF
                        . 'Showing the '.PluginUtility::getUrl("automatic/in-article/ad#AdsInArticleShowPlaceholder","In-article placeholder").'<br>' . DOKU_LF;

                        $htmlAttributes["color"] = "dark";
                        $htmlAttributes["spacing"] = "m-3 p-3";
                        $htmlAttributes["text-align"] = "center";
                        $htmlAttributes["align"] = "center";
                        $htmlAttributes["width"] = "600px";
                        $htmlAttributes["border-color"] = "dark";

                    } else {

                        // Show nothing
                        return "";

                    }
                }
            }

        }

        /**
         * We wrap the ad with a div to locate it and id it
         */
        $divHtmlWrapper = "<div";
        $htmlAttributes["id"] = AdsUtility::getTagId($name);
        $htmlAttributes = PluginUtility::mergeAttributes($attributes, $htmlAttributes);
        unset($htmlAttributes["name"]);
        $divHtmlWrapper .= " " . PluginUtility::array2HTMLAttributesAsString($htmlAttributes);
        return $divHtmlWrapper . ">" . $html . '</div>';
    }
}
