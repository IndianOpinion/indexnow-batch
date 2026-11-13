<?php

declare(strict_types=1);

namespace IndianOpinion\IndexNowBatch\Queue;

/**
 * The queue table is missing or lacks columns after creation.
 */
final class SchemaException extends \RuntimeException
{
}
