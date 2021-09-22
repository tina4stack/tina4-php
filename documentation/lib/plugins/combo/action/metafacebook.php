<?php

use ComboStrap\RasterImageLink;
use ComboStrap\MediaLink;
use ComboStrap\LogUtility;
use ComboStrap\MetadataUtility;
use ComboStrap\PluginUtility;
use ComboStrap\Page;
use ComboStrap\Site;
use ComboStrap\StringUtility;

if (!defined('DOKU_INC')) die();

require_once(__DIR__ . '/../ComboStrap/Site.php');
require_once(__DIR__ . '/../ComboStrap/RasterImageLink.php');

/**
 *
 * For the canonical meta, see {@link action_plugin_combo_metacanonical}
 *
 * Inspiration, reference:
 * https://developers.facebook.com/docs/sharing/webmasters
 * https://github.com/twbs/bootstrap/blob/v4-dev/site/layouts/partials/social.html
 * https://github.com/mprins/dokuwiki-plugin-socialcards/blob/master/action.php
 */
class action_plugin_combo_metafacebook extends DokuWiki_Action_Plugin
{

    const FACEBOOK_APP_ID = "486120022012342";

    /**
     * The image
     */
    const CONF_DEFAULT_FACEBOOK_IMAGE = "defaultFacebookImage";


    const CANONICAL = "facebook";


    function __construct()
    {
        // enable direct access to language strings
        // ie $this->lang
        $this->setupLocale();
    }

    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'metaFacebookProcessing', array());
    }

    /**
     *
     * @param $event
     */
    function metaFacebookProcessing($event)
    {

        global $ID;
        if (empty($ID)) {
            // $ID is null for media
            return;
        }


        $page = Page::createPageFromId($ID);
        if (!$page->exists()) {
            return;
        }

        /**
         * No social for bars
         */
        if ($page->isSlot()) {
            return;
        }


        /**
         * "og:url" is already created in the {@link action_plugin_combo_metacanonical}
         * "og:description" is already created in the {@link action_plugin_combo_metadescription}
         */
        $facebookMeta = array(
            "og:title" => StringUtility::truncateString($page->getTitleNotEmpty(), 70)
        );
        $descriptionOrElseDokuWiki = $page->getDescriptionOrElseDokuWiki();
        if (!empty($descriptionOrElseDokuWiki)) {
            // happens in test with document without content
            $facebookMeta["og:description"] = $descriptionOrElseDokuWiki;
        }

        $title = Site::getTitle();
        if (!empty($title)) {
            $facebookMeta["og:site_name"] = $title;
        }

        /**
         * Type of page
         */
        $ogType = $page->getType();
        if (!empty($ogType)) {
            $facebookMeta["og:type"] = $ogType;
        } else {
            // The default facebook value
            $facebookMeta["og:type"] = Page::WEBSITE_TYPE;
        }

        if ($ogType == Page::ARTICLE_TYPE) {
            // https://ogp.me/#type_article
            $facebookMeta["article:published_time"] = $page->getPublishedElseCreationTime()->format(DATE_ISO8601);
            $modifiedTime = $page->getModifiedTime();
            if($modifiedTime!=null) {
                $facebookMeta["article:modified_time"] = $modifiedTime->format(DATE_ISO8601);
            }
        }

        /**
         * @var MediaLink[]
         */
        $facebookImages = $page->getLocalImageSet();
        if (empty($facebookImages)) {
            $defaultFacebookImage = cleanID(PluginUtility::getConfValue(self::CONF_DEFAULT_FACEBOOK_IMAGE));
            if (!empty($defaultFacebookImage)) {
                $image = MediaLink::createMediaLinkFromNonQualifiedPath($defaultFacebookImage);
                if ($image->exists()) {
                    $facebookImages[] = $image;
                } else {
                    if ($defaultFacebookImage != "logo-facebook.png") {
                        LogUtility::msg("The default facebook image ($defaultFacebookImage) does not exist", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
                    }
                }


            }
        }
        if (!empty($facebookImages)) {

            /**
             * One of image/jpeg, image/gif or image/png
             * As stated here: https://developers.facebook.com/docs/sharing/webmasters#images
             **/
            $facebookMime = ["image/jpeg", "image/gif", "image/png"];
            foreach ($facebookImages as $facebookImage) {

                if (!in_array($facebookImage->getMime(), $facebookMime)) {
                    continue;
                }

                /** @var RasterImageLink $facebookImage */
                if (!($facebookImage instanceof RasterImageLink)) {
                    LogUtility::msg("Internal: The image ($facebookImage) is not a raster image and this should not be the case for facebook", LogUtility::LVL_MSG_ERROR, "support");
                    continue;
                }

                if (!$facebookImage->exists()) {
                    LogUtility::msg("The image ($facebookImage) does not exist and was not added", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
                } else {

                    $toSmall = false;
                    if ($facebookImage->isAnalyzable()) {

                        // There is a minimum size constraint of 200px by 200px
                        if ($facebookImage->getMediaWidth() < 200) {
                            $toSmall = true;
                        } else {
                            $facebookMeta["og:image:width"] = $facebookImage->getMediaWidth();
                            if ($facebookImage->getMediaHeight() < 200) {
                                $toSmall = true;
                            } else {
                                $facebookMeta["og:image:height"] = $facebookImage->getMediaHeight();
                            }
                        }
                    }

                    if ($toSmall) {
                        $message = "The facebook image ($facebookImage) is too small (" . $facebookImage->getMediaWidth() . " x " . $facebookImage->getMediaHeight() . "). The minimum size constraint is 200px by 200px";
                        if ($facebookImage->getId() != $page->getFirstImage()->getId()) {
                            LogUtility::msg($message, LogUtility::LVL_MSG_ERROR, self::CANONICAL);
                        } else {
                            LogUtility::log2BrowserConsole($message);
                        }
                    }


                    /**
                     * We may don't known the dimensions
                     */
                    if (!$toSmall) {
                        $mime = $facebookImage->getMime();
                        if (!empty($mime)) {
                            $facebookMeta["og:image:type"] = $mime[1];
                        }
                        $facebookMeta["og:image"] = $facebookImage->getAbsoluteUrl();
                        // One image only
                        break;
                    }
                }

            }
        }


        $facebookMeta["fb:app_id"] = self::FACEBOOK_APP_ID;

        $lang = $page->getLang();
        if (!empty($lang)) {

            $country = $page->getCountry();
            if (empty($country)) {
                $country = $lang;
            }
            $facebookMeta["og:locale"] = $lang . "_" . strtoupper($country);

        } else {

            // The Facebook default
            $facebookMeta["og:locale"] = "en_US";

        }

        /**
         * Add the properties
         */
        foreach ($facebookMeta as $property => $content) {
            $event->data['meta'][] = array("property" => $property, "content" => $content);
        }


    }

}
