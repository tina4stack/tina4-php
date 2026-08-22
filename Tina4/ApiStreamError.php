<?php

namespace Tina4;

/**
 * Base error raised by the streaming primitives on Api (streamBytes /
 * streamLines / streamSse). All streaming-transport failures inherit from
 * this class so a caller can catch the whole family with one `catch`.
 *
 * The AI client translates these to AITimeoutError / AIHTTPError for its
 * pre-stream retry policy; the streaming primitive itself only reports
 * transport-level facts, never provider semantics.
 */
class ApiStreamError extends \RuntimeException
{
}
