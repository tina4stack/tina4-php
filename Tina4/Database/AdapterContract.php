<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Database;

/**
 * ADR-0044 (feature 3, plan/v3/fixtures/adapter_contract.json): the exact
 * fourteen adapter capabilities. None is optional; a registered adapter
 * missing one fails loud at registration time, never at the first unlucky
 * call (DBA-S02).
 */
final class AdapterContract
{
    /** @var string[] */
    public const REQUIRED_CAPABILITIES = [
        'connect', 'close', 'getDatabaseType',
        'execute', 'executeMany', 'fetch', 'fetchOne',
        'startTransaction', 'commit', 'rollback', 'autocommit',
        'getTables', 'getColumns', 'tableExists',
    ];

    /**
     * ADR-0044 NOT_REQUIRED_ON_ADAPTER: engine-neutral composition that must
     * NOT be part of the declared DatabaseAdapter interface (DBA-S03).
     *
     * @var string[]
     */
    public const NOT_REQUIRED_ON_ADAPTER = [
        'query', 'insert', 'update', 'delete', 'truncate', 'fetchAll',
        'createTable', 'addColumn', 'lastInsertId', 'error', 'sqlTranslation',
    ];

    /**
     * Fail loud when a class does not declare every required capability.
     *
     * @param class-string $adapterClass
     * @throws AdapterContractException naming the adapter and every missing capability
     */
    public static function validate(string $adapterClass, string $name = ''): void
    {
        $label = $name !== '' ? $name : $adapterClass;
        $missing = [];
        foreach (self::REQUIRED_CAPABILITIES as $capability) {
            if (!method_exists($adapterClass, $capability)) {
                $missing[] = $capability;
            }
        }
        if ($missing !== []) {
            throw new AdapterContractException(
                "adapter '{$label}' does not implement the required Tina4 database "
                . 'adapter contract capabilities: ' . implode(', ', $missing)
                . ' (ADR-0044 / plan/v3/fixtures/adapter_contract.json)'
            );
        }
    }
}
