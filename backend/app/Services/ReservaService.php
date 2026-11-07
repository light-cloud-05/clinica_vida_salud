<?php
declare(strict_types=1);
namespace Clinica\Services;
use Clinica\Repositories\BookingStore;
final class ReservaService
{
    public function __construct(private BookingStore $store,private bool $enabled=false){}
    public function reserve(array $actor,array $input): array
    {
        $normalized=BookingInput::parse($actor,$input);
        if(!$this->enabled){throw new \RuntimeException('Las reservas persistentes están deshabilitadas.');}
        return $this->store->create($actor,$normalized);
    }
}
