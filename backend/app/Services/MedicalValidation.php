<?php
declare(strict_types=1);
namespace Clinica\Services;
final class MedicalValidation extends \RuntimeException
{
    public function __construct(public readonly array $errors){parent::__construct('Revise los campos indicados.');}
}
