<?php

namespace Tina4;

class Slack extends \Tina4\Api
{
    public $ignoreSSLValidation = true;
    /**
     * Constructor for Slack
     * @param string|null $baseURL
     * @param string $authHeader
     */
    function __construct(?string $baseURL = "", string $authHeader = "")
    {
        if (empty($baseURL)) {
            $baseURL = "https://slack.com/api/";
        }
        $authHeader = "Authorization: Bearer ".$_ENV["SLACK_TOKEN"];

        parent::__construct($baseURL, $authHeader);
    }

    /**
     * Sending a message to slack
     * @param $message
     * @param string $channel
     * @return void
     */
    function postMessage ($message, string $channel="general")
    {
        $channels = $this->getChannels();
        return $this->sendRequest("chat.postMessage", "POST", ["text" => $message, "channel" => $channels[$channel]["id"]]);
    }

    /**
     * Gets channels from Slack
     * @param string|null $types
     * @param string $limit
     * @return array|mixed
     */
    function getChannels(string $types= null, string $limit="1000")
    {
        $types ??= "public_channel,private_channel";
        $result = $this->sendRequest("conversations.list?types={$types}&limit={$limit}", "GET");

        if (empty($result["error"])) {
            $channels = $result["body"]["channels"];
            $channelList = [];
            foreach ($channels as $id => $channel) {
                $channelList[$channel["name"]] = $channel;
            }
            return $channelList;
        } else {
            return $result;
        }
    }

    function checkAuth()
    {
        return $this->sendRequest("auth.test?pretty=1", "GET");
    }
}