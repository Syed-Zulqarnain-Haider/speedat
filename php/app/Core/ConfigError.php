<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * A configuration problem: the file is missing, unreadable, does not return an
 * array, or a required value is empty.
 *
 * The message NEVER contains a configuration value - only the key that is
 * wrong. A misconfigured server that prints this on screen must not thereby
 * print the database password.
 */
final class ConfigError extends \RuntimeException
{
}
