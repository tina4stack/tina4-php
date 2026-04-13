<?php

/**
 * WebSocket routes — order tracking and live chat.
 * Demonstrates: WebSocket rooms, broadcast, message persistence.
 */

$ws = new \Tina4\WebSocket();

$ws->route("/ws/orders")(function ($conn) {
    $conn->sendJson(["type" => "connected", "message" => "Order tracking ready"]);

    $conn->setOnMessage(function ($message) use ($conn) {
        try {
            $data = json_decode($message, true);
            if (($data["action"] ?? "") === "track") {
                $orderId = $data["order_id"] ?? null;
                $room = "order_{$orderId}";
                $conn->joinRoom($room);
                $conn->sendJson([
                    "type" => "tracking",
                    "order_id" => $orderId,
                    "message" => "Tracking started",
                ]);
            }
        } catch (\Throwable $e) {
            $conn->sendJson(["type" => "error", "message" => "Invalid JSON"]);
        }
    });

    $conn->setOnClose(function () {
        // cleanup if needed
    });
});

$ws->route("/ws/chat")(function ($conn) {
    $conn->joinRoom("chat_lobby");
    $conn->sendJson(["type" => "system", "message" => "Connected to support chat"]);

    // Notify admin users someone joined
    $conn->broadcastToRoom("chat_admin", json_encode([
        "type" => "system",
        "message" => "Customer {$conn->id} joined chat",
        "client_id" => $conn->id,
    ]), true);

    $conn->setOnMessage(function ($message) use ($conn) {
        try {
            $data = json_decode($message, true);
            $msgType = $data["type"] ?? "message";

            if ($msgType === "join_admin") {
                $conn->joinRoom("chat_admin");
                $conn->leaveRoom("chat_lobby");
                $conn->sendJson(["type" => "system", "message" => "Joined as admin"]);
                return;
            }

            if ($msgType === "message") {
                $sender = $data["sender"] ?? "Guest";
                $text = $data["text"] ?? "";
                $isAdmin = in_array("chat_admin", $conn->getRooms());
                $clientId = $data["target_client_id"] ?? $conn->id;

                // Persist to database
                try {
                    $db = \Tina4\App::getDatabase();
                    $db->execute(
                        "INSERT INTO chat_messages (client_id, sender, text, is_admin) VALUES (?, ?, ?, ?)",
                        [$clientId, $sender, $text, $isAdmin ? 1 : 0]
                    );
                    $db->commit();
                } catch (\Throwable $e) {
                    // Don't break chat if DB write fails
                }

                $payload = json_encode([
                    "type" => "message",
                    "sender" => $sender,
                    "text" => $text,
                    "is_admin" => $isAdmin,
                    "client_id" => $clientId,
                ]);

                $conn->broadcastToRoom("chat_lobby", $payload, false);
                $conn->broadcastToRoom("chat_admin", $payload, false);
            }
        } catch (\Throwable $e) {
            $conn->sendJson(["type" => "error", "message" => "Invalid JSON"]);
        }
    });

    $conn->setOnClose(function () use ($conn) {
        $conn->broadcastToRoom("chat_admin", json_encode([
            "type" => "system",
            "message" => "Customer {$conn->id} left chat",
            "client_id" => $conn->id,
        ]), false);
    });
});
