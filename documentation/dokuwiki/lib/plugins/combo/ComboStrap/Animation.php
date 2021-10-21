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
 * Class Animation
 * Manage the animation
 * @package ComboStrap
 */
class Animation
{

    const ON_VIEW_ATTRIBUTE = "onview";
    const ON_VIEW_SNIPPET_ID = "onview";
    const ANIMATE_CLASS = "animate__animated";

    /**
     * Based on https://wowjs.uk/
     * @param TagAttributes $attributes
     */
    public static function processOnView(&$attributes)
    {
        if ($attributes->hasComponentAttribute(self::ON_VIEW_ATTRIBUTE)) {
            $onView = $attributes->getValueAndRemove(self::ON_VIEW_ATTRIBUTE);

            $animateClass = self::ANIMATE_CLASS;
            $attributes->addClassName($animateClass);

            $animationClass = "animate__" . $onView;
            $attributes->addHtmlAttributeValue("data-animated-class", $animationClass);

            // TODO: Add attributes
            //$delay = "animate__delay-2s";
            //PluginUtility::addClass2Attributes($delay, $attributes);

            $snippetManager = PluginUtility::getSnippetManager();

            self::scrollMagicInit();

            $snippetManager->attachTagsForBar(self::ON_VIEW_SNIPPET_ID)
                ->setCritical(false)
                ->setTags(
                    array(
                        "link" =>
                            [
                                array(
                                    "rel" => "stylesheet",
                                    "href" => "https://cdn.jsdelivr.net/npm/animate.css@4.1.1/animate.min.css",
                                    "integrity" => "sha256-X7rrn44l1+AUO65h1LGALBbOc5C5bOstSYsNlv9MhT8=",
                                    "crossorigin" => "anonymous"
                                )
                            ]
                    ));

        }

    }

    /**
     * https://www.delac.io/wow/docs.html
     * Offset: Define the distance between the bottom of browser viewport and the top of hidden box.
     *         When the user scrolls and reach this distance the hidden box is revealed.
     * Live  : Constantly check for new WOW elements on the page.
     * @param $attributes
     * @deprecated - wow permits only one trigger by animation
     */
    private static function wowInit(&$attributes)
    {
        $snippetManager = PluginUtility::getSnippetManager();
        $wowClass = "wow";
        $wowSnippetId = "wow";
        PluginUtility::addClass2Attributes($wowClass, $attributes);
        $snippetManager->upsertCssSnippetForBar($wowSnippetId);


        $animateClass = self::ANIMATE_CLASS;
        $js = <<<EOF
window.addEventListener("load", function(event) {
    var wow = new WOW(
      {
        boxClass:     '$wowClass',      // animated element css class (default is wow)
        animateClass: '$animateClass', // animation css class (default is animated)
        offset:       0,          // distance to the element when triggering the animation (default is 0)
        mobile:       true,       // trigger animations on mobile devices (default is true)
        live:         false,       // act on asynchronously loaded content (default is true)
        callback:     function(box) {
          // the callback is fired every time an animation is started
          // the argument that is passed in is the DOM node being animated
        },
        scrollContainer: null // optional scroll container selector, otherwise use window
      }
    );
    wow.init();
});
EOF;
        $snippetManager->attachJavascriptSnippetForBar($wowSnippetId)->setContent($js);
        $snippetManager->attachTagsForBar($wowSnippetId)->setTags(
            array(
                "script" =>
                    [
                        array(
                            "src" => "https://cdn.jsdelivr.net/npm/wowjs@1.1.3/dist/wow.min.js",
                            "integrity" => "sha256-gHiUEskgBO+3ccSDRM+c5+nEwTGp64R99KYPfITpnuo=",
                            "crossorigin" => "anonymous"
                        )
                    ],

            )
        );
    }

    /**
     * https://scrollmagic.io/docs/index.html
     */
    private static function scrollMagicInit()
    {
        $snippetManager = PluginUtility::getSnippetManager();

        $scrollMagicSnippetId = "scroll-magic";
        $snippetManager->attachJavascriptSnippetForBar($scrollMagicSnippetId);
        $snippetManager->upsertTagsForBar($scrollMagicSnippetId,
            array(
                "script" =>
                    [
                        array(
                            "src" => "https://cdnjs.cloudflare.com/ajax/libs/ScrollMagic/2.0.8/ScrollMagic.min.js",
                            "integrity" => "sha512-8E3KZoPoZCD+1dgfqhPbejQBnQfBXe8FuwL4z/c8sTrgeDMFEnoyTlH3obB4/fV+6Sg0a0XF+L/6xS4Xx1fUEg==",
                            "crossorigin" => "anonymous"
                        ),
                        array(
                            "src" => "https://cdnjs.cloudflare.com/ajax/libs/ScrollMagic/2.0.8/plugins/debug.addIndicators.min.js",
                            "integrity" => "sha512-RvUydNGlqYJapy0t4AH8hDv/It+zKsv4wOQGb+iOnEfa6NnF2fzjXgRy+FDjSpMfC3sjokNUzsfYZaZ8QAwIxg==",
                            "crossorigin" => "anonymous"
                        )
                    ],

            ));
    }

}
