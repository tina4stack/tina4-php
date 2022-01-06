<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Helps manage the code for the debugger
 * @package Tina4
 */
class DebugCodeHandler
{
    /**
     * Gets 10 lines around line where error occurred
     * @param string $fileName
     * @param int $lineNo
     * @return string
     */
    public static function getCodeSnippet(string $fileName, int $lineNo): string
    {
        if (file_exists($fileName)) {
            $lines = explode(PHP_EOL, file_get_contents($fileName));

            $lineStart = $lineNo - 5;
            if ($lineStart < 0) {
                $lineStart = 0;
            }

            $lineEnd = $lineNo + 5;
            if ($lineEnd > count($lines) - 1) {
                $lineEnd = count($lines) - 1;
            }

            $codeContent = [];
            for ($i = $lineStart; $i < $lineEnd; $i++) {
                $lineNr = $i + 1;
                if ($lineNr === $lineNo) {
                    $codeContent[] = "<span class='selected'><span class='lineNo'>{$lineNr}</span>" . ($lines[$i]) . "</span>";
                } else {
                    $codeContent[] = "<span class='lineNo'>{$lineNr}</span>" . ($lines[$i]);
                }
            }
            return implode(PHP_EOL, $codeContent);
        }

        return "";
    }
}