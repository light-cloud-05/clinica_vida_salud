<?php
declare(strict_types=1);

/** Only known state membership CHECKs are normalized. All other constraints stay exact. */
final class MigrationConstraintComparison
{
    public const STATES=[
        'citas'=>['citas_estado_check'=>['programada','confirmada','atendida','cancelada','inasistencia']],
        'especialidades'=>['especialidades_estado_check'=>['activo','inactivo']],
        'horarios_atencion'=>['horarios_atencion_estado_check'=>['activo','inactivo']],
        'medicos'=>['medicos_estado_check'=>['activo','inactivo']],
        'pacientes'=>['pacientes_estado_check'=>['activo','inactivo']],
        'reservas_provisionales'=>['reservas_provisionales_estado_check'=>['pendiente','confirmada','expirada','cancelada']],
    ];
    public static function normalize(array $constraint): array
    {
        $expected=self::STATES[$constraint['table_name']][$constraint['conname']]??null;
        if ($expected===null) { return $constraint; }
        if ($constraint['contype']!=='c' || json_decode($constraint['column_names']??'null',true)!==['estado']) { throw new MigrationDiagnosticFailure('SCHEMA_CONSTRAINTS_DIFFER'); }
        $literals=[];
        $expression=preg_replace_callback("/'((?:''|[^'])*)'/s",static function(array $match)use(&$literals): string {
            $index=count($literals); $literals[]=str_replace("''","'",$match[1]); return 'literal'.$index;
        },$constraint['definition']);
        // No width casts, user functions, collations, OR, NOT, extra columns or operators are accepted.
        $expression=preg_replace('/::\s*(?:character\s+varying|text)(?:\s*\[\s*\])?/i','',(string)$expression);
        $expression=strtolower((string)preg_replace('/[\s()]+/','',(string)$expression));
        if (!preg_match('/^check(?:estado|"estado")=anyarray\[(literal\d+(?:,literal\d+)*)\]$/D',$expression,$match)) { throw new MigrationDiagnosticFailure('SCHEMA_CONSTRAINTS_DIFFER'); }
        $values=[];
        foreach(explode(',',$match[1]) as $token) {
            $index=(int)substr($token,7);
            if(!array_key_exists($index,$literals)) { throw new MigrationDiagnosticFailure('SCHEMA_CONSTRAINTS_DIFFER'); }
            $values[]=$literals[$index];
        }
        $values=array_values(array_unique($values)); sort($values,SORT_STRING); sort($expected,SORT_STRING);
        if($values!==$expected) { throw new MigrationDiagnosticFailure('SCHEMA_CONSTRAINTS_DIFFER'); }
        $constraint['definition']=['operator'=>'text_enum_membership','column'=>'estado','values'=>$values];
        return $constraint;
    }
}
