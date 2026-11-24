<?php

declare(strict_types=1);

namespace Traqio\Http;

/** No HTTP answer at all: DNS, connection, TLS or a timeout. */
final class TransportException extends \RuntimeException
{
}
