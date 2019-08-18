<?php namespace Tina4;
use Tina4\ApiTester;

class routingCest
{
    public function _before(ApiTester $I)
    {
    }

    // tests
    public function tryToTestGet(ApiTester $I)
    {
        $I->sendGET("/tests/routing/any");
        $I->seeResponseCodeIs(\Codeception\Util\HttpCode::OK);
    }
}
