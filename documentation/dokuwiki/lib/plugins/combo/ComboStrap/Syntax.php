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


use dokuwiki\Extension\SyntaxPlugin;

abstract class Syntax extends SyntaxPlugin
{

    const NODE_TYPE_BLOCK = "block";
    const NODE_TYPE_INLINE = "block";

    /**
     * @return mixed
     */
    public abstract function getNodeType();

}
