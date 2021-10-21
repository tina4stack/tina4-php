<?php


namespace ComboStrap;


/**
 * Process background attribute
 */
class Background
{
    /**
     * Background Logical attribute / Public
     */
    const BACKGROUND_COLOR = 'background-color';
    const BACKGROUND_FILL = "fill";


    /**
     * CSS attribute / not public
     */
    const BACKGROUND_IMAGE = 'background-image';
    const BACKGROUND_SIZE = "background-size";
    const BACKGROUND_REPEAT = "background-repeat";
    const BACKGROUND_POSITION = "background-position";


    /**
     * The page canonical of the documentation
     */
    const CANONICAL = "background";
    /**
     * A component attributes to store backgrounds
     */
    const BACKGROUNDS = "backgrounds";

    /**
     * Pattern css
     * https://bansal.io/pattern-css
     */
    const PATTERN_ATTRIBUTE = "pattern";
    const PATTERN_CSS_SNIPPET_ID = "pattern.css";
    const PATTERN_CSS_SIZE = ["sm", "md", "lg", "xl"];
    const PATTERN_NAMES = ['checks', 'grid', 'dots', 'cross-dots', 'diagonal-lines', 'horizontal-lines', 'vertical-lines', 'diagonal-stripes', 'horizontal-stripes', 'vertical-stripes', 'triangles', 'zigzag'];
    const PATTERN_CSS_CLASS_PREFIX = "pattern";
    const PATTERN_COLOR_ATTRIBUTE = "pattern-color";


    public static function processBackgroundAttributes(TagAttributes &$tagAttributes)
    {

        /**
         * Backgrounds set with the {@link \syntax_plugin_combo_background} component
         */
        if ($tagAttributes->hasComponentAttribute(self::BACKGROUNDS)) {
            PluginUtility::getSnippetManager()->attachCssSnippetForBar(self::CANONICAL);
            $backgrounds = $tagAttributes->getValueAndRemove(self::BACKGROUNDS);
            switch (sizeof($backgrounds)) {
                case 1:
                    // Only one background was specified
                    $background = $backgrounds[0];
                    if (
                        /**
                         * We need to create a background node
                         * if we transform or
                         * use a CSS pattern (because it use the text color as one of painting color)
                         */
                        !isset($background[TagAttributes::TRANSFORM]) &&
                        !isset($background[self::PATTERN_ATTRIBUTE])
                    ) {
                        /**
                         * For readability,
                         * we put the background on the parent node
                         * because there is only one background
                         */
                        $tagAttributes->addComponentAttributeValueIfNotEmpty(self::BACKGROUND_IMAGE, $background[self::BACKGROUND_IMAGE]);
                        $tagAttributes->addComponentAttributeValueIfNotEmpty(self::BACKGROUND_COLOR, $background[self::BACKGROUND_COLOR]);
                        $tagAttributes->addComponentAttributeValueIfNotEmpty(Opacity::OPACITY_ATTRIBUTE, $background[Opacity::OPACITY_ATTRIBUTE]);
                        $tagAttributes->addComponentAttributeValueIfNotEmpty(self::BACKGROUND_POSITION, $background[self::BACKGROUND_POSITION]);
                        $tagAttributes->addComponentAttributeValueIfNotEmpty(self::BACKGROUND_FILL, $background[self::BACKGROUND_FILL]);
                    } else {
                        $backgroundTagAttribute = TagAttributes::createFromCallStackArray($background);
                        $backgroundTagAttribute->addClassName(self::CANONICAL);
                        $backgroundHTML = "<div class=\"backgrounds\">" . DOKU_LF .
                            $backgroundTagAttribute->toHtmlEnterTag("div") .
                            "</div>" . DOKU_LF .
                            "</div>";
                        $tagAttributes->addHtmlAfterEnterTag($backgroundHTML);
                    }
                    break;
                default:
                    /**
                     * More than one background
                     * This backgrounds should have been set on the backgrounds
                     */
                    $backgroundHTML = "";
                    foreach ($backgrounds as $background) {
                        $backgroundTagAttribute = TagAttributes::createFromCallStackArray($background);
                        $backgroundTagAttribute->addClassName(self::CANONICAL);
                        $backgroundHTMLEnter = $backgroundTagAttribute->toHtmlEnterTag("div");
                        $backgroundHTML .= $backgroundHTMLEnter . "</div>";
                    }
                    $tagAttributes->addHtmlAfterEnterTag($backgroundHTML);
                    break;
            }
        }

        /**
         * Background-image attribute
         */
        $backgroundImageStyleValue = "";
        if ($tagAttributes->hasComponentAttribute(self::BACKGROUND_IMAGE)) {
            $backgroundImageValue = $tagAttributes->getValueAndRemove(self::BACKGROUND_IMAGE);
            if (is_string($backgroundImageValue)) {
                /**
                 * Image background is set by the user
                 */
                $backgroundImageStyleValue = $tagAttributes->getValueAndRemove(self::BACKGROUND_IMAGE);

            } else {

                if (is_array($backgroundImageValue)) {

                    /**
                     * Background-fill for background image
                     */
                    $backgroundFill = $tagAttributes->getValueAndRemove(self::BACKGROUND_FILL, "cover");
                    switch ($backgroundFill) {
                        case "cover":
                            // it makes the background responsive
                            $tagAttributes->addStyleDeclaration(self::BACKGROUND_SIZE, $backgroundFill);
                            $tagAttributes->addStyleDeclaration(self::BACKGROUND_REPEAT, "no-repeat");
                            $tagAttributes->addStyleDeclaration(self::BACKGROUND_POSITION, "center center");

                            /**
                             * The type of image is important for the processing of SVG
                             */
                            $backgroundImageValue[TagAttributes::TYPE_KEY] = SvgDocument::ILLUSTRATION_TYPE;
                            break;
                        case "tile":
                            // background size is then "auto" (ie repeat), the default
                            // background position is not needed (the tile start on the left top corner)
                            $tagAttributes->addStyleDeclaration(self::BACKGROUND_REPEAT, "repeat");

                            /**
                             * The type of image is important for the processing of SVG
                             * A tile needs to have a width and a height
                             */
                            $backgroundImageValue[TagAttributes::TYPE_KEY] = SvgDocument::TILE_TYPE;
                            break;
                        case "css":
                            // custom, set by the user in own css stylesheet, nothing to do
                            break;
                        default:
                            LogUtility::msg("The background `fill` attribute ($backgroundFill) is unknown. If you want to take over the filling via css, set the `fill` value to `css`.", self::CANONICAL);
                            break;
                    }

                    $media = MediaLink::createFromCallStackArray($backgroundImageValue);
                    $url = $media->getUrl(DokuwikiUrl::URL_AND, $media->getRequestedWidth());
                    if ($url !== false) {

                        $backgroundImageStyleValue = "url(" . $url . ")";


                    } else {
                        LogUtility::msg("The image ($media) does not exist", LogUtility::LVL_MSG_WARNING, self::CANONICAL);
                    }
                } else {
                    LogUtility::msg("Internal Error: The background image value ($backgroundImageValue) is not a string nor an array", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
                }

            }
        }
        if (!empty($backgroundImageStyleValue)) {
            if ($tagAttributes->hasComponentAttribute(Opacity::OPACITY_ATTRIBUTE)) {
                $opacity = $tagAttributes->getValueAndRemove(Opacity::OPACITY_ATTRIBUTE);
                $finalOpacity = 1 - $opacity;
                $backgroundImageStyleValue = "linear-gradient(to right, rgba(255,255,255, $finalOpacity) 0 100%)," . $backgroundImageStyleValue;
            }
            $tagAttributes->addStyleDeclaration(self::BACKGROUND_IMAGE, $backgroundImageStyleValue);
        }


        /**
         * Process the pattern css
         * https://bansal.io/pattern-css
         * This call should be before the processing of the background color
         * because it will set one if it's not available
         */
        self::processPatternAttribute($tagAttributes);

        /**
         * Background color
         */
        if ($tagAttributes->hasComponentAttribute(self::BACKGROUND_COLOR)) {

            $colorValue = $tagAttributes->getValueAndRemove(self::BACKGROUND_COLOR);
            $colorValue = ColorUtility::getColorValue($colorValue);
            $gradientPrefix = 'gradient-';
            if (strpos($colorValue, $gradientPrefix) === 0) {
                /**
                 * A gradient is an image
                 * Check that there is no image
                 */
                if (!empty($backgroundImageStyleValue)) {
                    LogUtility::msg("An image and a linear gradient color are exclusive because a linear gradient color creates an image. You can't use the linear color (" . $colorValue . ") and the image (" . $backgroundImageStyleValue . ")", LogUtility::LVL_MSG_WARNING, self::CANONICAL);
                } else {
                    $mainColorValue = substr($colorValue, strlen($gradientPrefix));
                    $tagAttributes->addStyleDeclaration(self::BACKGROUND_IMAGE, 'linear-gradient(to top,#fff 0,' . ColorUtility::getColorValue($mainColorValue) . ' 100%)');
                    $tagAttributes->addStyleDeclaration(self::BACKGROUND_COLOR, 'unset!important');
                }
            } else {
                $tagAttributes->addStyleDeclaration(self::BACKGROUND_COLOR, $colorValue);
            }
        }


    }

    /**
     * Return a background array with background properties
     * from a media {@link MediaLink::toCallStackArray()}
     * @param array $mediaCallStackArray
     * @return array
     */
    public static function fromMediaToBackgroundImageStackArray(array $mediaCallStackArray)
    {
        $backgroundProperties = [];
        foreach ($mediaCallStackArray as $key => $property) {
            switch ($key) {
                case MediaLink::LINKING_KEY:
                case TagAttributes::TITLE_KEY:
                case MediaLink::ALIGN_KEY:
                case FloatAttribute::FLOAT_KEY: // Float is when the image is at the right
                    /**
                     * Attributes not taken
                     */
                    break;
                case TagAttributes::TYPE_KEY: // needed for the metadata registration
                default:
                    /**
                     * Attributes taken
                     */
                    $backgroundProperties[$key] = $property;
                    break;
            }
        }
        return $backgroundProperties;
    }

    /**
     * @param TagAttributes $tagAttributes
     * Process the `pattern` attribute
     * that implements
     * https://bansal.io/pattern-css
     */
    private static function processPatternAttribute(TagAttributes &$tagAttributes)
    {
        /**
         * Css Pattern
         */
        if ($tagAttributes->hasComponentAttribute(self::PATTERN_ATTRIBUTE)) {

            /**
             * Attach the stylesheet
             */
            PluginUtility::getSnippetManager()->attachTagsForBar(self::PATTERN_CSS_SNIPPET_ID)
                ->setCritical(false) // not blocking for rendering
                ->setTags(
                    array(
                        "link" =>
                            [
                                array(
                                    "rel" => "stylesheet",
                                    "href" => "https://cdn.jsdelivr.net/npm/pattern.css@1.0.0/dist/pattern.min.css",
                                    "integrity" => "sha256-Vwich3JPJa27TO9g6q+TxJGE7DNEigBaHNPm+KkMR6o=",
                                    "crossorigin" => "anonymous"
                                )
                            ]
                    ));

            $patternValue = strtolower($tagAttributes->getValueAndRemove(self::PATTERN_ATTRIBUTE));

            $lastIndexOfMinus = StringUtility::lastIndexOf($patternValue, "-");
            $lastMinusPart = substr($patternValue, $lastIndexOfMinus);
            /**
             * Do we have the size as last part
             */
            if (!in_array($lastMinusPart, self::PATTERN_CSS_SIZE)) {
                /**
                 * We don't have a size
                 */
                $pattern = $patternValue;
                $size = "md";
            } else {
                $pattern = substr($patternValue, 0, $lastIndexOfMinus);
                $size = $lastMinusPart;
            }
            /**
             * Does this pattern is a known pattern
             */
            if (!in_array($pattern, self::PATTERN_NAMES)) {
                LogUtility::msg("The pattern (" . $pattern . ") is not a known CSS pattern and was ignored.", LogUtility::LVL_MSG_WARNING, self::CANONICAL);
                return;
            } else {
                $tagAttributes->addClassName(self::PATTERN_CSS_CLASS_PREFIX . "-" . $pattern . "-" . $size);
            }

            if (!$tagAttributes->hasComponentAttribute(self::BACKGROUND_COLOR)) {
                LogUtility::msg("The background color was not set for the background with the (" . $pattern . "). It was set to the default color.", LogUtility::LVL_MSG_INFO, self::CANONICAL);
                $tagAttributes->addComponentAttributeValue(self::BACKGROUND_COLOR, "steelblue");
            }
            /**
             * Color
             */
            if ($tagAttributes->hasComponentAttribute(self::PATTERN_COLOR_ATTRIBUTE)) {
                $patternColor = $tagAttributes->getValueAndRemove(self::PATTERN_COLOR_ATTRIBUTE);
            } else {
                LogUtility::msg("The pattern color was not set for the background with the (" . $pattern . "). It was set to the default color.", LogUtility::LVL_MSG_INFO, self::CANONICAL);
                $patternColor = "#FDE482";
            }
            $tagAttributes->addStyleDeclaration(ColorUtility::COLOR, $patternColor);

        }
    }


}
