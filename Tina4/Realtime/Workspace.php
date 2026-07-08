<?php

namespace Tina4\Realtime;

/**
 * Workspace — the top-level container for channels (a "team" / "org").
 * Framework-owned table: the tina4_rt_ prefix keeps it clear of an app's own
 * domain tables (mirrors tina4_migration / tina4_sequences + Python tina4_rt_*).
 *
 * Properties are camelCase (Tina4 PHP ORM convention): the ORM maps them to
 * snake_case columns on createTable()/save() and back on read, and toDict()
 * serialises snake_case keys — so the DB schema and JSON wire shape stay
 * byte-identical to the Python master (created_at, workspace_id, ...).
 */
class Workspace extends \Tina4\ORM
{
    public string $tableName = 'tina4_rt_workspaces';
    public string $primaryKey = 'id';

    public ?int $id = null;
    public string $name = '';
    public ?string $createdAt = null;
}
