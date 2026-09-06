<?php

declare(strict_types=1);

namespace App\Domain\Sms\Exceptions;

use RuntimeException;

final class SmsSendFailed extends RuntimeException {}
