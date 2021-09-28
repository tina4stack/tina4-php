<?php

use ComboStrap\MediaLink;
use ComboStrap\LogUtility;
use ComboStrap\Page;
use ComboStrap\PluginUtility;
use ComboStrap\StringUtility;
use ComboStrap\TagAttributes;

if (!defined('DOKU_INC')) die();

require_once(__DIR__ . '/../class/Site.php');

/**
 *
 * For the canonical meta, see {@link action_plugin_combo_metacanonical}
 * https://github.com/twbs/bootstrap/blob/v4-dev/site/layouts/partials/social.html
 *
 * TODO: https://developer.twitter.com/en/docs/twitter-for-websites/embedded-tweets/overview
 */
class action_plugin_combo_metatwitter extends DokuWiki_Action_Plugin
{


    /**
     * The handle name
     */
    const CONF_TWITTER_SITE_HANDLE = "twitterSiteHandle";
    /**
     * The handle id
     */
    const CONF_TWITTER_SITE_ID = "twitterSiteId";
    /**
     * The image
     */
    const CONF_DEFAULT_TWITTER_IMAGE = "defaultTwitterImage";

    /**
     * Don't track
     */
    const CONF_TWITTER_DONT_NOT_TRACK = self::META_DNT;
    const CONF_DONT_NOT_TRACK = self::META_DNT;
    const CONF_ON = "on";
    const CONF_OFF = "off";

    /**
     * The creation ie (combostrap)
     */
    const COMBO_STRAP_TWITTER_HANDLE = "@combostrapweb";
    const COMBO_STRAP_TWITTER_ID = "1283330969332842497";
    const CANONICAL = "twitter";

    const META_CARD = "twitter:card";
    const DEFAULT_IMAGE = ":apple-touch-icon.png";
    const META_DESCRIPTION = "twitter:description";
    const META_IMAGE = "twitter:image";
    const META_TITLE = "twitter:title";
    const META_CREATOR = "twitter:creator";
    const META_CREATOR_ID = "twitter:creator:id";
    const META_SITE = "twitter:site";
    const META_SITE_ID = "twitter:site:id";
    const META_IMAGE_ALT = "twitter:image:alt";
    const META_DNT = "twitter:dnt";
    const META_WIDGET_CSP = "twitter:widgets:csp";
    const META_WIDGETS_THEME = "twitter:widgets:theme";
    const META_WIDGETS_BORDER_COLOR = "twitter:widgets:border-color";


    function __construct()
    {
        // enable direct access to language strings
        // ie $this->lang
        $this->setupLocale();
    }

    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'metaTwitterProcessing', array());
    }

    /**
     *
     * @param $event
     */
    function metaTwitterProcessing($event)
    {

        global $ID;
        if (empty($ID)) {
            // $ID is null for media
            return;
        }


        $page = Page::createPageFromId($ID);

        if(!$page->exists()){
            return;
        }

        /**
         * No social for bars
         */
        if ($page->isSlot()) {
            return;
        }


        // https://datacadamia.com/marketing/twitter#html_meta
        // https://developer.twitter.com/en/docs/twitter-for-websites/cards/overview/markup
        // https://cards-dev.twitter.com/validator


        $twitterMeta = array(
            self::META_CARD => "summary",
            self::META_TITLE => StringUtility::truncateString($page->getTitleNotEmpty(), 70),
            self::META_CREATOR => self::COMBO_STRAP_TWITTER_HANDLE,
            self::META_CREATOR_ID => self::COMBO_STRAP_TWITTER_ID
        );
        $description = $page->getDescriptionOrElseDokuWiki();
        if (!empty($description)){
            // happens in test with document without content
            $twitterMeta[self::META_DESCRIPTION] = StringUtility::truncateString($description, 200);
        }


        /**
         * Twitter site
         */
        $siteTwitterHandle = PluginUtility::getConfValue(self::CONF_TWITTER_SITE_HANDLE);
        $siteTwitterId = PluginUtility::getConfValue(self::CONF_TWITTER_SITE_ID);
        if (!empty($siteTwitterHandle)) {
            $twitterMeta[self::META_SITE] = $siteTwitterHandle;

            // Identify the Twitter profile of the page that populates the via property
            // https://developer.twitter.com/en/docs/twitter-for-websites/webpage-properties
            $name = str_replace("@","",$siteTwitterHandle);
            $event->data['link'][] = array("rel" => "me", "href" => "https://twitter.com/$name");
        }
        if (!empty($siteTwitterId)) {
            $twitterMeta[self::META_SITE_ID] = $siteTwitterId;
        }

        /**
         * Card image
         */
        $twitterImages = $page->getLocalImageSet();
        if (empty($twitterImages)) {
            $defaultImageIdConf = cleanID(PluginUtility::getConfValue(self::CONF_DEFAULT_TWITTER_IMAGE));
            if (!empty($defaultImageIdConf)) {
                $twitterImage = MediaLink::createMediaLinkFromNonQualifiedPath($defaultImageIdConf);
                if ($twitterImage->exists()) {
                    $twitterImages[] = $twitterImage;
                } else {
                    if ($defaultImageIdConf != "apple-touch-icon.png") {
                        LogUtility::msg("The default twitter image ($defaultImageIdConf) does not exist", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
                    }
                }
            }

        }
        if (!empty($twitterImages)) {
            foreach ($twitterImages as $twitterImage) {
                if ($twitterImage->exists()) {
                    $twitterMeta[self::META_IMAGE] = $twitterImage->getAbsoluteUrl();
                    $title = $twitterImage->getTagAttributes()->getComponentAttributeValue(TagAttributes::TITLE_KEY);
                    if (!empty($title)) {
                        $twitterMeta[self::META_IMAGE_ALT] = $title;
                    }
                    // One image only
                    break;
                }
            }
        }

        /**
         * https://developer.twitter.com/en/docs/twitter-for-websites/webpage-properties
         */
        // don't track
        $twitterMeta[self::META_DNT]=PluginUtility::getConfValue(self::CONF_TWITTER_DONT_NOT_TRACK);
        // turn off csp warning
        $twitterMeta[self::META_WIDGET_CSP]="on";

        /**
         * Embedded Tweet Theme
         */

        $twitterMeta[self::META_WIDGETS_THEME]=PluginUtility::getConfValue(syntax_plugin_combo_blockquote::CONF_TWEET_WIDGETS_THEME);
        $twitterMeta[self::META_WIDGETS_BORDER_COLOR]=PluginUtility::getConfValue(syntax_plugin_combo_blockquote::CONF_TWEET_WIDGETS_BORDER);

        /**
         * Add the properties
         */
        foreach ($twitterMeta as $key => $content) {
            $event->data['meta'][] = array("name" => $key, "content" => $content);
        }



    }

}
