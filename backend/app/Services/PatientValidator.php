<?php
declare(strict_types=1);
namespace Clinica\Services;

final class PatientValidator
{
    public const FIELDS = [
        'codigo_paciente'=>['Código de paciente',30,true],
        'documento_identidad'=>['Documento de identidad',30,true],
        'nombres'=>['Nombres',100,true], 'apellidos'=>['Apellidos',100,true],
        'fecha_nacimiento'=>['Fecha de nacimiento',null,false],
        'sexo'=>['Sexo',30,false], 'telefono'=>['Teléfono',30,false],
        'correo_contacto'=>['Correo de contacto',160,false], 'direccion'=>['Dirección',null,false],
        'contacto_emergencia_nombre'=>['Nombre del contacto de emergencia',null,false],
        'contacto_emergencia_parentesco'=>['Parentesco del contacto',null,false],
        'contacto_emergencia_telefono'=>['Teléfono de emergencia',30,false],
        'estado'=>['Estado',10,true],
    ];

    public static function id(mixed $value): ?int
    {
        return \Clinica\Support\Input::positiveId($value);
    }

    public static function validate(array $input): array
    {
        $data=[]; $errors=[];
        if (array_diff(array_keys($input),array_merge(array_keys(self::FIELDS),['csrf','version']))) {
            $errors['_form']='El formulario incluye campos que no se pueden editar.';
        }
        foreach(self::FIELDS as $field=>[$label,$max,$required]) {
            $value=$input[$field] ?? '';
            if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0")) {
                $errors[$field]='El valor de este campo no es válido.';
                $data[$field]=''; continue;
            }
            $value=trim($value);
            $data[$field]=$value === '' && !$required ? null : $value;
            if($required && $value==='') { $errors[$field]="$label es obligatorio."; }
            elseif($max!==null && mb_strlen($value,'UTF-8')>$max) { $errors[$field]="Use como máximo $max caracteres."; }
        }
        if(!in_array($data['estado'],['activo','inactivo'],true)) { $errors['estado']='Seleccione activo o inactivo.'; }
        if($data['correo_contacto']!==null && !filter_var($data['correo_contacto'],FILTER_VALIDATE_EMAIL)) { $errors['correo_contacto']='Ingrese un correo electrónico válido.'; }
        if($data['fecha_nacimiento']!==null) {
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$data['fecha_nacimiento']);
            if(!$date || $date->format('Y-m-d')!==$data['fecha_nacimiento'] || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$data['fecha_nacimiento']) || substr($data['fecha_nacimiento'],0,4)==='0000') { $errors['fecha_nacimiento']='Ingrese una fecha válida (AAAA-MM-DD).'; }
        }
        return ['data'=>$data,'errors'=>$errors];
    }
}
