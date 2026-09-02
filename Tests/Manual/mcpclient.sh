#!/bin/bash
# Kleiner MCP-Client für die Kommandozeile: handshake + beliebiger Aufruf
KEY="$1"; shift
BASE="${MCP_URL:-https://in2mcp.ddev.site/typo3/mcp}"
H=$(mktemp)
curl -sk -D "$H" -o /dev/null -X POST "$BASE" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -H "Api-Key: $KEY" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"mcptest","version":"1.0"}}}'
SID=$(grep -i '^mcp-session-id' "$H" | tr -d '\r' | cut -d' ' -f2)
rm -f "$H"
[ -z "$SID" ] && { echo "Keine Session erhalten (Auth fehlgeschlagen?)"; exit 1; }
call() {
  curl -sk -X POST "$BASE" -H 'Content-Type: application/json' \
    -H 'Accept: application/json, text/event-stream' -H "Api-Key: $KEY" \
    -H "Mcp-Session-Id: $SID" -H 'MCP-Protocol-Version: 2025-06-18' -d "$1"
}
call '{"jsonrpc":"2.0","method":"notifications/initialized"}' >/dev/null
call "$1"
