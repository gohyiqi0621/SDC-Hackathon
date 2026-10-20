<?php
/**
 * GoogleAuthRevoked.php - Google rejected the stored tokens (expired consent,
 * password change, access removed). The user has to connect again.
 */
declare(strict_types=1);

namespace Src\Services;

use RuntimeException;

final class GoogleAuthRevoked extends RuntimeException
{
}
