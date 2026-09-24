<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


namespace Tina4\Realtime;

/**
 * Message — one posted message in a channel.
 * threadId is null for a top-level message, or the id of the parent message
 * for a threaded reply. editedAt is null until an edit.
 *
 * camelCase properties map to snake_case columns/JSON via the ORM (see
 * Workspace) — channel_id, user_id, thread_id, created_at, edited_at.
 */
class Message extends \Tina4\ORM
{
    public string $tableName = 'tina4_rt_messages';
    public string $primaryKey = 'id';

    public ?int $id = null;
    public ?int $channelId = null;
    public string $userId = '';
    public string $body = '';
    public ?int $threadId = null;
    public ?string $createdAt = null;
    public ?string $editedAt = null;
}
