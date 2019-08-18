<?php namespace Tina4;

class routingCest
{
    public function _before(ApiTester $I)
    {
    }

    // tests
    public function tryToTestAnyClass(ApiTester $I)
    {
        $I->amGoingTo("Test the Any Class");
        $I->sendGET("/tests/routing/any/notfound");
        $I->seeResponseCodeIs(\Codeception\Util\HttpCode::NOT_FOUND);

        $I->sendGET( "/tests/routing/any");
        $I->seeResponseCodeIs(\Codeception\Util\HttpCode::OK);

        $I->sendPUT( "/tests/routing/any");
        $I->seeResponseCodeIs(\Codeception\Util\HttpCode::OK);

        $I->sendPATCH( "/tests/routing/any");
        $I->seeResponseCodeIs(\Codeception\Util\HttpCode::OK);

        $I->sendPOST( "/tests/routing/any");
        $I->seeResponseCodeIs(\Codeception\Util\HttpCode::OK);

        $I->sendDELETE( "/tests/routing/any");
        $I->seeResponseCodeIs(\Codeception\Util\HttpCode::OK);

        $I->sendOPTIONS( "/tests/routing/any");
        $I->seeResponseCodeIs(\Codeception\Util\HttpCode::OK);
    }
}
