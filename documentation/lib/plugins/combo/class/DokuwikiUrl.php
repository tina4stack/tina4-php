<?php


namespace ComboStrap;

/**
 * Parse a internal dokuwiki URL
 *
 * This class takes care of the
 * fact that a color can have a #
 * and of the special syntax for an image
 */
class DokuwikiUrl
{

    /**
     * In HTML (not in css)
     * Because ampersands are used to denote HTML entities,
     * if you want to use them as literal characters, you must escape them as entities,
     * e.g.  &amp;.
     *
     * This URL encoding is mandatory for the {@link ml} function
     * when there is a width and use them not otherwise
     *
     * Thus, if you want to link to:
     * http://images.google.com/images?num=30&q=larry+bird
     * you need to encode (ie pass this parameter to the {@link ml} function:
     * http://images.google.com/images?num=30&amp;q=larry+bird
     *
     * https://daringfireball.net/projects/markdown/syntax#autoescape
     *
     */
    const URL_ENCODED_AND = '&amp;';

    /**
     * Used in dokuwiki syntax & in CSS attribute
     * (Css attribute value are then HTML encoded as value of the attribute)
     */
    const URL_AND = "&";
    const ANCHOR_ATTRIBUTES = "anchor";
    /**
     * @var array
     */
    private $queryParameters;
    /**
     * @var false|string
     */
    private $pathOrId;
    /**
     * @var false|string
     */
    private $fragment;
    /**
     * @var false|string
     */
    private $queryString;

    /**
     * Url constructor.
     */
    public function __construct($url)
    {
        $this->queryParameters = [];

        /**
         * Path
         */
        $questionMarkPosition = strpos($url, "?");
        $this->pathOrId = $url;
        $queryStringAndAnchorOriginal = null;
        if ($questionMarkPosition !== false) {
            $this->pathOrId = substr($url, 0, $questionMarkPosition);
            $queryStringAndAnchorOriginal = substr($url, $questionMarkPosition + 1);
        } else {
            // We may have only an anchor
            $hashTagPosition = strpos($url, "#");
            if ($hashTagPosition !== false) {
                $this->pathOrId = substr($url, 0, $hashTagPosition);
                $this->fragment = substr($url, $hashTagPosition + 1);
            }
        }

        /**
         * Parsing Query string if any
         */
        if ($queryStringAndAnchorOriginal !== null) {

            /**
             * The value $queryStringAndAnchorOriginal
             * is kept to create the original queryString
             * at the end if we found an anchor
             */
            $queryStringAndAnchorProcessing = $queryStringAndAnchorOriginal;
            while (strlen($queryStringAndAnchorProcessing) > 0) {

                /**
                 * Capture the token
                 * and reduce the text
                 */
                $questionMarkPos = strpos($queryStringAndAnchorProcessing, "&");
                if ($questionMarkPos !== false) {
                    $token = substr($queryStringAndAnchorProcessing, 0, $questionMarkPos);
                    $queryStringAndAnchorProcessing = substr($queryStringAndAnchorProcessing, $questionMarkPos + 1);
                } else {
                    $token = $queryStringAndAnchorProcessing;
                    $queryStringAndAnchorProcessing = "";
                }


                /**
                 * Sizing (wxh)
                 */
                $sizing = [];
                if (preg_match('/^([0-9]+)(?:x([0-9]+))?/', $token, $sizing)) {
                    $this->queryParameters[Dimension::WIDTH_KEY] = $sizing[1];
                    if (isset($sizing[2])) {
                        $this->queryParameters[Dimension::HEIGHT_KEY] = $sizing[2];
                    }
                    $token = substr($token, strlen($sizing[0]));
                    if ($token == "") {
                        // no anchor behind we continue
                        continue;
                    }
                }

                /**
                 * Linking
                 */
                $found = preg_match('/^(nolink|direct|linkonly|details)/i', $token, $matches);
                if ($found) {
                    $linkingValue = $matches[1];
                    $this->queryParameters[MediaLink::LINKING_KEY] = $linkingValue;
                    $token = substr($token, strlen($linkingValue));
                    if ($token == "") {
                        // no anchor behind we continue
                        continue;
                    }
                }

                /**
                 * Cache
                 */
                $found = preg_match('/^(nocache)/i', $token, $matches);
                if ($found) {
                    $cacheValue = "nocache";
                    $this->queryParameters[CacheMedia::CACHE_KEY] = $cacheValue;
                    $token = substr($token, strlen($cacheValue));
                    if ($token == "") {
                        // no anchor behind we continue
                        continue;
                    }
                }

                /**
                 * Anchor value after a single token case
                 */
                if (strpos($token, '#') === 0) {
                    $this->fragment = substr($token, 1);
                    continue;
                }

                /**
                 * Key, value
                 * explode to the first `=`
                 * in the anchor value, we can have one
                 *
                 * Ex with media.pdf#page=31
                 */
                list($key, $value) = explode("=", $token, 2);

                /**
                 * Case of an anchor after a boolean attribute (ie without =)
                 * at the end
                 */
                $anchorPosition = strpos($key, '#');
                if ($anchorPosition !== false) {
                    $this->fragment = substr($key, $anchorPosition + 1);
                    $key = substr($key, 0, $anchorPosition);
                }

                /**
                 * Test Anchor on the value
                 */
                if($value!=null) {
                    if (($countHashTag = substr_count($value, "#")) >= 3) {
                        LogUtility::msg("The value ($value) of the key ($key) for the link ($this->pathOrId) has $countHashTag `#` characters and the maximum supported is 2.", LogUtility::LVL_MSG_ERROR);
                        continue;
                    }
                } else {
                    /**
                     * Boolean attribute
                     */
                    $value = "true";
                }

                $anchorPosition = false;
                $lowerCaseKey = strtolower($key);
                if ($lowerCaseKey === TextColor::CSS_ATTRIBUTE) {
                    /**
                     * Special case when color has one color value as hexadecimal #
                     * and the hashtag
                     */
                    if (strpos($value, '#') == 0) {
                        if (substr_count($value, "#") >= 2) {

                            /**
                             * The last one
                             */
                            $anchorPosition = strrpos($value, '#');
                        }
                        // no anchor then
                    } else {
                        // a color that is not hexadecimal can have an anchor
                        $anchorPosition = strpos($value, "#");
                    }
                } else {
                    // general case
                    $anchorPosition = strpos($value, "#");
                }
                if ($anchorPosition !== false) {
                    $this->fragment = substr($value, $anchorPosition + 1);
                    $value = substr($value, 0, $anchorPosition);
                }

                switch ($lowerCaseKey) {
                    case "w": // used in a link w=xxx
                        $this->queryParameters[Dimension::WIDTH_KEY] = $value;
                        break;
                    case "h": // used in a link h=xxxx
                        $this->queryParameters[Dimension::HEIGHT_KEY] = $value;
                        break;
                    default:
                        /**
                         * Multiple parameter can be set to form an array
                         *
                         * Example: s=word1&s=word2
                         *
                         */
                        if (isset($this->queryParameters[$key])){
                            $actualValue = $this->queryParameters[$key];
                            if(is_array($actualValue)){
                                $actualValue[]=$value;
                                $this->queryParameters[$key] = $actualValue;
                            } else {
                                $this->queryParameters[$key] = [$actualValue, $value];
                            }
                        } else {
                            $this->queryParameters[$key] = $value;
                        }
                }

            }

            /**
             * If a fragment was found,
             * calculate the query string
             */
            $this->queryString = $queryStringAndAnchorOriginal;
            if ($this->fragment != null) {
                $this->queryString = substr($queryStringAndAnchorOriginal, 0, -strlen($this->fragment) - 1);
            }
        }

    }


    public static function createFromUrl($dokuwikiUrl)
    {
        return new DokuwikiUrl($dokuwikiUrl);
    }

    /**
     * All URL token in an array
     * @return array
     */
    public function toArray()
    {
        $attributes = [];
        $attributes[self::ANCHOR_ATTRIBUTES] = $this->fragment;
        $attributes[DokuPath::PATH_ATTRIBUTE] = $this->pathOrId;
        return PluginUtility::mergeAttributes($attributes, $this->queryParameters);
    }

    public function getQueryString()
    {
        return $this->queryString;
    }

    public function hasQueryParameter($propertyKey)
    {
        return isset($this->queryParameters[$propertyKey]);
    }

    public function getQueryParameters()
    {
        return $this->queryParameters;
    }

    public function getFragment()
    {
        return $this->fragment;
    }

    /**
     * In Dokuwiki, a path may also be in the form of an id (ie without root separator)
     * @return false|string
     */
    public function getPathOrId()
    {
        return $this->pathOrId;
    }

    public function getQueryParameter($key)
    {
        if(isset($this->queryParameters[$key])){
            return $this->queryParameters[$key];
        } else {
            return null;
        }

    }
}
