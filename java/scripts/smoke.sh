#!/usr/bin/env bash
# 对已启动的 Java 后端做兼容性冒烟。默认 http://127.0.0.1:8080
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
fail=0
check() {
  local name="$1" expect="$2"
  shift 2
  local body
  body="$(curl -sS "$@")" || { echo "FAIL $name curl"; fail=1; return; }
  if echo "$body" | grep -q "$expect"; then
    echo "OK   $name"
  else
    echo "FAIL $name"
    echo "$body" | head -c 400
    echo
    fail=1
  fi
}
check "login-get" '"captcha":false' "$BASE/admin/Index/login"
TOKEN="$(curl -sS -H 'Content-Type: application/json' -d '{"username":"admin","password":"NexusHive@123","keep":1}' "$BASE/admin/Index/login" | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"]["userInfo"]["token"])')"
echo "OK   login-post token=${TOKEN:0:8}..."
check "index" '"menus"' -H "batoken: $TOKEN" "$BASE/admin/Index/index"
check "project" '"list"' -H "batoken: $TOKEN" "$BASE/admin/Project/index?page=1&limit=10"
check "equipment" '"list"' -H "batoken: $TOKEN" "$BASE/admin/Equipment/index?page=1&limit=10"
check "airline" '"list"' -H "batoken: $TOKEN" "$BASE/admin/Airline/index?page=1&limit=10"
check "flighttask" '"list"' -H "batoken: $TOKEN" "$BASE/admin/Flighttask/index?page=1&limit=10"
check "dashboard" 'total_flight_distance' -H "batoken: $TOKEN" "$BASE/admin/Dashboard/osd"
check "api-osd" 'total_airline' "$BASE/api/Index/osd"
check "need-login" '"code":303' "$BASE/admin/Project/index"
check "health" '"status":"UP"' "$BASE/actuator/health"
code="$(curl -sS -o /dev/null -D - -X OPTIONS -H 'Origin: http://localhost' -H 'Access-Control-Request-Method: POST' "$BASE/admin/Index/login" | head -n 1)"
echo "$code" | grep -q 204 && echo "OK   options" || { echo "FAIL options $code"; fail=1; }
exit "$fail"
