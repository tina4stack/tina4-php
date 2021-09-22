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


class DomUtility
{
    /**
     * @param DOMElement $domElements
     * @return array with one element by dom element  with its attributes
     *
     * This funcion was created because there is no way to get this information
     * from the phpQuery element
     */
    static public function domElements2Attributes($domElements)
    {
        $nodes = array();
        foreach ($domElements as $key => $domElement) {
            $nodes[$key] = self::extractsAttributes($domElement);
        }
        return $nodes;
    }

    /**
     * @param DOMElement $domElement
     * @return array with one element  with its attributes
     *
     * This function was created because there is no way to get this information
     * from the phpQuery element
     */
    static public function extractsAttributes($domElement)
    {
        $node = array();
        if ($domElement->hasAttributes()) {
            foreach ($domElement->attributes as $attr) {
                $name = $attr->name;
                $value = $attr->value;
                $node[$name] = $value;
            }
        }
        return $node;
    }

}
