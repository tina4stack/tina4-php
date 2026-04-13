<?php

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
