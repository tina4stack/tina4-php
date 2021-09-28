<?php

namespace ComboStrap;


use DOMDocument;
use Exception;
use LibXMLError;

require_once(__DIR__ . '/XmlUtility.php');

/**
 * Class HtmlUtility
 * Static HTML utility
 *
 * On HTML as string, if you want to work on HTML as XML, see the {@link XmlUtility} class
 *
 * @package ComboStrap
 *
 * This class is based on {@link XmlDocument}
 *
 */
class XhtmlUtility
{


    /**
     * Return a diff
     * @param string $left
     * @param string $right
     * @return string
     * DOMDocument supports formatted XML while SimpleXMLElement does not.
     * @noinspection PhpComposerExtensionStubsInspection
     */
    public static function diffMarkup($left, $right, $xhtml = true)
    {
        if (empty($right)) {
            throw new \RuntimeException("The right text should not be empty");
        }
        if (empty($left)) {
            throw new \RuntimeException("The left text should not be empty");
        }
        $loading = XmlDocument::XML_TYPE;
        if (!$xhtml) {
            $loading = XmlDocument::HTML_TYPE;
        }

        $leftDocument = (new XmlDocument($left, $loading))->getXmlDom();
        $rightDocument = (new XmlDocument($right, $loading))->getXmlDom();

        $error = "";
        XmlUtility::diffNode($leftDocument, $rightDocument, $error);

        return $error;

    }

    /**
     * @param $text
     * @return int the number of lines estimated
     */
    public static function countLines($text)
    {
        return count(preg_split("/<\/p>|<\/h[1-9]{1}>|<br|<\/tr>|<\/li>|<hr>|<\/pre>/", $text)) - 1;
    }


    public static function normalize($htmlText)
    {
        if (empty($htmlText)) {
            throw new \RuntimeException("The text should not be empty");
        }
        $xmlDoc = new XmlDocument($htmlText, XmlDocument::HTML_TYPE);
        return $xmlDoc->getXmlTextNormalized();
    }


}
