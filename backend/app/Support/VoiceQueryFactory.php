<?php
declare(strict_types=1);
namespace Clinica\Support;

use Clinica\Repositories\{Connection,VoiceQueryRepository};
use Clinica\Services\VoiceQueryService;

final class VoiceQueryFactory
{
    public static function service():VoiceQueryService
    {
        return new VoiceQueryService(new VoiceQueryRepository(Connection::readOnly(require dirname(__DIR__,2).'/config/database.php')));
    }
}
