<?php

namespace Tina4\Realtime;

/**
 * ChannelMember — a user's membership of a channel plus their read cursor.
 * userId is a string so it holds any identity shape the app puts in the JWT
 * (an integer id, a UUID, an email). lastReadAt is the read-receipt cursor.
 *
 * camelCase properties map to snake_case columns/JSON via the ORM (see
 * Workspace) — channel_id, user_id, last_read_at on the wire.
 */
class ChannelMember extends \Tina4\ORM
{
    public string $tableName = 'tina4_rt_channel_members';
    public string $primaryKey = 'id';

    public ?int $id = null;
    public ?int $channelId = null;
    public string $userId = '';
    public string $role = 'member';
    public ?string $lastReadAt = null;
}
