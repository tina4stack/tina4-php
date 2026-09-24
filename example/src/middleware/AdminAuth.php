<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


class AdminAuth
{
    public static function before($request, $response)
    {
        $token = $request->session->get("token");
        if (!$token || !\Tina4\Auth::validToken($token)) {
            return $response->redirect("/login");
        }

        $payload = \Tina4\Auth::getPayload($token);
        if (($payload['role'] ?? '') !== 'admin') {
            return $response(["error" => "Forbidden: admin access required"], 403);
        }

        return [$request, $response];
    }
}
