<?php
declare(strict_types=1);
namespace Clinica\Controllers;

/** Public liveness metadata only. No database, configuration or credential diagnostics. */
final class HealthController
{
    public function index(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        $commit=getenv('RENDER_GIT_COMMIT');
        echo json_encode(['status'=>'ok','commit'=>is_string($commit)&&preg_match('/^[a-f0-9]{40}$/D',$commit)?$commit:null],JSON_THROW_ON_ERROR);
    }
}
