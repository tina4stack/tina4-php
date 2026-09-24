#!/usr/bin/env bash
# Copyright (c) 2026 Code Infinity
# SPDX-License-Identifier: MPL-2.0
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.

# Stand up the TLS + AUTH mail servers the Messenger transport tests drive
# (tests/MessengerTlsTransportTest.php; vendored byte-for-byte in behaviour from
# tina4-ruby spec/support/mail-infra.sh, which first shipped it). Idempotent: safe to re-run. The plain GreenMail
# on 3025/3143 (auth disabled) is provisioned separately, as before.
#
#   4025  GreenMail SMTP, AUTH required (PLAIN / LOGIN)   -> auth accepted + refused
#   4465  GreenMail SMTPS, implicit TLS + AUTH             -> encryption "ssl"
#   4143  GreenMail IMAP, LOGIN required                   -> wrong-password negative
#   4993  GreenMail IMAPS, implicit TLS                    -> imap_encryption "tls"
#   4587  Mailpit SMTP, STARTTLS required + AUTH           -> encryption "starttls"
#   4825  Mailpit HTTP API                                 -> proves the STARTTLS mail arrived
#   4144  Dovecot IMAP, STARTTLS (any user, password pass) -> imap_encryption "starttls"
#
# Every server presents a certificate signed by a throwaway CA generated here, for
# DNS:localhost and IP:127.0.0.1. The tests trust that CA through SSL_CERT_FILE,
# so certificate verification stays ON; the negative examples prove a server is
# REFUSED when its CA is not trusted.
#
# There are no mocks in the mail tests: TLS and certificate verification are
# proven on the wire against real servers, here and in CI.
#
# Usage:  bash tests/mail-infra.sh [dir] [name-prefix]
#         dir defaults to ${TMPDIR:-/tmp}/tina4-mail-infra; nothing lands in the repo.
#         name-prefix (default tina4-mail) lets two checkouts share one docker host.
#         Prints the export lines for TINA4_TEST_MAIL_TLS_HOST / _CA_FILE at the end.
#         `bash tests/mail-infra.sh [dir] [name-prefix] down` removes them.
set -e

DIR="${1:-${TMPDIR:-/tmp}/tina4-mail-infra}"
PREFIX="${2:-tina4-mail}"
ACTION="${3:-up}"
USER_NAME="tina4"
USER_PASS="mail-secret"

if [ "$ACTION" = "down" ]; then
  docker rm -f "$PREFIX-greenmail" "$PREFIX-mailpit" "$PREFIX-dovecot" >/dev/null 2>&1 || true
  echo "removed $PREFIX-greenmail $PREFIX-mailpit $PREFIX-dovecot"
  exit 0
fi

mkdir -p "$DIR/certs"
cd "$DIR"

# ---------------------------------------------------------------- TLS material
# Same recipe as spec/support/mqtt-infra.sh: the CA needs basicConstraints AND
# keyUsage or modern OpenSSL will not treat it as a CA, and the server
# certificate covers BOTH localhost and 127.0.0.1 because identity is checked
# against whatever host the client dialled.
if [ ! -f certs/ca.crt ]; then
  cat > certs/ca.cnf <<'EOF'
[req]
distinguished_name=dn
x509_extensions=v3_ca
prompt=no
[dn]
CN=tina4-mail-test-ca
[v3_ca]
basicConstraints=critical,CA:TRUE
keyUsage=critical,keyCertSign,cRLSign
subjectKeyIdentifier=hash
EOF
  openssl req -x509 -newkey rsa:2048 -nodes -keyout certs/ca.key \
    -out certs/ca.crt -days 365 -config certs/ca.cnf >/dev/null 2>&1
  openssl req -newkey rsa:2048 -nodes -keyout certs/server.key \
    -out certs/server.csr -subj "/CN=localhost" >/dev/null 2>&1
  cat > certs/srv.ext <<'EOF'
basicConstraints=CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=DNS:localhost,IP:127.0.0.1
EOF
  openssl x509 -req -in certs/server.csr -CA certs/ca.crt -CAkey certs/ca.key \
    -CAcreateserial -out certs/server.crt -days 365 \
    -extfile certs/srv.ext >/dev/null 2>&1
  # GreenMail reads a PKCS#12 keystore. -legacy is NOT used: Java 11+ reads the
  # modern AES-based PKCS#12 that OpenSSL 3 writes by default.
  openssl pkcs12 -export -in certs/server.crt -inkey certs/server.key \
    -name greenmail -out certs/greenmail.p12 -passout pass:changeit >/dev/null 2>&1
  printf '%s:%s\n' "$USER_NAME" "$USER_PASS" > certs/mailpit-auth
  chmod 644 certs/*
  echo "certs generated in $DIR/certs"
fi

# ---------------------------------------------------------------- GreenMail (TLS + AUTH)
# greenmail.users pre-registers the one account, and auth is NOT disabled, so
# SMTP AUTH and IMAP LOGIN are really checked (a wrong password is refused).
docker rm -f "$PREFIX-greenmail" >/dev/null 2>&1 || true
docker run -d --name "$PREFIX-greenmail" \
  -p 127.0.0.1:4025:3025 -p 127.0.0.1:4465:3465 -p 127.0.0.1:4143:3143 -p 127.0.0.1:4993:3993 \
  -v "$DIR/certs:/certs:ro" \
  -e GREENMAIL_OPTS="-Dgreenmail.setup.test.all -Dgreenmail.hostname=0.0.0.0 \
-Dgreenmail.users=$USER_NAME:$USER_PASS@tina4.test \
-Dgreenmail.tls.keystore.file=/certs/greenmail.p12 -Dgreenmail.tls.keystore.password=changeit" \
  greenmail/standalone:2.1.3 >/dev/null

# ---------------------------------------------------------------- Mailpit (STARTTLS)
# --smtp-require-starttls refuses MAIL before STARTTLS, so a client that skipped
# the upgrade cannot pass by accident; the API on 8025 shows what arrived.
docker rm -f "$PREFIX-mailpit" >/dev/null 2>&1 || true
docker run -d --name "$PREFIX-mailpit" \
  -p 127.0.0.1:4587:1025 -p 127.0.0.1:4825:8025 \
  -v "$DIR/certs:/certs:ro" \
  axllent/mailpit:v1.27 \
  --smtp-tls-cert /certs/server.crt --smtp-tls-key /certs/server.key \
  --smtp-require-starttls --smtp-auth-file /certs/mailpit-auth >/dev/null

# ---------------------------------------------------------------- Dovecot (IMAP STARTTLS)
# The image's own config: static passdb (any user, password "pass"), ssl=yes
# with cert.pem/key.pem, so port 143 offers STARTTLS. Only the key pair is ours.
docker rm -f "$PREFIX-dovecot" >/dev/null 2>&1 || true
docker run -d --name "$PREFIX-dovecot" --platform linux/amd64 \
  -p 127.0.0.1:4144:143 \
  -v "$DIR/certs/server.crt:/etc/dovecot/cert.pem:ro" \
  -v "$DIR/certs/server.key:/etc/dovecot/key.pem:ro" \
  dovecot/dovecot:2.3.21 >/dev/null

# ---------------------------------------------------------------- wait
# Wait for a GREETING, not just an open port: docker-proxy accepts connections
# before the server inside is ready.
ports="4025 4143 4587 4144"
printf 'waiting for mail servers'
for port in $ports; do
  tries=0
  while [ "$tries" -lt 90 ]; do
    if greeting=$( (exec 3<>"/dev/tcp/127.0.0.1/$port" && head -c 3 <&3) 2>/dev/null ) \
       && { [ "$greeting" = "220" ] || [ "$greeting" = "* O" ]; }; then
      break
    fi
    printf '.'
    sleep 1
    tries=$((tries + 1))
  done
done
echo ""

failed=0
for port in $ports 4465 4993 4825; do
  if (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then
    echo "  $port UP"
  else
    echo "  $port DOWN"
    failed=1
  fi
done

if [ "$failed" -ne 0 ]; then
  echo "" >&2
  echo "One or more mail servers never came up. Logs:" >&2
  for name in greenmail mailpit dovecot; do
    docker logs "$PREFIX-$name" 2>&1 | tail -20 >&2
  done
  exit 1
fi

echo ""
# Two names only (the canonical TINA4_TEST_* list, ADR-0038): the ports and the
# account above are fixed by this script, and spec/mail_transport_spec.rb uses
# the same fixed values.
echo "export TINA4_TEST_MAIL_TLS_HOST=127.0.0.1"
echo "export TINA4_TEST_MAIL_TLS_CA_FILE=$DIR/certs/ca.crt"
