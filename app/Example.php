<?php
/**
 * Class Example
 * This example class demonstrates some basic Tina4 concepts
 */

class Example
{

    /**
     * @param $a
     * @param $b
     * @return mixed
     * @tests
     *   assert (1,1) === 2, "1 + 1 = 2"
     *   assert (1,2) !== 4, "1 + 2 <> 4"
     */
    public function add2Numbers($a, $b) {
        return $a + $b;
    }


    /**
     * To do this test get an API key from https://the-one-api.dev/ and put it in the .env file
     *
     * @tests
     *   assert () === "The Fellowship Of The Ring", "API request"
     *   assert () !== "The Fellowship Of The Ring", "Testing again"
     *
     */
    public function testAPI() {
       $api = new \Tina4\Api("https://the-one-api.dev/v2", "Authorization: Bearer ".API_KEY);

       return $api->sendRequest("/book")->docs[0]->name;
    }
}