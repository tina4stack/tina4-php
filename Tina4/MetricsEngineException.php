<?php

namespace Tina4;

/**
 * The native metrics engine could not produce a payload.
 *
 * Thrown instead of falling back to a second implementation: two engines is
 * exactly the condition that made the four frameworks' numbers incomparable, so
 * a missing, failing or stale `tina4` CLI fails loudly and names the fix rather
 * than quietly serving different arithmetic.
 */
class MetricsEngineException extends \RuntimeException
{
}
