<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

class Calculator extends \Tina4\WSDL {
    protected array $returnSchemas = [
        "Add" => ["Result" => "int"],
        "SumList" => [
            "Numbers" => "array<int>",
            "Total" => "int",
            "Error" => "?string"
        ]
    ];

    public function Add(int $a, int $b): array {
        return ["Result" => $a + $b];
    }

    /**
     * @param int[] $Numbers
     */
    public function SumList(array $Numbers): array {
        return [
            "Numbers" => $Numbers,
            "Total" => array_sum($Numbers),
            "Error" => null
        ];
    }
}

// Tina4 route example
\Tina4\Any::add("/calculator", function (\Tina4\Request $request, \Tina4\Response $response) {
    $calculator = new Calculator($request);
    $handle = $calculator->handle();
    return $response($handle, HTTP_OK, APPLICATION_XML);
});
