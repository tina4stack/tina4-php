<?php


namespace ComboStrap;


class Hover
{
    /**
     * Smooth animation
     * on hover animation
     */
    const COMBO_HOVER_EASING_CLASS = "combo-hover-easing";
    /**
     * Supported Animation name of hover
     * float and grow are not in
     */
    const HOVER_ANIMATIONS = ["shrink", "pulse", "pulse-grow", "pulse-shrink", "push", "pop", "bounce-in", "bounce-out", "rotate", "grow-rotate", "sink", "bob", "hang", "skew", "skew-forward", "skew-backward", "wobble-horizontal", "wobble-vertical", "wobble-to-bottom-right", "wobble-to-top-right", "wobble-top", "wobble-bottom", "wobble-skew", "buzz", "buzz-out", "forward", "backward", "fade", "back-pulse", "sweep-to-right", "sweep-to-left", "sweep-to-bottom", "sweep-to-top", "bounce-to-right", "bounce-to-left", "bounce-to-bottom", "bounce-to-top", "radial-out", "radial-in", "rectangle-in", "rectangle-out", "shutter-in-horizontal", "shutter-out-horizontal", "shutter-in-vertical", "shutter-out-vertical", "icon-back", "hollow", "trim", "ripple-out", "ripple-in", "outline-out", "outline-in", "round-corners", "underline-from-left", "underline-from-center", "underline-from-right", "reveal", "underline-reveal", "overline-reveal", "overline-from-left", "overline-from-center", "overline-from-right", "grow-shadow", "float-shadow", "glow", "shadow-radial", "box-shadow-outset", "box-shadow-inset", "bubble-top", "bubble-right", "bubble-bottom", "bubble-left", "bubble-float-top", "bubble-float-right", "bubble-float-bottom", "bubble-float-left", "curl-top-left", "curl-top-right", "curl-bottom-right", "curl-bottom-left"];
    const ON_HOVER_SNIPPET_ID = "onhover";
    const ON_HOVER_ATTRIBUTE = "onhover";

    /**
     * Process hover animation
     * @param TagAttributes $attributes
     */
    public static function processOnHover(&$attributes)
    {
        if ($attributes->hasComponentAttribute(self::ON_HOVER_ATTRIBUTE)) {
            $hover = strtolower($attributes->getValueAndRemove(self::ON_HOVER_ATTRIBUTE));
            $hoverAnimations = preg_split("/\s/", $hover);

            $comboDataHoverClasses = "";
            $snippetManager = PluginUtility::getSnippetManager();
            foreach ($hoverAnimations as $hover) {

                if (in_array($hover, self::HOVER_ANIMATIONS)) {
                    $snippetManager->attachTagsForBar(self::ON_HOVER_SNIPPET_ID)
                        ->setCritical(false)
                        ->setTags(
                            array("link" =>
                                [
                                    array(
                                        "rel" => "stylesheet",
                                        "href" => "https://cdnjs.cloudflare.com/ajax/libs/hover.css/2.3.1/css/hover-min.css",
                                        "integrity" => "sha512-csw0Ma4oXCAgd/d4nTcpoEoz4nYvvnk21a8VA2h2dzhPAvjbUIK6V3si7/g/HehwdunqqW18RwCJKpD7rL67Xg==",
                                        "crossorigin" => "anonymous"
                                    )
                                ]
                            ));
                    $attributes->addClassName("hvr-$hover");

                } else {

                    /**
                     * The combo hover effect
                     */
                    if (in_array($hover, ["float", "grow"])) {
                        $hover = "combo-" . $hover;
                    }

                    /**
                     * Shadow translation between animation name
                     * and class
                     */
                    switch ($hover) {
                        case "shadow":
                            $hover = Shadow::getDefaultClass();
                            break;
                        case "shadow-md":
                            $hover = Shadow::MEDIUM_ELEVATION_CLASS;
                            break;
                        case "shadow-lg":
                            $hover = "shadow";
                            break;
                        case "shadow-xl":
                            $hover = "shadow-lg";
                            break;
                    }

                    /**
                     * Add it to the list of class
                     */
                    $comboDataHoverClasses .= " " . $hover;

                }

            }
            if (!empty($comboDataHoverClasses)) {

                // Grow, float and easing are in the css
                $snippetManager
                    ->attachCssSnippetForBar(self::ON_HOVER_SNIPPET_ID)
                    ->setCritical(false);

                // Smooth Transition in and out of hover
                $attributes->addClassName(self::COMBO_HOVER_EASING_CLASS);

                $attributes->addHtmlAttributeValue("data-hover-class", trim($comboDataHoverClasses));

                // The javascript that manage the hover effect by adding the class in the data-hover class
                $snippetManager->attachJavascriptSnippetForBar(self::ON_HOVER_SNIPPET_ID);

            }

        }

    }
}
