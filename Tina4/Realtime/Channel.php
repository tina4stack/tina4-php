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
 * Channel — a conversation stream inside a workspace.
 * kind is one of public | private | dm.
 *
 * camelCase properties map to snake_case columns/JSON via the ORM (see
 * Workspace) — workspace_id, created_at on the wire.
 */
class Channel extends \Tina4\ORM
{
    public string $tableName = 'tina4_rt_channels';
    public string $primaryKey = 'id';

    public ?int $id = null;
    public ?int $workspaceId = null;
    public string $name = '';
    public string $kind = 'public';
    public ?string $createdAt = null;
}
