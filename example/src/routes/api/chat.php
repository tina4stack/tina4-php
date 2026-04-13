<?php

\Tina4\Router::get("/api/chat/history", function ($request, $response) {
    try {
        $db = \Tina4\App::getDatabase();

        $sessionsResult = $db->fetch(
            "SELECT DISTINCT client_id, MAX(created_at) as last_activity FROM chat_messages WHERE created_at > datetime('now', '-24 hours') GROUP BY client_id ORDER BY last_activity DESC",
            [], 50, 0
        );

        $sessions = [];
        if ($sessionsResult && $sessionsResult->records) {
            foreach ($sessionsResult->records as $row) {
                $clientId = $row["client_id"] ?? "";

                $msgsResult = $db->fetch(
                    "SELECT sender, text, is_admin, created_at FROM chat_messages WHERE client_id = ? ORDER BY created_at ASC",
                    [$clientId], 200, 0
                );

                $messages = [];
                if ($msgsResult && $msgsResult->records) {
                    foreach ($msgsResult->records as $m) {
                        $messages[] = [
                            "sender" => $m["sender"] ?? "Guest",
                            "text" => $m["text"] ?? "",
                            "is_admin" => (bool) ($m["is_admin"] ?? 0),
                            "created_at" => $m["created_at"] ?? "",
                        ];
                    }
                }

                $name = $clientId;
                foreach ($messages as $m) {
                    if (!$m["is_admin"] && $m["sender"] && $m["sender"] !== "Guest") {
                        $name = $m["sender"];
                        break;
                    }
                }

                $sessions[] = [
                    "client_id" => $clientId,
                    "name" => $name,
                    "last_activity" => $row["last_activity"] ?? "",
                    "messages" => $messages,
                ];
            }
        }

        return $response(["sessions" => $sessions]);
    } catch (\Throwable $e) {
        return $response(["error" => $e->getMessage()], 500);
    }
})->middleware(["adminAuth"]);
