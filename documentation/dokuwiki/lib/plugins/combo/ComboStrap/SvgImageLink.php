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

require_once(__DIR__ . '/MediaLink.php');
require_once(__DIR__ . '/PluginUtility.php');
require_once(__DIR__ . '/SvgDocument.php');

/**
 * Image
 * This is the class that handles the
 * svg link type
 */
class SvgImageLink extends MediaLink
{

    const CANONICAL = "svg";

    /**
     * The maximum size to be embedded
     * Above this size limit they are fetched
     */
    const CONF_MAX_KB_SIZE_FOR_INLINE_SVG = "svgMaxInlineSizeKb";

    /**
     * Lazy Load
     */
    const CONF_LAZY_LOAD_ENABLE = "svgLazyLoadEnable";

    /**
     * Svg Injection
     */
    const CONF_SVG_INJECTION_ENABLE = "svgInjectionEnable";

    /**
     * @var SvgDocument
     */
    private $svgDocument;

    /**
     * SvgImageLink constructor.
     * @param $ref
     * @param TagAttributes $tagAttributes
     * @param string $rev
     */
    public function __construct($ref, $tagAttributes = null, $rev = '')
    {
        parent::__construct($ref, $tagAttributes, $rev);
        $this->getTagAttributes()->setLogicalTag(self::CANONICAL);
    }


    private function createImgHTMLTag()
    {


        $lazyLoad = $this->getLazyLoad();

        $svgInjection = PluginUtility::getConfValue(self::CONF_SVG_INJECTION_ENABLE, 1);
        /**
         * Snippet
         */
        if ($svgInjection) {
            $snippetManager = PluginUtility::getSnippetManager();

            // Based on https://github.com/iconic/SVGInjector/
            // See also: https://github.com/iconfu/svg-inject
            // !! There is a fork: https://github.com/tanem/svg-injector !!
            // Fallback ? : https://github.com/iconic/SVGInjector/#per-element-png-fallback
            $snippetManager->upsertTagsForBar("svg-injector",
                array(
                    'script' => [
                        array(
                            "src" => "https://cdn.jsdelivr.net/npm/svg-injector@1.1.3/svg-injector.min.js",
                           // "integrity" => "sha256-CjBlJvxqLCU2HMzFunTelZLFHCJdqgDoHi/qGJWdRJk=",
                            "crossorigin" => "anonymous"
                        )
                    ]
                )
            );
        }

        // Add lazy load snippet
        if ($lazyLoad) {
            LazyLoad::addLozadSnippet();
        }

        /**
         * Remove the cache attribute
         * (no cache for the img tag)
         */
        $this->tagAttributes->removeComponentAttributeIfPresent(CacheMedia::CACHE_KEY);

        /**
         * Remove linking (not yet implemented)
         */
        $this->tagAttributes->removeComponentAttributeIfPresent(MediaLink::LINKING_KEY);


        /**
         * Src
         */
        $srcValue = $this->getUrl();
        if ($lazyLoad) {

            /**
             * Note: Responsive image srcset is not needed for svg
             */
            $this->tagAttributes->addHtmlAttributeValue("data-src", $srcValue);
            $this->tagAttributes->addHtmlAttributeValue("src", LazyLoad::getPlaceholder($this->getImgTagWidthValue(), $this->getImgTagHeightValue()));

        } else {

            $this->tagAttributes->addHtmlAttributeValue("src", $srcValue);

        }

        /**
         * Adaptive Image
         * It adds a `height: auto` that avoid a layout shift when
         * using the img tag
         */
        $this->tagAttributes->addClassName(RasterImageLink::RESPONSIVE_CLASS);


        /**
         * Title
         */
        if (!empty($this->getTitle())) {
            $this->tagAttributes->addHtmlAttributeValue("alt", $this->getTitle());
        }


        /**
         * Class management
         *
         * functionalClass is the class used in Javascript
         * that should be in the class attribute
         * When injected, the other class should come in a `data-class` attribute
         */
        $svgFunctionalClass = "";
        if ($svgInjection && $lazyLoad) {
            PluginUtility::getSnippetManager()->attachJavascriptSnippetForBar("lozad-svg-injection");
            $svgFunctionalClass = "lazy-svg-injection-combo";
        } else if ($lazyLoad && !$svgInjection) {
            PluginUtility::getSnippetManager()->attachJavascriptSnippetForBar("lozad-svg");
            $svgFunctionalClass = "lazy-svg-combo";
        } else if ($svgInjection && !$lazyLoad) {
            PluginUtility::getSnippetManager()->attachJavascriptSnippetForBar("svg-injector");
            $svgFunctionalClass = "svg-injection-combo";
        }
        if ($lazyLoad) {
            // A class to all component lazy loaded to download them before print
            $svgFunctionalClass .= " " . LazyLoad::LAZY_CLASS;
        }
        $this->tagAttributes->addClassName($svgFunctionalClass);

        /**
         * Dimension are mandatory
         * to avoid layout shift (CLS)
         */
        $this->tagAttributes->addHtmlAttributeValue(Dimension::WIDTH_KEY, $this->getImgTagWidthValue());
        $this->tagAttributes->addHtmlAttributeValue(Dimension::HEIGHT_KEY, $this->getImgTagHeightValue());


        /**
         * Return the image
         */
        return '<img ' . $this->tagAttributes->toHTMLAttributeString() . '/>';

    }


    public function getAbsoluteUrl()
    {

        return $this->getUrl();

    }

    /**
     * @param string $ampersand $absolute - the & separator (should be encoded for HTML but not for CSS)
     * @return string|null
     *
     * At contrary to {@link RasterImageLink::getUrl()} this function does not need any width parameter
     */
    public function getUrl($ampersand = DokuwikiUrl::URL_ENCODED_AND)
    {

        if ($this->exists()) {

            /**
             * We remove align and linking because,
             * they should apply only to the img tag
             */


            /**
             *
             * Create the array $att that will cary the query
             * parameter for the URL
             */
            $att = array();
            $componentAttributes = $this->tagAttributes->getComponentAttributes();
            foreach ($componentAttributes as $name => $value) {

                if (!in_array(strtolower($name), MediaLink::NON_URL_ATTRIBUTES)) {
                    $newName = $name;

                    /**
                     * Width and Height
                     * permits to create SVG of the asked size
                     *
                     * This is a little bit redundant with the
                     * {@link Dimension::processWidthAndHeight()}
                     * `max-width and width` styling property
                     * but you may use them outside of HTML.
                     */
                    switch ($name) {
                        case Dimension::WIDTH_KEY:
                            $newName = "w";
                            /**
                             * We don't remove width because,
                             * the sizing should apply to img
                             */
                            break;
                        case Dimension::HEIGHT_KEY:
                            $newName = "h";
                            /**
                             * We don't remove height because,
                             * the sizing should apply to img
                             */
                            break;
                    }

                    if ($newName == CacheMedia::CACHE_KEY && $value == CacheMedia::CACHE_DEFAULT_VALUE) {
                        // This is the default
                        // No need to add it
                        continue;
                    }

                    if (!empty($value)) {
                        $att[$newName] = trim($value);
                    }
                }

            }

            /**
             * Cache bursting
             */
            if (!$this->tagAttributes->hasComponentAttribute(CacheMedia::CACHE_BUSTER_KEY)) {
                $att[CacheMedia::CACHE_BUSTER_KEY] = $this->getModifiedTime();
            }

            $direct = true;
            return ml($this->getId(), $att, $direct, $ampersand, true);

        } else {

            return null;

        }
    }

    /**
     * Render a link
     * Snippet derived from {@link \Doku_Renderer_xhtml::internalmedia()}
     * A media can be a video also
     * @return string
     */
    public function renderMediaTag()
    {

        if ($this->exists()) {

            /**
             * This attributes should not be in the render
             */
            $this->tagAttributes->removeComponentAttributeIfPresent(MediaLink::MEDIA_DOKUWIKI_TYPE);
            $this->tagAttributes->removeComponentAttributeIfPresent(MediaLink::DOKUWIKI_SRC);
            /**
             * TODO: Title should be a node just below SVG
             */
            $this->tagAttributes->removeComponentAttributeIfPresent(Page::TITLE_META_PROPERTY);

            if (
                $this->getSize() > $this->getMaxInlineSize()
            ) {

                /**
                 * Img tag
                 */
                $imgHTML = $this->createImgHTMLTag();

            } else {

                /**
                 * Svg tag
                 */
                $imgHTML = file_get_contents($this->getSvgFile());

            }


        } else {

            $imgHTML = "<span class=\"text-danger\">The svg ($this) does not exist</span>";

        }
        return $imgHTML;
    }

    private function getMaxInlineSize()
    {
        return PluginUtility::getConfValue(self::CONF_MAX_KB_SIZE_FOR_INLINE_SVG, 2) * 1024;
    }


    public function getLazyLoad()
    {
        $lazyLoad = parent::getLazyLoad();
        if ($lazyLoad !== null) {
            return $lazyLoad;
        } else {
            return PluginUtility::getConfValue(SvgImageLink::CONF_LAZY_LOAD_ENABLE);
        }
    }


    public function getSvgFile()
    {

        $cache = new CacheMedia($this, $this->tagAttributes);
        if (!$cache->isCacheUsable()) {
            $content = $this->getSvgDocument()->getXmlText($this->tagAttributes);
            $cache->storeCache($content);
        }
        return $cache->getFile()->getFileSystemPath();

    }

    public function getMediaWidth()
    {
        return $this->getSvgDocument()->getMediaWidth();
    }

    public function getMediaHeight()
    {
        return $this->getSvgDocument()->getMediaHeight();
    }

    private function getSvgDocument()
    {
        if ($this->svgDocument == null) {
            $this->svgDocument = SvgDocument::createFromPath($this);
        }
        return $this->svgDocument;
    }
}
