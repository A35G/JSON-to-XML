#!/usr/bin/env bash
# Prova la CLI installata come dipendenza (vendor/bin/json-to-xml).
set -u

fail() {
    echo "FAIL: $1" >&2
    exit 1
}

BIN=vendor/bin/json-to-xml
[ -e "$BIN" ] || fail "$BIN non esiste: il pacchetto non espone la CLI."

# 1. JSON -> XML: l'intero oltre int64 deve restare intero.
out=$(printf '%s' '{"id": 12345678901234567890}' | "$BIN" to-xml --root=dati) \
    || fail "to-xml ha restituito un exit code diverso da zero."
case "$out" in
    *"<id>12345678901234567890</id>"*) echo "OK   to-xml: intero oltre int64 preservato" ;;
    *) fail "output di to-xml inatteso: $out" ;;
esac

# 2. XML -> JSON.
out=$(printf '%s' '<data><nome>Mario</nome></data>' | "$BIN" to-json) \
    || fail "to-json ha restituito un exit code diverso da zero."
case "$out" in
    *'"nome": "Mario"'*) echo "OK   to-json: conversione corretta" ;;
    *) fail "output di to-json inatteso: $out" ;;
esac

# 3. JSON non valido -> exit code 3.
printf '%s' '{json non valido}' | "$BIN" to-xml >/dev/null 2>&1
code=$?
[ "$code" -eq 3 ] || fail "JSON non valido: atteso exit code 3, ottenuto $code."
echo "OK   JSON non valido: exit code 3"

# 4. Entità DOCTYPE in un attributo -> exit code 3.
printf '%s' '<?xml version="1.0"?><!DOCTYPE q [<!ENTITY e "v">]><q a="&e;"/>' | "$BIN" to-json >/dev/null 2>&1
code=$?
[ "$code" -eq 3 ] || fail "Entità in un attributo: atteso exit code 3, ottenuto $code."
echo "OK   entità DOCTYPE in un attributo: exit code 3"

echo "Smoke test completato."