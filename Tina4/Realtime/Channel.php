<?php

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
