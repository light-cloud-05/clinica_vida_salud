<?php
declare(strict_types=1);

namespace Clinica\Controllers;

final class HomeController
{
    public function index(): void
    {
        require dirname(__DIR__, 3) . '/frontend/views/home.php';
    }
}
