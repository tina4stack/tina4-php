<?php namespace Tina4;

class routingCest
{
    public function _before(ApiTester $I)
    {
    }

    // tests
    public function tryToTestAnyClass(ApiTester $I)
    {
        $I->amGoingTo("test Any Class");

        $I->amGoingTo("test GET for route that does not exist");
        $I->sendGET("/cest/routing/any/notfound");
        $I->seeResponseCodeIs(\Codeception\Util\HttpCode::NOT_FOUND);

        $I->sendGET( "/cest/routing/any");
        $I->seeResponseCodeIs(\Codeception\Util\HttpCode::OK);

        $I->sendPUT( "/cest/routing/any");
        $I->seeResponseCodeIs(\Codeception\Util\HttpCode::OK);

        $I->sendPATCH( "/cest/routing/any");
        $I->seeResponseCodeIs(\Codeception\Util\HttpCode::OK);

        $I->sendPOST( "/cest/routing/any");
        $I->seeResponseCodeIs(\Codeception\Util\HttpCode::OK);

        $I->sendDELETE( "/cest/routing/any");
        $I->seeResponseCodeIs(\Codeception\Util\HttpCode::OK);

        $I->sendOPTIONS( "/cest/routing/any");
        $I->seeResponseCodeIs(\Codeception\Util\HttpCode::OK);
    }
}
