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
require_once(__DIR__ . '/LazyLoad.php');
require_once(__DIR__ . '/PluginUtility.php');

/**
 * Image
 * This is the class that handles the
 * raster image type of the dokuwiki {@link MediaLink}
 *
 * The real documentation can be found on the image page
 * @link https://www.dokuwiki.org/images
 *
 * Doc:
 * https://web.dev/optimize-cls/#images-without-dimensions
 * https://web.dev/cls/
 */
class RasterImageLink extends MediaLink
{

    const CANONICAL = "raster";
    const CONF_LAZY_LOADING_ENABLE = "rasterImageLazyLoadingEnable";

    const RESPONSIVE_CLASS = "img-fluid";

    const CONF_RESPONSIVE_IMAGE_MARGIN = "responsiveImageMargin";
    const CONF_RETINA_SUPPORT_ENABLED = "retinaRasterImageEnable";
    const LAZY_CLASS = "lazy-raster-combo";

    const BREAKPOINTS =
        array(
            "xs" => 375,
            "sm" => 576,
            "md" => 768,
            "lg" => 992
        );


    private $imageWidth = null;
    /**
     * @var int
     */
    private $imageWeight = null;
    /**
     * See {@link image_type_to_mime_type}
     * @var int
     */
    private $imageType;
    private $wasAnalyzed = false;

    /**
     * @var bool
     */
    private $analyzable = false;

    /**
     * @var mixed - the mime from the {@link RasterImageLink::analyzeImageIfNeeded()}
     */
    private $mime;

    /**
     * RasterImageLink constructor.
     * @param $ref
     * @param TagAttributes $tagAttributes
     */
    public function __construct($ref, $tagAttributes = null)
    {
        parent::__construct($ref, $tagAttributes);
        $this->getTagAttributes()->setLogicalTag(self::CANONICAL);

    }


    /**
     * @param string $ampersand
     * @param null $localWidth - the asked width - use for responsive image
     * @return string|null
     */
    public function getUrl($ampersand = DokuwikiUrl::URL_ENCODED_AND, $localWidth = null)
    {

        if ($this->exists()) {

            /**
             * Link attribute
             */
            $att = array();

            // Width is driving the computation
            if ($localWidth != null && $localWidth != $this->getMediaWidth()) {

                $att['w'] = $localWidth;

                // Height
                $height = $this->getImgTagHeightValue($localWidth);
                if (!empty($height)) {
                    $att['h'] = $height;
                    $this->checkWidthAndHeightRatioAndReturnTheGoodValue($localWidth, $height);
                }


            }

            if ($this->getCache()) {
                $att[CacheMedia::CACHE_KEY] = $this->getCache();
            }
            $direct = true;

            return ml($this->getId(), $att, $direct, $ampersand, true);

        } else {

            return false;

        }
    }

    public function getAbsoluteUrl()
    {

        return $this->getUrl();

    }


    /**
     * Render a link
     * Snippet derived from {@link \Doku_Renderer_xhtml::internalmedia()}
     * A media can be a video also (Use
     * @return string
     */
    public function renderMediaTag()
    {


        if ($this->exists()) {


            /**
             * No dokuwiki type attribute
             */
            $this->tagAttributes->removeComponentAttributeIfPresent(MediaLink::MEDIA_DOKUWIKI_TYPE);
            $this->tagAttributes->removeComponentAttributeIfPresent(MediaLink::DOKUWIKI_SRC);

            /**
             * Responsive image
             * https://getbootstrap.com/docs/5.0/content/images/
             * to apply max-width: 100%; and height: auto;
             *
             * Even if the resizing is requested by height,
             * the height: auto on styling is needed to conserve the ratio
             * while scaling down the screen
             */
            $this->tagAttributes->addClassName(self::RESPONSIVE_CLASS);


            /**
             * width and height to give the dimension ratio
             * They have an effect on the space reservation
             * but not on responsive image at all
             * To allow responsive height, the height style property is set at auto
             * (ie img-fluid in bootstrap)
             */
            // The unit is not mandatory in HTML, this is expected to be CSS pixel
            // https://html.spec.whatwg.org/multipage/embedded-content-other.html#attr-dim-height
            // The HTML validator does not expect an unit otherwise it send an error
            // https://validator.w3.org/
            $htmlLengthUnit = "";

            /**
             * Height
             * The logical height that the image should take on the page
             *
             * Note: The style is also set in {@link Dimension::processWidthAndHeight()}
             *
             * The doc is {@link https://www.dokuwiki.org/images#resizing}
             * See the ''0x20''
             */
            $imgTagHeight = $this->getImgTagHeightValue();
            if (!empty($imgTagHeight)) {
                $this->tagAttributes->addHtmlAttributeValue("height", $imgTagHeight . $htmlLengthUnit);
            }


            /**
             * Width
             *
             * We create a series of URL
             * for different width and let the browser
             * download the best one for:
             *   * the actual container width
             *   * the actual of screen resolution
             *   * and the connection speed.
             *
             * The max-width value is set
             */
            $mediaWidthValue = $this->getMediaWidth();
            $srcValue = $this->getUrl();

            /**
             * Responsive image src set building
             * We have chosen
             *   * 375: Iphone6
             *   * 768: Ipad
             *   * 1024: Ipad Pro
             *
             */
            // The image margin applied
            $imageMargin = PluginUtility::getConfValue(self::CONF_RESPONSIVE_IMAGE_MARGIN, "20px");


            /**
             * Srcset and sizes for responsive image
             * Width is mandatory for responsive image
             * Ref https://developers.google.com/search/docs/advanced/guidelines/google-images#responsive-images
             */
            if (!empty($mediaWidthValue)) {

                /**
                 * The internal intrinsic value of the image
                 */
                $imgTagWidth = $this->getImgTagWidthValue();
                if (!empty($imgTagWidth)) {

                    if (!empty($imgTagHeight)) {
                        $imgTagWidth = $this->checkWidthAndHeightRatioAndReturnTheGoodValue($imgTagWidth, $imgTagHeight);
                    }
                    $this->tagAttributes->addHtmlAttributeValue("width", $imgTagWidth . $htmlLengthUnit);
                }

                /**
                 * Continue
                 */
                $srcSet = "";
                $sizes = "";

                /**
                 * Add smaller sizes
                 */
                foreach (self::BREAKPOINTS as $breakpointWidth) {

                    if ($imgTagWidth > $breakpointWidth) {

                        if (!empty($srcSet)) {
                            $srcSet .= ", ";
                            $sizes .= ", ";
                        }
                        $breakpointWidthMinusMargin = $breakpointWidth - $imageMargin;
                        $xsmUrl = $this->getUrl(DokuwikiUrl::URL_ENCODED_AND, $breakpointWidthMinusMargin);
                        $srcSet .= "$xsmUrl {$breakpointWidthMinusMargin}w";
                        $sizes .= $this->getSizes($breakpointWidth, $breakpointWidthMinusMargin);

                    }

                }

                /**
                 * Add the last size
                 * If the image is really small, srcset and sizes are empty
                 */
                if (!empty($srcSet)) {
                    $srcSet .= ", ";
                    $sizes .= ", ";
                    $srcUrl = $this->getUrl(DokuwikiUrl::URL_ENCODED_AND, $imgTagWidth);
                    $srcSet .= "$srcUrl {$imgTagWidth}w";
                    $sizes .= "{$imgTagWidth}px";
                }

                /**
                 * Lazy load
                 */
                $lazyLoad = $this->getLazyLoad();
                if ($lazyLoad) {

                    /**
                     * Snippet Lazy loading
                     */
                    LazyLoad::addLozadSnippet();
                    PluginUtility::getSnippetManager()->attachJavascriptSnippetForBar("lozad-raster");
                    $this->tagAttributes->addClassName(self::LAZY_CLASS);
                    $this->tagAttributes->addClassName(LazyLoad::LAZY_CLASS);

                    /**
                     * A small image has no srcset
                     *
                     */
                    if (!empty($srcSet)) {

                        /**
                         * !!!!! DON'T FOLLOW THIS ADVICE !!!!!!!!!
                         * https://github.com/aFarkas/lazysizes/#modern-transparent-srcset-pattern
                         * The transparent image has a fix dimension aspect ratio of 1x1 making
                         * a bad reserved space for the image
                         * We use a svg instead
                         */
                        $this->tagAttributes->addHtmlAttributeValue("src", $srcValue);
                        $this->tagAttributes->addHtmlAttributeValue("srcset", LazyLoad::getPlaceholder($imgTagWidth,$imgTagHeight));
                        /**
                         * We use `data-sizes` and not `sizes`
                         * because `sizes` without `srcset`
                         * shows the broken image symbol
                         * Javascript changes them at the same time
                         */
                        $this->tagAttributes->addHtmlAttributeValue("data-sizes", $sizes);
                        $this->tagAttributes->addHtmlAttributeValue("data-srcset", $srcSet);

                    } else {

                        /**
                         * Small image but there is no little improvement
                         */
                        $this->tagAttributes->addHtmlAttributeValue("data-src", $srcValue);

                    }

                    LazyLoad::addPlaceholderBackground($this->tagAttributes);


                } else {

                    if (!empty($srcSet)) {
                        $this->tagAttributes->addHtmlAttributeValue("srcset", $srcSet);
                        $this->tagAttributes->addHtmlAttributeValue("sizes", $sizes);
                    } else {
                        $this->tagAttributes->addHtmlAttributeValue("src", $srcValue);
                    }

                }

            } else {

                // No width, no responsive possibility
                $lazyLoad = $this->getLazyLoad();
                if ($lazyLoad) {

                    LazyLoad::addPlaceholderBackground($this->tagAttributes);
                    $this->tagAttributes->addHtmlAttributeValue("src", LazyLoad::getPlaceholder());
                    $this->tagAttributes->addHtmlAttributeValue("data-src", $srcValue);

                }

            }


            /**
             * Title (ie alt)
             */
            if ($this->tagAttributes->hasComponentAttribute(TagAttributes::TITLE_KEY)) {
                $title = $this->tagAttributes->getValueAndRemove(TagAttributes::TITLE_KEY);
                $this->tagAttributes->addHtmlAttributeValueIfNotEmpty("alt", $title);
            }

            /**
             * Create the img element
             */
            $htmlAttributes = $this->tagAttributes->toHTMLAttributeString();
            $imgHTML = '<img ' . $htmlAttributes . '/>';

        } else {

            $imgHTML = "<span class=\"text-danger\">The image ($this) does not exist</span>";

        }

        return $imgHTML;
    }

    /**
     * @return int - the width of the image from the file
     */
    public
    function getMediaWidth()
    {
        $this->analyzeImageIfNeeded();
        return $this->imageWidth;
    }

    /**
     * @return int - the height of the image from the file
     */
    public
    function getMediaHeight()
    {
        $this->analyzeImageIfNeeded();
        return $this->imageWeight;
    }

    private
    function analyzeImageIfNeeded()
    {

        if (!$this->wasAnalyzed) {

            if ($this->exists()) {

                /**
                 * Based on {@link media_image_preview_size()}
                 * $dimensions = media_image_preview_size($this->id, '', false);
                 */
                $imageInfo = array();
                $imageSize = getimagesize($this->getFileSystemPath(), $imageInfo);
                if ($imageSize === false) {
                    $this->analyzable = false;
                    LogUtility::msg("We couldn't retrieve the type and dimensions of the image ($this). The image format seems to be not supported.", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
                } else {
                    $this->analyzable = true;
                    $this->imageWidth = (int)$imageSize[0];
                    if (empty($this->imageWidth)) {
                        $this->analyzable = false;
                    }
                    $this->imageWeight = (int)$imageSize[1];
                    if (empty($this->imageWeight)) {
                        $this->analyzable = false;
                    }
                    $this->imageType = (int)$imageSize[2];
                    $this->mime = $imageSize[3];
                }
            }
        }
        $this->wasAnalyzed = true;
    }


    /**
     *
     * @return bool true if we could extract the dimensions
     */
    public
    function isAnalyzable()
    {
        $this->analyzeImageIfNeeded();
        return $this->analyzable;

    }


    public function getRequestedHeight()
    {
        $requestedHeight = parent::getRequestedHeight();
        if (!empty($requestedHeight)) {
            // it should not be bigger than the media Height
            $mediaHeight = $this->getMediaHeight();
            if (!empty($mediaHeight)) {
                if ($requestedHeight > $mediaHeight) {
                    LogUtility::msg("For the image ($this), the requested height of ($requestedHeight) can not be bigger than the intrinsic height of ($mediaHeight). The height was then set to its natural height ($mediaHeight)", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
                    $requestedHeight = $mediaHeight;
                }
            }
        }
        return $requestedHeight;
    }

    public function getRequestedWidth()
    {
        $requestedWidth = parent::getRequestedWidth();
        if (!empty($requestedWidth)) {
            // it should not be bigger than the media Height
            $mediaWidth = $this->getMediaWidth();
            if (!empty($mediaWidth)) {
                if ($requestedWidth > $mediaWidth) {
                    global $ID;
                    if ($ID != "wiki:syntax") {
                        // There is a bug in the wiki syntax page
                        // {{wiki:dokuwiki-128.png?200x50}}
                        // https://forum.dokuwiki.org/d/19313-bugtypo-how-to-make-a-request-to-change-the-syntax-page-on-dokuwikii
                        LogUtility::msg("For the image ($this), the requested width of ($requestedWidth) can not be bigger than the intrinsic width of ($mediaWidth). The width was then set to its natural width ($mediaWidth)", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
                    }
                    $requestedWidth = $mediaWidth;
                }
            }
        }
        return $requestedWidth;
    }


    public
    function getLazyLoad()
    {
        $lazyLoad = parent::getLazyLoad();
        if ($lazyLoad !== null) {
            return $lazyLoad;
        } else {
            return PluginUtility::getConfValue(RasterImageLink::CONF_LAZY_LOADING_ENABLE);
        }
    }

    /**
     * @param $screenWidth
     * @param $imageWidth
     * @return string sizes with a dpi correction if
     */
    private
    function getSizes($screenWidth, $imageWidth)
    {

        if ($this->getWithDpiCorrection()) {
            $dpiBase = 96;
            $sizes = "(max-width: {$screenWidth}px) and (min-resolution:" . (3 * $dpiBase) . "dpi) " . intval($imageWidth / 3) . "px";
            $sizes .= ", (max-width: {$screenWidth}px) and (min-resolution:" . (2 * $dpiBase) . "dpi) " . intval($imageWidth / 2) . "px";
            $sizes .= ", (max-width: {$screenWidth}px) and (min-resolution:" . (1 * $dpiBase) . "dpi) {$imageWidth}px";
        } else {
            $sizes = "(max-width: {$screenWidth}px) {$imageWidth}px";
        }
        return $sizes;
    }

    /**
     * Return if the DPI correction is enabled or not for responsive image
     *
     * Mobile have a higher DPI and can then fit a bigger image on a smaller size.
     *
     * This can be disturbing when debugging responsive sizing image
     * If you want also to use less bandwidth, this is also useful.
     *
     * @return bool
     */
    private
    function getWithDpiCorrection()
    {
        /**
         * Support for retina means no DPI correction
         */
        $retinaEnabled = PluginUtility::getConfValue(self::CONF_RETINA_SUPPORT_ENABLED, 0);
        return !$retinaEnabled;
    }


}
