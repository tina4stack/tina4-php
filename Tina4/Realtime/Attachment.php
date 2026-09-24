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
 * Attachment — a file linked to a channel (and optionally a message).
 * channelId scopes the file for permission checks; messageId is null until
 * the file is attached to a posted message. storageKey is the StorageBackend
 * key; the row carries only metadata, never the blob.
 *
 * camelCase properties map to snake_case columns/JSON via the ORM (see
 * Workspace) — channel_id, message_id, storage_key, thumb_key on the wire.
 */
class Attachment extends \Tina4\ORM
{
    public string $tableName = 'tina4_rt_attachments';
    public string $primaryKey = 'id';

    public ?int $id = null;
    public ?int $channelId = null;
    public ?int $messageId = null;
    public string $storageKey = '';
    public string $filename = '';
    public string $mime = '';
    public ?int $size = null;
    public ?string $thumbKey = null;
}
