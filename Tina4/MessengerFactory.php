<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 *
 * MessengerFactory — Creates a Messenger or DevMailbox based on environment.
 * Returns DevMailbox in development mode, real Messenger in production.
 */

namespace Tina4;

class MessengerFactory
{
    /**
     * Create a Messenger or DevMailbox based on the current environment.
     *
     * Returns a DevMailbox when:
     *   - TINA4_DEBUG is set to a truthy value, OR
     *   - TINA4_MAIL_HOST is not configured
     *
     * Returns a real Messenger otherwise.
     *
     * @return Messenger|DevMailbox
     */
    public static function create(): Messenger|DevMailbox
    {
        $debug = self::env('TINA4_DEBUG');
        $smtpHost = self::env('TINA4_MAIL_HOST');

        $isDev = match (true) {
            $debug !== null && in_array(strtolower($debug), ['true', '1', 'yes', 'on'], true) => true,
            $smtpHost === null || $smtpHost === '' => true,
            default => false,
        };

        if ($isDev) {
            return new DevMailbox();
        }

        return new Messenger();
    }

    /**
     * Get an environment variable value.
     */
    private static function env(string $key): ?string
    {
        if (class_exists(DotEnv::class) && method_exists(DotEnv::class, 'getEnv')) {
            $value = DotEnv::getEnv($key);
            if ($value !== null) {
                return $value;
            }
        }

        $value = getenv($key);
        return $value !== false ? $value : null;
    }
}
