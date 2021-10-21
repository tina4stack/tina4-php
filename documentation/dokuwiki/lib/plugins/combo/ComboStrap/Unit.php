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


class Unit
{

    /**
     * Calculates pixel size for any given SVG size
     *
     * @credit https://github.com/lechup/svgpureinsert/blob/master/helper.php#L72
     * @param $value
     * @return int
     */
    static public function toPixel($value) {
        if(!preg_match('/^(\d+?(\.\d*)?)(in|em|ex|px|pt|pc|cm|mm)?$/', $value, $m)) return 0;

        $digit = (double) $m[1];
        $unit  = (string) $m[3];

        $dpi         = 72;
        $conversions = array(
            'in' => $dpi,
            'em' => 16,
            'ex' => 12,
            'px' => 1,
            'pt' => $dpi / 72, # 1/27 of an inch
            'pc' => $dpi / 6, # 1/6 of an inch
            'cm' => $dpi / 2.54, # inch to cm
            'mm' => $dpi / (2.54 * 10), # inch to cm,
        );

        if(isset($conversions[$unit])) {
            $digit = $digit * (float) $conversions[$unit];
        }

        return $digit;
    }


}
