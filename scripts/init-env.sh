#!/bin/sh
set -eu

if [ -f .env ]; then
  echo ".env already exists; leaving it unchanged."
  exit 0
fi

cp .env.example .env

replace_value() {
  key="$1"
  value="$2"
  awk -v key="$key" -v value="$value" '
    BEGIN { replaced = 0 }
    index($0, key "=") == 1 { print key "=" value; replaced = 1; next }
    { print }
    END { if (!replaced) print key "=" value }
  ' .env > .env.tmp
  mv .env.tmp .env
}

app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
postgres_password="$(openssl rand -hex 24)"
redis_password="$(openssl rand -hex 24)"
reverb_key="$(openssl rand -hex 16)"
reverb_secret="$(openssl rand -hex 32)"

replace_value APP_KEY "$app_key"
replace_value POSTGRES_PASSWORD "$postgres_password"
replace_value REDIS_PASSWORD "$redis_password"
replace_value REVERB_APP_KEY "$reverb_key"
replace_value REVERB_APP_SECRET "$reverb_secret"
replace_value NEXT_PUBLIC_REVERB_WS_URL "ws://localhost/app/$reverb_key?protocol=7&client=js&version=8.4.0&flash=false"

chmod 600 .env
echo "Created .env with random application, database, Redis, and Reverb secrets."
