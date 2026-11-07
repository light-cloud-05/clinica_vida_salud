--
-- PostgreSQL database dump
--

\restrict UIpRjvV5IDbLGtj6IdQkxRU9Ky6YfR7Hewgo3P75VyPwPnhNQujFqHntj8WNSJE

-- Dumped from database version 18.6
-- Dumped by pg_dump version 18.6

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: btree_gist; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS btree_gist WITH SCHEMA public;


--
-- Name: EXTENSION btree_gist; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION btree_gist IS 'support for indexing common datatypes in GiST';


--
-- Name: actualizar_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.actualizar_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;


--
-- Name: auditar_cambios(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.auditar_cambios() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_id_entidad BIGINT;
    v_id_usuario BIGINT;
BEGIN

    -- IDENTIFICAR AL USUARIO RESPONSABLE
    v_id_usuario := NULLIF(
        current_setting('app.usuario_id', true),
        ''
    )::BIGINT;

    -- OBTENER EL IDENTIFICADOR DEL REGISTRO
    IF TG_OP = 'DELETE' THEN
        v_id_entidad :=
            (to_jsonb(OLD) ->> TG_ARGV[0])::BIGINT;
    ELSE
        v_id_entidad :=
            (to_jsonb(NEW) ->> TG_ARGV[0])::BIGINT;
    END IF;

    -- GUARDAR EL REGISTRO DE AUDITORIA
    INSERT INTO registros_auditoria (
        id_usuario,
        operacion,
        entidad_afectada,
        id_entidad,
        detalles
    )
    VALUES (
        v_id_usuario,
        TG_OP,
        TG_TABLE_NAME,
        v_id_entidad,
        'Operación registrada mediante trigger'
    );

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;

END;
$$;


--
-- Name: cancelar_cita(bigint, text); Type: PROCEDURE; Schema: public; Owner: -
--

CREATE PROCEDURE public.cancelar_cita(IN p_cita bigint, IN p_motivo text)
    LANGUAGE plpgsql
    AS $$
BEGIN

    -- CANCELAR LA CITA Y CONSERVAR SU HISTORIAL
    UPDATE citas
    SET
        estado = 'cancelada',
        motivo_cancelacion = p_motivo
    WHERE id_cita = p_cita
      AND estado IN ('programada', 'confirmada');

    -- COMPROBAR QUE SE PUDO CANCELAR
    IF NOT FOUND THEN
        RAISE EXCEPTION
            'Cita no encontrada o no cancelable';
    END IF;

    -- CANCELAR RESERVA PROVISIONAL PENDIENTE
    UPDATE reservas_provisionales
    SET estado = 'cancelada'
    WHERE id_cita = p_cita
      AND estado = 'pendiente';

    -- LOS PAGOS CONFIRMADOS NO SE MODIFICAN

END;
$$;


--
-- Name: expirar_reservas_vencidas(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.expirar_reservas_vencidas() RETURNS integer
    LANGUAGE plpgsql
    AS $$
DECLARE
    r RECORD;
    v_total INTEGER := 0;
BEGIN

    -- BUSCAR RESERVAS PENDIENTES Y VENCIDAS
    FOR r IN
        SELECT
            rp.id_reserva,
            rp.id_cita
        FROM reservas_provisionales rp
        JOIN citas c
            ON c.id_cita = rp.id_cita
        WHERE rp.estado = 'pendiente'
          AND rp.fecha_vencimiento <= now()
          AND c.estado = 'programada'

          -- NO EXPIRAR CITAS CON PAGO CONFIRMADO
          AND NOT EXISTS (
              SELECT 1
              FROM pagos p
              WHERE p.id_cita = rp.id_cita
                AND p.estado = 'confirmado'
          )

        ORDER BY rp.fecha_vencimiento
        FOR UPDATE OF rp SKIP LOCKED

    LOOP

        -- CANCELAR LA CITA MEDICA
        UPDATE citas
        SET
            estado = 'cancelada',
            motivo_cancelacion =
                'Reserva vencida por falta de pago'
        WHERE id_cita = r.id_cita
          AND estado = 'programada';

        -- MARCAR LA RESERVA COMO EXPIRADA
        UPDATE reservas_provisionales
        SET estado = 'expirada'
        WHERE id_reserva = r.id_reserva
          AND estado = 'pendiente';

        -- CONTAR RESERVAS PROCESADAS
        v_total := v_total + 1;

    END LOOP;

    -- DEVOLVER LA CANTIDAD DE RESERVAS EXPIRADAS
    RETURN v_total;

END;
$$;


--
-- Name: fn_controlar_reprogramacion(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_controlar_reprogramacion() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN

    -- COMPROBAR CAMBIOS EN FECHA, HORARIO O MEDICO
    IF (
        NEW.fecha,
        NEW.hora_inicio,
        NEW.hora_fin,
        NEW.id_medico
    ) IS DISTINCT FROM (
        OLD.fecha,
        OLD.hora_inicio,
        OLD.hora_fin,
        OLD.id_medico
    ) THEN

        -- IMPEDIR UNA SEGUNDA REPROGRAMACION
        IF OLD.cantidad_reprogramaciones >= 1 THEN
            RAISE EXCEPTION
                'Solo se permite una reprogramacion por cita';
        END IF;

        -- INCREMENTAR EL CONTADOR AUTOMATICAMENTE
        NEW.cantidad_reprogramaciones :=
            OLD.cantidad_reprogramaciones + 1;

    -- EVITAR MODIFICACIONES MANUALES DEL CONTADOR
    ELSIF NEW.cantidad_reprogramaciones
          IS DISTINCT FROM OLD.cantidad_reprogramaciones THEN

        RAISE EXCEPTION
            'No se permite modificar manualmente el contador';

    END IF;

    RETURN NEW;
END;
$$;


--
-- Name: fn_vencimiento_pago_efectivo(timestamp with time zone, date, time without time zone); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.fn_vencimiento_pago_efectivo(p_creacion timestamp with time zone, p_fecha_cita date, p_hora_cita time without time zone) RETURNS timestamp with time zone
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
    v_cita TIMESTAMPTZ;
    v_limite TIMESTAMPTZ;
    v_resultado TIMESTAMPTZ;
BEGIN

    -- VALIDAR PARAMETROS
    IF p_creacion IS NULL
       OR p_fecha_cita IS NULL
       OR p_hora_cita IS NULL THEN
        RETURN NULL;
    END IF;

    -- CALCULAR FECHA Y HORA DE LA CONSULTA
    v_cita := (p_fecha_cita + p_hora_cita)
        AT TIME ZONE 'America/La_Paz';

    -- LIMITE: 48 HORAS O 3 HORAS ANTES DE LA CITA
    v_limite := LEAST(
        p_creacion + INTERVAL '48 hours',
        v_cita - INTERVAL '3 hours'
    );

    -- COMPROBAR QUE EXISTA UN PLAZO VALIDO
    IF v_limite <= p_creacion THEN
        RETURN NULL;
    END IF;

    -- AJUSTAR AL HORARIO DE CAJA
    SELECT MAX(
        LEAST(
            v_limite,
            (g.d::date + TIME '18:00')
                AT TIME ZONE 'America/La_Paz'
        )
    )
    INTO v_resultado
    FROM generate_series(
        (p_creacion AT TIME ZONE 'America/La_Paz')::date::timestamp,
        (v_limite AT TIME ZONE 'America/La_Paz')::date::timestamp,
        INTERVAL '1 day'
    ) AS g(d)
    WHERE EXTRACT(ISODOW FROM g.d) BETWEEN 1 AND 5
      AND LEAST(
          v_limite,
          (g.d::date + TIME '18:00')
              AT TIME ZONE 'America/La_Paz'
      ) > GREATEST(
          p_creacion,
          (g.d::date + TIME '08:00')
              AT TIME ZONE 'America/La_Paz'
      );

    RETURN v_resultado;

END;
$$;


--
-- Name: registrar_cambio_cita(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.registrar_cambio_cita() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN

    -- COMPROBAR SI CAMBIO EL HORARIO O EL ESTADO
    IF (
        OLD.fecha,
        OLD.hora_inicio,
        OLD.hora_fin,
        OLD.estado
    ) IS DISTINCT FROM (
        NEW.fecha,
        NEW.hora_inicio,
        NEW.hora_fin,
        NEW.estado
    ) THEN

        -- GUARDAR EL CAMBIO EN EL HISTORIAL
        INSERT INTO historial_cambios_cita (
            id_cita,
            id_usuario,
            tipo_cambio,
            fecha_anterior,
            hora_inicio_anterior,
            hora_fin_anterior,
            fecha_nueva,
            hora_inicio_nueva,
            hora_fin_nueva,
            estado_anterior,
            estado_nuevo
        )
        VALUES (
            NEW.id_cita,
            NULLIF(
                current_setting('app.usuario_id', true),
                ''
            )::BIGINT,
            'actualizacion',
            OLD.fecha,
            OLD.hora_inicio,
            OLD.hora_fin,
            NEW.fecha,
            NEW.hora_inicio,
            NEW.hora_fin,
            OLD.estado,
            NEW.estado
        );

    END IF;

    RETURN NEW;

END;
$$;


--
-- Name: validar_cita(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.validar_cita() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_verificar BOOLEAN := FALSE;
    v_hoy DATE := (now() AT TIME ZONE 'America/La_Paz')::date;
    v_tope DATE;
BEGIN

    IF TG_OP = 'INSERT' THEN
        v_verificar := TRUE;
    ELSE
        IF OLD.estado IN ('atendida', 'cancelada', 'inasistencia')
           AND (NEW.estado, NEW.fecha, NEW.hora_inicio, NEW.hora_fin)
           IS DISTINCT FROM
               (OLD.estado, OLD.fecha, OLD.hora_inicio, OLD.hora_fin)
        THEN
            RAISE EXCEPTION
                'La cita finalizada no puede modificarse';
        END IF;

        v_verificar :=
            (NEW.fecha, NEW.hora_inicio, NEW.hora_fin,
             NEW.id_medico, NEW.id_especialidad)
            IS DISTINCT FROM
            (OLD.fecha, OLD.hora_inicio, OLD.hora_fin,
             OLD.id_medico, OLD.id_especialidad);
    END IF;

    IF v_verificar
       AND NEW.estado IN ('programada', 'confirmada')
    THEN

        -- PERMITIR SOLO LUNES A VIERNES
        IF EXTRACT(ISODOW FROM NEW.fecha) NOT BETWEEN 1 AND 5 THEN
            RAISE EXCEPTION
                'Solo se permiten citas de lunes a viernes';
        END IF;

        -- CALCULAR LOS PROXIMOS 10 DIAS HABILES
        SELECT MAX(fecha)
        INTO v_tope
        FROM (
            SELECT d::date AS fecha
            FROM generate_series(
                v_hoy::timestamp,
                (v_hoy + 20)::timestamp,
                INTERVAL '1 day'
            ) AS d
            WHERE EXTRACT(ISODOW FROM d) BETWEEN 1 AND 5
            ORDER BY fecha
            LIMIT 10
        ) habiles;

        IF NEW.fecha > v_tope THEN
            RAISE EXCEPTION
                'La cita excede la ventana de diez dias habiles';
        END IF;

        -- COMPROBAR FECHA Y HORA FUTURA
        IF (NEW.fecha + NEW.hora_inicio)
           <= (now() AT TIME ZONE 'America/La_Paz')
        THEN
            RAISE EXCEPTION 'La cita debe ser futura';
        END IF;

        -- VERIFICAR MEDICO ACTIVO
        IF NOT EXISTS (
            SELECT 1
            FROM medicos
            WHERE id_medico = NEW.id_medico
              AND estado = 'activo'
        ) THEN
            RAISE EXCEPTION 'El profesional no esta activo';
        END IF;

        -- VALIDAR HORARIO Y DURACION
        IF NOT EXISTS (
            SELECT 1
            FROM horarios_atencion h
            WHERE h.id_medico = NEW.id_medico
              AND h.estado = 'activo'
              AND h.dia_semana = EXTRACT(ISODOW FROM NEW.fecha)
              AND NEW.hora_inicio >= h.hora_inicio
              AND NEW.hora_fin <= h.hora_fin
              AND NEW.hora_fin - NEW.hora_inicio =
                  make_interval(mins => h.duracion_cita_minutos)
              AND MOD(
                  EXTRACT(EPOCH FROM (
                      NEW.hora_inicio - h.hora_inicio
                  ))::integer,
                  h.duracion_cita_minutos * 60
              ) = 0
        ) THEN
            RAISE EXCEPTION 'Fuera del horario configurado';
        END IF;

        -- COMPROBAR BLOQUEOS MEDICOS
        IF EXISTS (
            SELECT 1
            FROM bloqueos_horario b
            WHERE b.id_medico = NEW.id_medico
              AND b.fecha = NEW.fecha
              AND NEW.hora_inicio < b.hora_fin
              AND NEW.hora_fin > b.hora_inicio
        ) THEN
            RAISE EXCEPTION 'Horario bloqueado';
        END IF;

    END IF;

    RETURN NEW;
END;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: bloqueos_horario; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.bloqueos_horario (
    id_bloqueo bigint NOT NULL,
    id_medico bigint NOT NULL,
    fecha date NOT NULL,
    hora_inicio time without time zone NOT NULL,
    hora_fin time without time zone NOT NULL,
    motivo text,
    CONSTRAINT bloqueos_horario_check CHECK ((hora_fin > hora_inicio))
);


--
-- Name: bloqueos_horario_id_bloqueo_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.bloqueos_horario ALTER COLUMN id_bloqueo ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.bloqueos_horario_id_bloqueo_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: citas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.citas (
    id_cita bigint NOT NULL,
    id_paciente bigint NOT NULL,
    id_medico bigint NOT NULL,
    id_especialidad bigint NOT NULL,
    codigo_cita character varying(30) NOT NULL,
    fecha date NOT NULL,
    hora_inicio time without time zone NOT NULL,
    hora_fin time without time zone NOT NULL,
    estado character varying(15) DEFAULT 'programada'::character varying NOT NULL,
    motivo_consulta text,
    motivo_cancelacion text,
    fecha_registro timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    cantidad_reprogramaciones smallint DEFAULT 0 NOT NULL,
    CONSTRAINT citas_check CHECK ((hora_fin > hora_inicio)),
    CONSTRAINT citas_estado_check CHECK (((estado)::text = ANY (ARRAY[('programada'::character varying)::text, ('confirmada'::character varying)::text, ('atendida'::character varying)::text, ('cancelada'::character varying)::text, ('inasistencia'::character varying)::text]))),
    CONSTRAINT ck_citas_reprogramaciones CHECK (((cantidad_reprogramaciones >= 0) AND (cantidad_reprogramaciones <= 1)))
);


--
-- Name: citas_id_cita_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.citas ALTER COLUMN id_cita ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.citas_id_cita_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: comprobantes_pago; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.comprobantes_pago (
    id_comprobante bigint NOT NULL,
    id_pago bigint NOT NULL,
    numero_comprobante character varying(50) NOT NULL,
    fecha_emision timestamp with time zone DEFAULT now() NOT NULL,
    ruta_archivo text
);


--
-- Name: comprobantes_pago_id_comprobante_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.comprobantes_pago ALTER COLUMN id_comprobante ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.comprobantes_pago_id_comprobante_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: especialidades; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.especialidades (
    id_especialidad bigint NOT NULL,
    nombre character varying(100) NOT NULL,
    descripcion text,
    precio_consulta numeric(12,2) NOT NULL,
    estado character varying(10) DEFAULT 'activo'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT especialidades_estado_check CHECK (((estado)::text = ANY (ARRAY[('activo'::character varying)::text, ('inactivo'::character varying)::text]))),
    CONSTRAINT especialidades_precio_consulta_check CHECK ((precio_consulta > (0)::numeric))
);


--
-- Name: especialidades_id_especialidad_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.especialidades ALTER COLUMN id_especialidad ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.especialidades_id_especialidad_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: historial_cambios_cita; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.historial_cambios_cita (
    id_cambio bigint NOT NULL,
    id_cita bigint NOT NULL,
    id_usuario bigint,
    fecha_hora_cambio timestamp with time zone DEFAULT now() NOT NULL,
    tipo_cambio text NOT NULL,
    fecha_anterior date,
    hora_inicio_anterior time without time zone,
    hora_fin_anterior time without time zone,
    fecha_nueva date,
    hora_inicio_nueva time without time zone,
    hora_fin_nueva time without time zone,
    estado_anterior character varying(15),
    estado_nuevo character varying(15),
    observacion text
);


--
-- Name: historial_cambios_cita_id_cambio_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.historial_cambios_cita ALTER COLUMN id_cambio ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.historial_cambios_cita_id_cambio_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: horarios_atencion; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.horarios_atencion (
    id_horario bigint NOT NULL,
    id_medico bigint NOT NULL,
    dia_semana smallint NOT NULL,
    hora_inicio time without time zone NOT NULL,
    hora_fin time without time zone NOT NULL,
    duracion_cita_minutos integer DEFAULT 30 NOT NULL,
    estado character varying(10) DEFAULT 'activo'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT horarios_atencion_check CHECK ((hora_fin > hora_inicio)),
    CONSTRAINT horarios_atencion_dia_semana_check CHECK (((dia_semana >= 1) AND (dia_semana <= 5))),
    CONSTRAINT horarios_atencion_duracion_cita_minutos_check CHECK ((duracion_cita_minutos > 0)),
    CONSTRAINT horarios_atencion_estado_check CHECK (((estado)::text = ANY (ARRAY[('activo'::character varying)::text, ('inactivo'::character varying)::text])))
);


--
-- Name: horarios_atencion_id_horario_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.horarios_atencion ALTER COLUMN id_horario ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.horarios_atencion_id_horario_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: medico_especialidad; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.medico_especialidad (
    id_medico bigint NOT NULL,
    id_especialidad bigint NOT NULL
);


--
-- Name: medicos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.medicos (
    id_medico bigint NOT NULL,
    id_usuario bigint,
    codigo_medico character varying(30) NOT NULL,
    registro_profesional character varying(40) NOT NULL,
    nombres character varying(100) NOT NULL,
    apellidos character varying(100) NOT NULL,
    telefono character varying(30),
    correo_contacto character varying(160),
    estado character varying(10) DEFAULT 'activo'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT medicos_estado_check CHECK (((estado)::text = ANY (ARRAY[('activo'::character varying)::text, ('inactivo'::character varying)::text])))
);


--
-- Name: medicos_id_medico_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.medicos ALTER COLUMN id_medico ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.medicos_id_medico_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: notas_medicas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notas_medicas (
    id_nota bigint NOT NULL,
    id_cita bigint NOT NULL,
    id_medico bigint NOT NULL,
    fecha_registro timestamp with time zone DEFAULT now() NOT NULL,
    observaciones text NOT NULL
);


--
-- Name: notas_medicas_id_nota_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.notas_medicas ALTER COLUMN id_nota ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.notas_medicas_id_nota_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: notificaciones; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notificaciones (
    id_notificacion bigint NOT NULL,
    id_cita bigint NOT NULL,
    tipo text NOT NULL,
    destinatario text NOT NULL,
    asunto text NOT NULL,
    fecha_programada timestamp with time zone,
    fecha_envio timestamp with time zone,
    estado_envio character varying(20) DEFAULT 'pendiente'::character varying NOT NULL
);


--
-- Name: notificaciones_id_notificacion_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.notificaciones ALTER COLUMN id_notificacion ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.notificaciones_id_notificacion_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: pacientes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pacientes (
    id_paciente bigint NOT NULL,
    id_usuario bigint,
    codigo_paciente character varying(30) NOT NULL,
    documento_identidad character varying(30) NOT NULL,
    nombres character varying(100) NOT NULL,
    apellidos character varying(100) NOT NULL,
    fecha_nacimiento date,
    sexo character varying(30),
    telefono character varying(30),
    correo_contacto character varying(160),
    direccion text,
    estado character varying(10) DEFAULT 'activo'::character varying NOT NULL,
    contacto_emergencia_nombre text,
    contacto_emergencia_parentesco text,
    contacto_emergencia_telefono character varying(30),
    tipo_sangre character varying(5),
    alergias text,
    antecedentes_relevantes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT pacientes_estado_check CHECK (((estado)::text = ANY (ARRAY[('activo'::character varying)::text, ('inactivo'::character varying)::text])))
);


--
-- Name: pacientes_id_paciente_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.pacientes ALTER COLUMN id_paciente ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.pacientes_id_paciente_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: pagos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pagos (
    id_pago bigint NOT NULL,
    id_cita bigint NOT NULL,
    id_usuario_registro bigint,
    importe numeric(12,2) NOT NULL,
    moneda character varying(3) DEFAULT 'BOB'::character varying NOT NULL,
    metodo character varying(20) NOT NULL,
    estado character varying(20) DEFAULT 'pendiente'::character varying NOT NULL,
    fecha_registro timestamp with time zone DEFAULT now() NOT NULL,
    fecha_confirmacion timestamp with time zone,
    referencia character varying(100),
    CONSTRAINT ck_pago_fecha_confirmacion CHECK ((((estado)::text <> 'confirmado'::text) OR (fecha_confirmacion IS NOT NULL))),
    CONSTRAINT pagos_estado_check CHECK (((estado)::text = ANY (ARRAY[('pendiente'::character varying)::text, ('confirmado'::character varying)::text, ('rechazado'::character varying)::text, ('cancelado'::character varying)::text, ('reembolsado'::character varying)::text]))),
    CONSTRAINT pagos_importe_check CHECK ((importe > (0)::numeric)),
    CONSTRAINT pagos_metodo_check CHECK (((metodo)::text = ANY (ARRAY[('paypal_sandbox'::character varying)::text, ('qr_simulado'::character varying)::text, ('efectivo'::character varying)::text])))
);


--
-- Name: pagos_id_pago_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.pagos ALTER COLUMN id_pago ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.pagos_id_pago_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: permisos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permisos (
    id_permiso bigint NOT NULL,
    nombre character varying(100) NOT NULL,
    descripcion text
);


--
-- Name: permisos_id_permiso_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.permisos ALTER COLUMN id_permiso ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.permisos_id_permiso_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: recuperacion_contrasena; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.recuperacion_contrasena (
    id_recuperacion bigint NOT NULL,
    id_usuario bigint NOT NULL,
    token_hash text NOT NULL,
    fecha_expiracion timestamp with time zone NOT NULL,
    fecha_uso timestamp with time zone
);


--
-- Name: recuperacion_contrasena_id_recuperacion_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.recuperacion_contrasena ALTER COLUMN id_recuperacion ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.recuperacion_contrasena_id_recuperacion_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: registros_auditoria; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.registros_auditoria (
    id_registro bigint NOT NULL,
    id_usuario bigint,
    fecha_hora timestamp with time zone DEFAULT now() NOT NULL,
    operacion text NOT NULL,
    entidad_afectada text NOT NULL,
    id_entidad bigint NOT NULL,
    detalles text
);


--
-- Name: registros_auditoria_id_registro_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.registros_auditoria ALTER COLUMN id_registro ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.registros_auditoria_id_registro_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: reservas_provisionales; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.reservas_provisionales (
    id_reserva bigint NOT NULL,
    id_cita bigint NOT NULL,
    fecha_creacion timestamp with time zone DEFAULT now() NOT NULL,
    fecha_vencimiento timestamp with time zone NOT NULL,
    estado character varying(15) DEFAULT 'pendiente'::character varying NOT NULL,
    CONSTRAINT ck_reserva_vencimiento CHECK ((fecha_vencimiento > fecha_creacion)),
    CONSTRAINT reservas_provisionales_estado_check CHECK (((estado)::text = ANY (ARRAY[('pendiente'::character varying)::text, ('confirmada'::character varying)::text, ('expirada'::character varying)::text, ('cancelada'::character varying)::text])))
);


--
-- Name: reservas_provisionales_id_reserva_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.reservas_provisionales ALTER COLUMN id_reserva ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.reservas_provisionales_id_reserva_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: rol_permiso; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rol_permiso (
    id_rol bigint NOT NULL,
    id_permiso bigint NOT NULL
);


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    id_rol bigint NOT NULL,
    nombre character varying(50) NOT NULL,
    descripcion text
);


--
-- Name: roles_id_rol_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.roles ALTER COLUMN id_rol ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.roles_id_rol_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: transacciones_pago; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.transacciones_pago (
    id_transaccion bigint NOT NULL,
    id_pago bigint NOT NULL,
    proveedor character varying(50) NOT NULL,
    referencia_externa character varying(150),
    estado character varying(30) NOT NULL,
    fecha_evento timestamp with time zone DEFAULT now() NOT NULL,
    detalles jsonb DEFAULT '{}'::jsonb NOT NULL
);


--
-- Name: transacciones_pago_id_transaccion_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.transacciones_pago ALTER COLUMN id_transaccion ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.transacciones_pago_id_transaccion_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: tratamientos_recetas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tratamientos_recetas (
    id_tratamiento bigint NOT NULL,
    id_cita bigint NOT NULL,
    id_medico bigint NOT NULL,
    fecha_registro timestamp with time zone DEFAULT now() NOT NULL,
    descripcion text NOT NULL,
    indicaciones text
);


--
-- Name: tratamientos_recetas_id_tratamiento_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.tratamientos_recetas ALTER COLUMN id_tratamiento ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.tratamientos_recetas_id_tratamiento_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: usuarios; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usuarios (
    id_usuario bigint NOT NULL,
    id_rol bigint NOT NULL,
    nombre_usuario character varying(80) NOT NULL,
    correo_acceso character varying(160) NOT NULL,
    contrasena_hash text,
    estado character varying(10) DEFAULT 'inactivo'::character varying NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT usuarios_check CHECK ((((estado)::text <> 'activo'::text) OR (contrasena_hash IS NOT NULL))),
    CONSTRAINT usuarios_estado_check CHECK (((estado)::text = ANY (ARRAY[('activo'::character varying)::text, ('inactivo'::character varying)::text])))
);


--
-- Name: usuarios_id_usuario_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.usuarios ALTER COLUMN id_usuario ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.usuarios_id_usuario_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: bloqueos_horario bloqueos_horario_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bloqueos_horario
    ADD CONSTRAINT bloqueos_horario_pkey PRIMARY KEY (id_bloqueo);


--
-- Name: citas citas_codigo_cita_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.citas
    ADD CONSTRAINT citas_codigo_cita_key UNIQUE (codigo_cita);


--
-- Name: citas citas_id_medico_tsrange_excl; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.citas
    ADD CONSTRAINT citas_id_medico_tsrange_excl EXCLUDE USING gist (id_medico WITH =, tsrange((fecha + hora_inicio), (fecha + hora_fin), '[)'::text) WITH &&) WHERE (((estado)::text <> 'cancelada'::text));


--
-- Name: citas citas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.citas
    ADD CONSTRAINT citas_pkey PRIMARY KEY (id_cita);


--
-- Name: comprobantes_pago comprobantes_pago_id_pago_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobantes_pago
    ADD CONSTRAINT comprobantes_pago_id_pago_key UNIQUE (id_pago);


--
-- Name: comprobantes_pago comprobantes_pago_numero_comprobante_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobantes_pago
    ADD CONSTRAINT comprobantes_pago_numero_comprobante_key UNIQUE (numero_comprobante);


--
-- Name: comprobantes_pago comprobantes_pago_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobantes_pago
    ADD CONSTRAINT comprobantes_pago_pkey PRIMARY KEY (id_comprobante);


--
-- Name: especialidades especialidades_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.especialidades
    ADD CONSTRAINT especialidades_pkey PRIMARY KEY (id_especialidad);


--
-- Name: historial_cambios_cita historial_cambios_cita_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.historial_cambios_cita
    ADD CONSTRAINT historial_cambios_cita_pkey PRIMARY KEY (id_cambio);


--
-- Name: horarios_atencion horarios_atencion_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.horarios_atencion
    ADD CONSTRAINT horarios_atencion_pkey PRIMARY KEY (id_horario);


--
-- Name: medico_especialidad medico_especialidad_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medico_especialidad
    ADD CONSTRAINT medico_especialidad_pkey PRIMARY KEY (id_medico, id_especialidad);


--
-- Name: medicos medicos_codigo_medico_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medicos
    ADD CONSTRAINT medicos_codigo_medico_key UNIQUE (codigo_medico);


--
-- Name: medicos medicos_id_usuario_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medicos
    ADD CONSTRAINT medicos_id_usuario_key UNIQUE (id_usuario);


--
-- Name: medicos medicos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medicos
    ADD CONSTRAINT medicos_pkey PRIMARY KEY (id_medico);


--
-- Name: medicos medicos_registro_profesional_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medicos
    ADD CONSTRAINT medicos_registro_profesional_key UNIQUE (registro_profesional);


--
-- Name: notas_medicas notas_medicas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notas_medicas
    ADD CONSTRAINT notas_medicas_pkey PRIMARY KEY (id_nota);


--
-- Name: notificaciones notificaciones_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notificaciones
    ADD CONSTRAINT notificaciones_pkey PRIMARY KEY (id_notificacion);


--
-- Name: pacientes pacientes_codigo_paciente_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pacientes
    ADD CONSTRAINT pacientes_codigo_paciente_key UNIQUE (codigo_paciente);


--
-- Name: pacientes pacientes_documento_identidad_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pacientes
    ADD CONSTRAINT pacientes_documento_identidad_key UNIQUE (documento_identidad);


--
-- Name: pacientes pacientes_id_usuario_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pacientes
    ADD CONSTRAINT pacientes_id_usuario_key UNIQUE (id_usuario);


--
-- Name: pacientes pacientes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pacientes
    ADD CONSTRAINT pacientes_pkey PRIMARY KEY (id_paciente);


--
-- Name: pagos pagos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pagos
    ADD CONSTRAINT pagos_pkey PRIMARY KEY (id_pago);


--
-- Name: permisos permisos_nombre_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permisos
    ADD CONSTRAINT permisos_nombre_key UNIQUE (nombre);


--
-- Name: permisos permisos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permisos
    ADD CONSTRAINT permisos_pkey PRIMARY KEY (id_permiso);


--
-- Name: recuperacion_contrasena recuperacion_contrasena_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recuperacion_contrasena
    ADD CONSTRAINT recuperacion_contrasena_pkey PRIMARY KEY (id_recuperacion);


--
-- Name: registros_auditoria registros_auditoria_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.registros_auditoria
    ADD CONSTRAINT registros_auditoria_pkey PRIMARY KEY (id_registro);


--
-- Name: reservas_provisionales reservas_provisionales_id_cita_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reservas_provisionales
    ADD CONSTRAINT reservas_provisionales_id_cita_key UNIQUE (id_cita);


--
-- Name: reservas_provisionales reservas_provisionales_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reservas_provisionales
    ADD CONSTRAINT reservas_provisionales_pkey PRIMARY KEY (id_reserva);


--
-- Name: rol_permiso rol_permiso_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rol_permiso
    ADD CONSTRAINT rol_permiso_pkey PRIMARY KEY (id_rol, id_permiso);


--
-- Name: roles roles_nombre_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_nombre_key UNIQUE (nombre);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id_rol);


--
-- Name: transacciones_pago transacciones_pago_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transacciones_pago
    ADD CONSTRAINT transacciones_pago_pkey PRIMARY KEY (id_transaccion);


--
-- Name: tratamientos_recetas tratamientos_recetas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tratamientos_recetas
    ADD CONSTRAINT tratamientos_recetas_pkey PRIMARY KEY (id_tratamiento);


--
-- Name: usuarios usuarios_correo_acceso_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_correo_acceso_key UNIQUE (correo_acceso);


--
-- Name: usuarios usuarios_nombre_usuario_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_nombre_usuario_key UNIQUE (nombre_usuario);


--
-- Name: usuarios usuarios_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_pkey PRIMARY KEY (id_usuario);


--
-- Name: especialidad_nombre; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX especialidad_nombre ON public.especialidades USING btree (lower(btrim((nombre)::text)));


--
-- Name: idx_pagos_cita; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pagos_cita ON public.pagos USING btree (id_cita);


--
-- Name: idx_pagos_estado_fecha; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pagos_estado_fecha ON public.pagos USING btree (estado, fecha_registro);


--
-- Name: ux_pago_confirmado_por_cita; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_pago_confirmado_por_cita ON public.pagos USING btree (id_cita) WHERE ((estado)::text = 'confirmado'::text);


--
-- Name: ux_pago_metodo_referencia; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_pago_metodo_referencia ON public.pagos USING btree (metodo, referencia) WHERE (referencia IS NOT NULL);


--
-- Name: ux_transaccion_proveedor_referencia; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_transaccion_proveedor_referencia ON public.transacciones_pago USING btree (proveedor, referencia_externa) WHERE (referencia_externa IS NOT NULL);


--
-- Name: citas actualizar_fecha; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER actualizar_fecha BEFORE UPDATE ON public.citas FOR EACH ROW EXECUTE FUNCTION public.actualizar_updated_at();


--
-- Name: especialidades actualizar_fecha; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER actualizar_fecha BEFORE UPDATE ON public.especialidades FOR EACH ROW EXECUTE FUNCTION public.actualizar_updated_at();


--
-- Name: horarios_atencion actualizar_fecha; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER actualizar_fecha BEFORE UPDATE ON public.horarios_atencion FOR EACH ROW EXECUTE FUNCTION public.actualizar_updated_at();


--
-- Name: medicos actualizar_fecha; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER actualizar_fecha BEFORE UPDATE ON public.medicos FOR EACH ROW EXECUTE FUNCTION public.actualizar_updated_at();


--
-- Name: pacientes actualizar_fecha; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER actualizar_fecha BEFORE UPDATE ON public.pacientes FOR EACH ROW EXECUTE FUNCTION public.actualizar_updated_at();


--
-- Name: usuarios actualizar_fecha; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER actualizar_fecha BEFORE UPDATE ON public.usuarios FOR EACH ROW EXECUTE FUNCTION public.actualizar_updated_at();


--
-- Name: citas auditar_citas; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER auditar_citas AFTER INSERT OR DELETE OR UPDATE ON public.citas FOR EACH ROW EXECUTE FUNCTION public.auditar_cambios('id_cita');


--
-- Name: comprobantes_pago auditar_comprobantes_pago; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER auditar_comprobantes_pago AFTER INSERT OR DELETE OR UPDATE ON public.comprobantes_pago FOR EACH ROW EXECUTE FUNCTION public.auditar_cambios('id_comprobante');


--
-- Name: especialidades auditar_especialidades; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER auditar_especialidades AFTER INSERT OR DELETE OR UPDATE ON public.especialidades FOR EACH ROW EXECUTE FUNCTION public.auditar_cambios('id_especialidad');


--
-- Name: horarios_atencion auditar_horarios_atencion; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER auditar_horarios_atencion AFTER INSERT OR DELETE OR UPDATE ON public.horarios_atencion FOR EACH ROW EXECUTE FUNCTION public.auditar_cambios('id_horario');


--
-- Name: medicos auditar_medicos; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER auditar_medicos AFTER INSERT OR DELETE OR UPDATE ON public.medicos FOR EACH ROW EXECUTE FUNCTION public.auditar_cambios('id_medico');


--
-- Name: pacientes auditar_pacientes; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER auditar_pacientes AFTER INSERT OR DELETE OR UPDATE ON public.pacientes FOR EACH ROW EXECUTE FUNCTION public.auditar_cambios('id_paciente');


--
-- Name: pagos auditar_pagos; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER auditar_pagos AFTER INSERT OR DELETE OR UPDATE ON public.pagos FOR EACH ROW EXECUTE FUNCTION public.auditar_cambios('id_pago');


--
-- Name: reservas_provisionales auditar_reservas_provisionales; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER auditar_reservas_provisionales AFTER INSERT OR DELETE OR UPDATE ON public.reservas_provisionales FOR EACH ROW EXECUTE FUNCTION public.auditar_cambios('id_reserva');


--
-- Name: usuarios auditar_usuarios; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER auditar_usuarios AFTER INSERT OR DELETE OR UPDATE ON public.usuarios FOR EACH ROW EXECUTE FUNCTION public.auditar_cambios('id_usuario');


--
-- Name: citas controlar_reprogramacion; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER controlar_reprogramacion BEFORE UPDATE ON public.citas FOR EACH ROW EXECUTE FUNCTION public.fn_controlar_reprogramacion();


--
-- Name: citas historial_cita; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER historial_cita AFTER UPDATE ON public.citas FOR EACH ROW EXECUTE FUNCTION public.registrar_cambio_cita();


--
-- Name: citas validar_cita_antes; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER validar_cita_antes BEFORE INSERT OR UPDATE ON public.citas FOR EACH ROW EXECUTE FUNCTION public.validar_cita();


--
-- Name: bloqueos_horario bloqueos_horario_id_medico_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bloqueos_horario
    ADD CONSTRAINT bloqueos_horario_id_medico_fkey FOREIGN KEY (id_medico) REFERENCES public.medicos(id_medico);


--
-- Name: citas citas_id_medico_id_especialidad_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.citas
    ADD CONSTRAINT citas_id_medico_id_especialidad_fkey FOREIGN KEY (id_medico, id_especialidad) REFERENCES public.medico_especialidad(id_medico, id_especialidad);


--
-- Name: citas citas_id_paciente_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.citas
    ADD CONSTRAINT citas_id_paciente_fkey FOREIGN KEY (id_paciente) REFERENCES public.pacientes(id_paciente);


--
-- Name: comprobantes_pago comprobantes_pago_id_pago_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comprobantes_pago
    ADD CONSTRAINT comprobantes_pago_id_pago_fkey FOREIGN KEY (id_pago) REFERENCES public.pagos(id_pago);


--
-- Name: historial_cambios_cita historial_cambios_cita_id_cita_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.historial_cambios_cita
    ADD CONSTRAINT historial_cambios_cita_id_cita_fkey FOREIGN KEY (id_cita) REFERENCES public.citas(id_cita);


--
-- Name: historial_cambios_cita historial_cambios_cita_id_usuario_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.historial_cambios_cita
    ADD CONSTRAINT historial_cambios_cita_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.usuarios(id_usuario);


--
-- Name: horarios_atencion horarios_atencion_id_medico_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.horarios_atencion
    ADD CONSTRAINT horarios_atencion_id_medico_fkey FOREIGN KEY (id_medico) REFERENCES public.medicos(id_medico);


--
-- Name: medico_especialidad medico_especialidad_id_especialidad_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medico_especialidad
    ADD CONSTRAINT medico_especialidad_id_especialidad_fkey FOREIGN KEY (id_especialidad) REFERENCES public.especialidades(id_especialidad);


--
-- Name: medico_especialidad medico_especialidad_id_medico_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medico_especialidad
    ADD CONSTRAINT medico_especialidad_id_medico_fkey FOREIGN KEY (id_medico) REFERENCES public.medicos(id_medico);


--
-- Name: medicos medicos_id_usuario_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.medicos
    ADD CONSTRAINT medicos_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.usuarios(id_usuario);


--
-- Name: notas_medicas notas_medicas_id_cita_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notas_medicas
    ADD CONSTRAINT notas_medicas_id_cita_fkey FOREIGN KEY (id_cita) REFERENCES public.citas(id_cita);


--
-- Name: notas_medicas notas_medicas_id_medico_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notas_medicas
    ADD CONSTRAINT notas_medicas_id_medico_fkey FOREIGN KEY (id_medico) REFERENCES public.medicos(id_medico);


--
-- Name: notificaciones notificaciones_id_cita_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notificaciones
    ADD CONSTRAINT notificaciones_id_cita_fkey FOREIGN KEY (id_cita) REFERENCES public.citas(id_cita);


--
-- Name: pacientes pacientes_id_usuario_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pacientes
    ADD CONSTRAINT pacientes_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.usuarios(id_usuario);


--
-- Name: pagos pagos_id_cita_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pagos
    ADD CONSTRAINT pagos_id_cita_fkey FOREIGN KEY (id_cita) REFERENCES public.citas(id_cita);


--
-- Name: pagos pagos_id_usuario_registro_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pagos
    ADD CONSTRAINT pagos_id_usuario_registro_fkey FOREIGN KEY (id_usuario_registro) REFERENCES public.usuarios(id_usuario);


--
-- Name: recuperacion_contrasena recuperacion_contrasena_id_usuario_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.recuperacion_contrasena
    ADD CONSTRAINT recuperacion_contrasena_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.usuarios(id_usuario);


--
-- Name: registros_auditoria registros_auditoria_id_usuario_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.registros_auditoria
    ADD CONSTRAINT registros_auditoria_id_usuario_fkey FOREIGN KEY (id_usuario) REFERENCES public.usuarios(id_usuario);


--
-- Name: reservas_provisionales reservas_provisionales_id_cita_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.reservas_provisionales
    ADD CONSTRAINT reservas_provisionales_id_cita_fkey FOREIGN KEY (id_cita) REFERENCES public.citas(id_cita);


--
-- Name: rol_permiso rol_permiso_id_permiso_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rol_permiso
    ADD CONSTRAINT rol_permiso_id_permiso_fkey FOREIGN KEY (id_permiso) REFERENCES public.permisos(id_permiso);


--
-- Name: rol_permiso rol_permiso_id_rol_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rol_permiso
    ADD CONSTRAINT rol_permiso_id_rol_fkey FOREIGN KEY (id_rol) REFERENCES public.roles(id_rol);


--
-- Name: transacciones_pago transacciones_pago_id_pago_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.transacciones_pago
    ADD CONSTRAINT transacciones_pago_id_pago_fkey FOREIGN KEY (id_pago) REFERENCES public.pagos(id_pago);


--
-- Name: tratamientos_recetas tratamientos_recetas_id_cita_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tratamientos_recetas
    ADD CONSTRAINT tratamientos_recetas_id_cita_fkey FOREIGN KEY (id_cita) REFERENCES public.citas(id_cita);


--
-- Name: tratamientos_recetas tratamientos_recetas_id_medico_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tratamientos_recetas
    ADD CONSTRAINT tratamientos_recetas_id_medico_fkey FOREIGN KEY (id_medico) REFERENCES public.medicos(id_medico);


--
-- Name: usuarios usuarios_id_rol_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_id_rol_fkey FOREIGN KEY (id_rol) REFERENCES public.roles(id_rol);


--
-- PostgreSQL database dump complete
--

\unrestrict UIpRjvV5IDbLGtj6IdQkxRU9Ky6YfR7Hewgo3P75VyPwPnhNQujFqHntj8WNSJE
