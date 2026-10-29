#!/usr/bin/python3
import json
import re
import sys
from decimal import Decimal, InvalidOperation

from num2words import num2words


ABBREVIATIONS = {
    "av": "avenida",
    "avda": "avenida",
    "jr": "jiron",
    "nro": "numero",
    "num": "numero",
    "mz": "manzana",
    "lt": "lote",
    "urb": "urbanizacion",
    "dpto": "departamento",
    "pto": "puerto",
    "prol": "prolongacion",
    "carretera": "carretera",
    "km": "kilometro",
}


def clean_decimal(value):
    text = str(value or "").strip().replace(" ", "")
    if not text:
        raise ValueError("monto vacio")
    if "," in text and "." in text:
        if text.rfind(",") > text.rfind("."):
            text = text.replace(".", "").replace(",", ".")
        else:
            text = text.replace(",", "")
    elif "," in text:
        parts = text.split(",")
        text = "".join(parts[:-1]) + "." + parts[-1] if len(parts[-1]) <= 2 else "".join(parts)
    try:
        return Decimal(text)
    except InvalidOperation as exc:
        raise ValueError("monto invalido") from exc


def amount_to_words(value):
    amount = clean_decimal(value)
    if amount == amount.to_integral():
        return num2words(int(amount), lang="es")
    integer = int(amount)
    cents = int((amount - integer) * 100)
    return f"{num2words(integer, lang='es')} con {num2words(cents, lang='es')} centimos"


def expand_abbreviation(match):
    token = match.group(1).lower()
    return ABBREVIATIONS.get(token, token)


def address_to_words(value):
    text = str(value or "").strip()
    text = re.sub(r"\b(" + "|".join(map(re.escape, ABBREVIATIONS)) + r")\.?\b", expand_abbreviation, text, flags=re.I)
    text = re.sub(r"\b\d+\b", lambda match: num2words(int(match.group(0)), lang="es"), text)
    return re.sub(r"\s+", " ", text).strip()


def fix_sion_accents(text):
    """Adds accent to -cion/-sion endings: decision->decisión"""
    return re.sub(r'([csCS])ion\b', lambda m: m.group(1) + 'ión', str(text))


# --- Cabecera flexible (2026-08-05) ---------------------------------------
# Alias de cabecera reconocidos para decidir COMO normalizar una columna cuyo
# nombre lo pone el cliente. Ver UPDATE.MD seccion 3.3.
AMOUNT_ALIASES = {
    "monto", "amount", "importe", "deuda", "saldo",
    "precio", "total", "cuota", "valor",
}
ADDRESS_ALIASES = {
    "direccion", "address", "tienda", "domicilio",
    "local", "sucursal", "agencia",
}


def normalize_by_convention(field_name, value):
    """Normaliza un valor segun el NOMBRE de su columna.

    - nombre reconocido como monto   -> numero a palabras
    - nombre reconocido como direccion -> abreviaturas expandidas + numeros a palabras
    - cualquier otro nombre          -> se devuelve tal cual (sin normalizar)

    Nunca lanza excepcion: si el valor no se puede normalizar se devuelve el
    original. Esto es deliberado: un dato raro no debe tumbar la carga entera
    de una campana.
    """
    raw = str(value or "").strip()
    if not raw:
        return ""
    key = str(field_name or "").strip().lower()
    try:
        if key in AMOUNT_ALIASES:
            return fix_sion_accents(amount_to_words(raw))
        if key in ADDRESS_ALIASES:
            return fix_sion_accents(address_to_words(raw))
    except Exception:
        return raw
    return raw


def normalize_row(row):
    """Normaliza una fila para TTS.

    Retrocompatible: si llegan 'amount_raw' / 'store_address_raw' (contrato
    clasico usado por initial_survey y por la carga de 4 columnas), la salida
    es identica a la version anterior.

    Nuevo: tolera que esas claves NO existan o vengan vacias (carga de cabecera
    flexible, donde el archivo puede no traer monto ni direccion). Antes eso
    lanzaba ValueError('monto vacio') y abortaba la carga completa.

    Nuevo: si llega la clave 'extra_raw' (dict), devuelve 'extra' con cada
    campo normalizado por convencion de nombre.
    """
    amount_raw = str(row.get("amount_raw") or "").strip()
    address_raw = str(row.get("store_address_raw") or "").strip()

    if amount_raw:
        try:
            amount = fix_sion_accents(amount_to_words(amount_raw))
        except Exception:
            amount = amount_raw
    else:
        amount = ""

    if address_raw:
        try:
            store_address = fix_sion_accents(address_to_words(address_raw))
        except Exception:
            store_address = address_raw
    else:
        store_address = ""

    result = {
        **row,
        "amount": amount,
        "store_address": store_address,
    }

    extra_raw = row.get("extra_raw")
    if isinstance(extra_raw, dict):
        result["extra"] = {
            str(k): normalize_by_convention(k, v) for k, v in extra_raw.items()
        }

    return result


def main():
    rows = json.load(sys.stdin)
    if not isinstance(rows, list):
        raise ValueError("entrada debe ser lista")
    print(json.dumps([normalize_row(row) for row in rows], ensure_ascii=False))


if __name__ == "__main__":
    main()
