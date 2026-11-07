<?php
declare(strict_types=1);
namespace Clinica\Services;
use Clinica\Support\Input;

final class MedicalValidator
{
    public const DOCTOR_FIELDS=[
        'codigo_medico'=>['Código de médico',30,true], 'registro_profesional'=>['Registro profesional',40,true],
        'nombres'=>['Nombres',100,true], 'apellidos'=>['Apellidos',100,true],
        'telefono'=>['Teléfono',30,false], 'correo_contacto'=>['Correo de contacto',160,false], 'estado'=>['Estado',10,true],
    ];
    public const SPECIALTY_FIELDS=[
        'nombre'=>['Nombre',100,true], 'descripcion'=>['Descripción',null,false],
        'precio_consulta'=>['Precio de consulta (BOB)',null,true], 'estado'=>['Estado',10,true],
    ];
    public static function fields(string $kind): array
    {
        return match($kind){'medico'=>self::DOCTOR_FIELDS,'especialidad'=>self::SPECIALTY_FIELDS,default=>throw new \InvalidArgumentException('Catálogo inválido.')};
    }
    public static function price(string $input): ?string
    {
        if(!preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/D',trim($input),$parts)){return null;}
        $whole=ltrim($parts[1],'0') ?: '0'; $fraction=str_pad($parts[2] ?? '',2,'0');
        if(strlen($whole)>10 || ($whole==='0' && $fraction==='00')){return null;}
        return $whole.'.'.$fraction;
    }
    public static function associationVersion(array $ids): string
    {
        sort($ids,SORT_NUMERIC); return hash('sha256',implode(',',$ids));
    }
    public static function validate(string $kind,array $input): array
    {
        $fields=self::fields($kind);$data=[];$errors=[];$ids=[];
        $extras=$kind==='medico' ? ['csrf','version','especialidades','asociaciones_version'] : ['csrf','version'];
        if(array_diff(array_keys($input),array_merge(array_keys($fields),$extras))){$errors['_form']='Hay campos que no se pueden editar desde este formulario.';}
        foreach($fields as $field=>[$label,$max,$required]){
            $value=$input[$field] ?? '';
            if(!is_string($value) || !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0")){$errors[$field]='El valor de este campo no es válido.';$data[$field]='';continue;}
            $value=trim($value);$data[$field]=$value==='' && !$required ? null : $value;
            if($required && $value===''){$errors[$field]="$label es obligatorio.";}
            elseif($max!==null && mb_strlen($value,'UTF-8')>$max){$errors[$field]="Use como máximo $max caracteres.";}
        }
        if(!in_array($data['estado'],['activo','inactivo'],true)){$errors['estado']='Seleccione activo o inactivo.';}
        if($kind==='medico'){
            if($data['correo_contacto']!==null && !filter_var($data['correo_contacto'],FILTER_VALIDATE_EMAIL)){$errors['correo_contacto']='Ingrese un correo válido.';}
            $selected=$input['especialidades'] ?? [];
            if(!is_array($selected) || !array_is_list($selected)){$errors['especialidades']='Seleccione especialidades válidas.';}
            else {
                foreach($selected as $value){$id=Input::positiveId($value);if($id===null || in_array($id,$ids,true)){$errors['especialidades']='La selección contiene identificadores inválidos o repetidos.';break;}$ids[]=$id;}
            }
        }else{
            $price=self::price($data['precio_consulta']);
            if($price===null){$errors['precio_consulta']='Ingrese un importe mayor que cero, hasta 9999999999.99 y con hasta dos decimales.';}
            else{$data['precio_consulta']=$price;}
        }
        sort($ids,SORT_NUMERIC);
        return ['data'=>$data,'ids'=>$ids,'errors'=>$errors];
    }
}
