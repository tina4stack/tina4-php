<?php

namespace routes;

use PHPUnit\Framework\TestCase;
use Tina4\MailboxReader;

class MessageTest extends TestCase
{
    function testConnectInbox()
    {
        $reader = new MailboxReader("sleekapp.co.za", "143", "sleekapp", "bixxif-vofjoD-dodpy9");
        $messages = $reader->getMessages("");
        $this->assertIsArray($messages, "InboxReader->getMessages should fetch an array");
    }

}