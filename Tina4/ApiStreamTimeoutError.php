<?php

namespace Tina4;

/**
 * Raised when Api streaming exceeds TINA4_API_TIMEOUT (total) or
 * TINA4_API_CONNECT_TIMEOUT (connection establishment). Iterator ends and
 * the underlying socket is closed by the streaming finally block.
 */
class ApiStreamTimeoutError extends ApiStreamError
{
}
