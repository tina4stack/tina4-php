<?php

namespace Tina4;

class GitDeploy
{

    /***
     * Deploying the system from a git repository
     * @param Response $response
     * @param Request $request
     * @return void
     * @throws \JsonException
     */
    public function deploy(Response $response, Request $request) : array
    {
        if (!isset($_ENV["GIT_SECRET"])) {
            throw Exception("GIT_SECRET not set in .env");
        }

        $signature = "sha1=".hash_hmac('sha1', $request->rawRequest, $_ENV["GIT_SECRET"]);

        //Validate the post
        if ($signature !== $request->headers["X-Hub-Signature"]) {
            throw new Exception("Invalid signature from GIT, make sure you have set your secret properly on your webhook");
        }

        //Pull the repository from the git repository
        Debug::message("Validated signature from GIT, pulling repository", TINA4_LOG_INFO);

        //If it does, pull the files from the repository and deploy the src folder, including changes to the index.php
        return $response("OK!");
    }
}