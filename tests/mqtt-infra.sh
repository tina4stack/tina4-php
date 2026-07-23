#!/usr/bin/env bash
# Stand up every MQTT broker the Ruby specs drive. Idempotent: safe to re-run.
#
#   1883  Mosquitto, anonymous                  -> spec/mqtt_spec.rb, spec/mqtt_session_spec.rb
#   1884  Mosquitto, allow_anonymous false      -> the auth examples
#         + password_file
#   8883  Mosquitto, TLS with a self-signed CA  -> the mqtts:// examples
#   1885  EMQX 5.8                              -> the SUBACK 0x80 examples
#
# There are no mocks in the MQTT specs: a hand-rolled binary protocol can only be
# proven on the wire, so CI and a developer's machine run the same real brokers
# from this one script. Mirrors plan/v3/spikes/mqtt-infra.sh (the spike infra the
# design was proven against); kept in-repo so CI runs exactly what a checkout can.
#
# Usage:  sh spec/support/mqtt-infra.sh [dir]
#         (default dir: ${TMPDIR:-/tmp}/tina4-mqtt-infra — nothing lands in the repo)
set -e

DIR="${1:-${TMPDIR:-/tmp}/tina4-mqtt-infra}"
mkdir -p "$DIR/certs" "$DIR/conf"
cd "$DIR"

# ---------------------------------------------------------------- TLS material
# The CA needs basicConstraints AND keyUsage, or modern OpenSSL refuses to trust
# it as a CA at all ("CA cert does not include key usage extension"). The server
# certificate needs subjectAltName covering BOTH localhost and 127.0.0.1, because
# certificate identity is checked against whatever host the client dialled.
if [ ! -f certs/ca.crt ]; then
  cat > certs/ca.cnf <<'EOF'
[req]
distinguished_name=dn
x509_extensions=v3_ca
prompt=no
[dn]
CN=tina4-test-ca
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
  chmod 644 certs/*.key certs/*.crt
  echo "certs generated in $DIR/certs"
fi

# ---------------------------------------------------------------- mosquitto
# per_listener_settings is REQUIRED. Without it allow_anonymous and password_file
# are GLOBAL and the LAST listener wins, so the auth listener silently accepts
# anonymous clients and the auth examples pass for the wrong reason.
cat > conf/mosquitto.conf <<'EOF'
per_listener_settings true

listener 1883 0.0.0.0
allow_anonymous true

listener 1884 0.0.0.0
allow_anonymous false
password_file /mosquitto/config/passwd

listener 8883 0.0.0.0
allow_anonymous true
cafile /mosquitto/certs/ca.crt
certfile /mosquitto/certs/server.crt
keyfile /mosquitto/certs/server.key
EOF

# mosquitto_passwd hashes the file IN PLACE; the "$" marks an already-hashed file.
if ! grep -q '\$' conf/passwd 2>/dev/null; then
  printf 'tina4:testpass\n' > conf/passwd
  docker run --rm -v "$PWD/conf:/mosquitto/config" eclipse-mosquitto:2 \
    mosquitto_passwd -U /mosquitto/config/passwd >/dev/null 2>&1
  echo "password file hashed (tina4 / testpass)"
fi

docker rm -f tina4-mosquitto >/dev/null 2>&1 || true
docker run -d --name tina4-mosquitto \
  -p 1883:1883 -p 1884:1884 -p 8883:8883 \
  -v "$PWD/conf/mosquitto.conf:/mosquitto/config/mosquitto.conf" \
  -v "$PWD/conf/passwd:/mosquitto/config/passwd" \
  -v "$PWD/certs:/mosquitto/certs" \
  eclipse-mosquitto:2 >/dev/null

# ---------------------------------------------------------------- emqx
# Mosquitto can NEVER answer a SUBSCRIBE with 0x80: it enforces ACLs at DELIVERY
# time and hands the client a granted QoS, then silently delivers nothing.
# EMQX refuses at subscribe time, and its DEFAULT authorization already denies
# "#" and "$SYS/#", so no custom ACL is needed. NOTE: there is no emqx/emqx:5
# tag — 5.8 is the one that exists.
docker rm -f tina4-emqx >/dev/null 2>&1 || true
docker run -d --name tina4-emqx -p 1885:1883 emqx/emqx:5.8 >/dev/null

# ---------------------------------------------------------------- wait
# /dev/tcp rather than nc, so the script needs no extra tool installed.
printf 'waiting for brokers'
for port in 1883 1884 8883 1885; do
  tries=0
  while [ "$tries" -lt 60 ]; do
    if (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then break; fi
    printf '.'
    sleep 1
    tries=$((tries + 1))
  done
done
echo ""

failed=0
for port in 1883 1884 8883 1885; do
  if (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then
    echo "  $port UP"
  else
    echo "  $port DOWN"
    failed=1
  fi
done

if [ "$failed" -ne 0 ]; then
  echo "" >&2
  echo "One or more brokers never came up. Logs:" >&2
  docker logs tina4-mosquitto 2>&1 | tail -25 >&2
  docker logs tina4-emqx 2>&1 | tail -25 >&2
  exit 1
fi

echo ""
echo "export TINA4_TEST_MQTT_URL=mqtt://127.0.0.1:1883"
echo "export TINA4_TEST_MQTT_AUTH_URL=mqtt://127.0.0.1:1884"
echo "export TINA4_TEST_MQTT_TLS_URL=mqtts://127.0.0.1:8883"
echo "export TINA4_TEST_MQTT_EMQX_URL=mqtt://127.0.0.1:1885"
echo "export TINA4_TEST_MQTT_CA_FILE=$DIR/certs/ca.crt"
