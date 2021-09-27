<?php


namespace ComboStrap;


use dokuwiki\Cache\Cache;

class Lang
{

    const CANONICAL = "lang";
    const LANG_ATTRIBUTES = "lang";

    /**
     * Process the lang attribute
     * https://www.w3.org/International/questions/qa-html-language-declarations
     * @param TagAttributes $attributes
     *
     * Language supported:
     * http://www.iana.org/assignments/language-subtag-registry/language-subtag-registry
     *
     * Common Locale Data Repository
     * http://cldr.unicode.org/
     * Data:
     *   * http://www.unicode.org/Public/cldr/
     *   * https://github.com/unicode-cldr/
     *   * https://github.com/unicode-org/cldr-json
     * The ''dir'' data is known as the ''characterOrder''
     *
     */
    public static function processLangAttribute(&$attributes)
    {


        /**
         * Adding the lang attribute
         * if set
         */
        if ($attributes->hasComponentAttribute(self::LANG_ATTRIBUTES)) {
            $langValue = $attributes->getValueAndRemove(self::LANG_ATTRIBUTES);
            $attributes->addHtmlAttributeValue("lang", $langValue);

            $languageDataCache = new Cache("combo_" . $langValue, ".json");
            $cacheDataUsable = $languageDataCache->useCache();
            if (!$cacheDataUsable) {

                // Language about the data
                $downloadUrl = "https://raw.githubusercontent.com/unicode-org/cldr-json/master/cldr-json/cldr-misc-modern/main/$langValue/layout.json";

                $filePointer = @fopen($downloadUrl, 'r');
                if ($filePointer != false) {

                    $numberOfByte = @file_put_contents($languageDataCache->cache, $filePointer);
                    if ($numberOfByte != false) {
                        LogUtility::msg("The new language data ($langValue) was downloaded", LogUtility::LVL_MSG_INFO, self::CANONICAL);
                        $cacheDataUsable = true;
                    } else {
                        LogUtility::msg("Internal error: The language data ($langValue) could no be written to ($languageDataCache->cache)", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
                    }

                } else {

                    LogUtility::msg("The data for the language ($langValue) could not be found at ($downloadUrl).", LogUtility::LVL_MSG_ERROR, self::CANONICAL);

                }
            }

            if ($cacheDataUsable) {
                $jsonAsArray = true;
                $languageData = json_decode(file_get_contents($languageDataCache->cache), $jsonAsArray);
                if($languageData==null){
                    LogUtility::msg("We could not read the data from the language ($langValue). No direction was set.", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
                    return;
                }
                $characterOrder = $languageData["main"][$langValue]["layout"]["orientation"]["characterOrder"];
                if ($characterOrder == "right-to-left") {
                    $attributes->addHtmlAttributeValue("dir", "rtl");
                } else {
                    $attributes->addHtmlAttributeValue("dir", "ltr");
                }
            } else {
                LogUtility::msg("The language direction cannot be set because no language data was found for the language ($langValue)", LogUtility::LVL_MSG_WARNING, self::CANONICAL);
            }

        }

    }

}
