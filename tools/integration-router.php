<?php
declare(strict_types=1);
require __DIR__.'/integration-bootstrap.php';
header('X-Clinic-Environment: isolated-integration');
return require __DIR__.'/dev-router.php';
