<?php
declare(strict_types=1);
namespace Clinica\Support;

final class Input
{
    public static function positiveId(mixed $value): ?int
    {
        if(!is_string($value) || !preg_match('/^[1-9][0-9]*$/D',$value)){return null;}
        $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        return $id===false ? null : $id;
    }
}
