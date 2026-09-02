# MCP server of in2mcp

in2mcp turns a TYPO3 installation into a **Model Context Protocol server**. An MCP client - Claude (web,
desktop, code), ChatGPT, Gemini CLI, Antigravity - connects to the endpoint, authenticates with the api key of a
TYPO3 backend user and then works on the CMS **with exactly the permissions of that user**.

---

## Setup

### 1. Activate the server

| Setting     | Description                                | Default |
|-------------|--------------------------------------------|---------|
| `mcpServer` | Answers MCP requests on `/typo3/mcp`       | `0`     |

The endpoint is `/typo3/mcp`. It lives in the **backend** context, because the tools need a backend user and
because a request to a path below `/typo3` must be answered before the backend routing rejects it.

### 2. Create an api key

Every backend user has its own api key in the field *MCP api key* of the backend user record, or via CLI:

```bash
vendor/bin/typo3 in2mcp:apikey <uid|username>     # creates and prints a new key once
vendor/bin/typo3 in2mcp:apikey <uid|username> -r  # revokes the key
```

The key is stored as a salted hash and can never be read back. Emptying the field or disabling the user revokes
the access immediately.

### 3. Connect a client

The server expects the key in one of these headers:

```
Authorization: Bearer <key>
X-Api-Key: <key>
Api-Key: <key>
```

`X-Api-Key` and `Api-Key` are fallbacks for clients that cannot set an authorization header and for webserver
configurations that do not pass it to php (apache with `mod_proxy_fcgi` without `CGIPassAuth On`).

**claude.ai / ChatGPT** (custom connector): set authentication to *None* and add the key under *Request headers*
using the header name `api-key` or `authorization` (with the value `Bearer <key>`).

**Claude Code**

```bash
claude mcp add --transport http in2mcp https://your-domain.org/typo3/mcp --header "Api-Key: <key>"
```

**Gemini CLI**

```bash
gemini mcp add in2mcp https://your-domain.org/typo3/mcp --transport http --header "X-Api-Key: <key>"
```

**Any client with a configuration file**

```json
{
  "mcpServers": {
    "in2mcp": {
      "type": "http",
      "url": "https://your-domain.org/typo3/mcp",
      "headers": { "Api-Key": "<key>" }
    }
  }
}
```

> **Gemini Enterprise** custom MCP connectors currently offer only *no authentication* or *OAuth 2.0* - they
> cannot send a static header. Connecting the Gemini Enterprise browser connector therefore needs an OAuth 2.1
> layer, which this extension does not implement yet.

A quick check on the command line:

```bash
curl -X POST https://your-domain.org/typo3/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'Api-Key: <key>' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1.0"}}}'
```

`Tests/Manual/mcpclient.sh` does the handshake and sends one request, for example:

```bash
MCP_URL=https://your-domain.org/typo3/mcp Tests/Manual/mcpclient.sh <key> \
  '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

---

## Tools

### Reading

| Tool                | Purpose                                                                        |
|---------------------|--------------------------------------------------------------------------------|
| `get_backend_user`  | Which user the connection acts as, its page mounts and its table permissions    |
| `get_page_tree`     | The page tree as a nested structure, rooted at the page mounts of the user      |
| `get_page`          | One page with all fields plus its content elements grouped by column position   |
| `search_pages`      | Pages by title, navigation title, subtitle or slug                              |
| `get_schema`        | Page types, content element types and the fields that exist in this installation |

### Writing

| Tool                     | Purpose                                              |
|--------------------------|------------------------------------------------------|
| `create_page`            | New page below a parent page                          |
| `update_page`            | Change fields of a page                               |
| `create_content_element` | New content element in a column of a page             |
| `update_content_element` | Change fields of a content element                    |
| `delete_record`          | Delete a page or content element (recoverable)        |
| `move_record`            | Move a page or content element                        |

`get_schema` exists because page types, content types and fields differ per installation. A client that asks
first does not have to guess field names - and unknown field names are rejected with a message that points back
to `get_schema` instead of being dropped silently.

---

## Permissions

There is no permission concept of its own. The api key identifies a backend user, that user is initialized like
in a regular backend request, and every read and write runs through the regular TYPO3 checks:

- **Reading** applies `getPagePermsClause()` **and** the page mounts of the user. Both are needed: a page can be
  readable by permission (`perms_everybody`) and still lie outside the tree of that user, exactly as in the
  backend page tree.
- **Writing** goes through the **DataHandler**, so page permissions, `tables_modify`, `non_exclude_fields`, web
  mounts, hooks, reference index, slug generation, workspaces and the record history all apply unchanged. A
  refused write is reported back to the client with the reason TYPO3 gave.
- New pages are created **hidden**, because that is the TCA default of TYPO3 for pages - a human decides when a
  page goes live.
- Every write is written to `sys_log` by the DataHandler, so the backend history shows what happened.

Revoking access means emptying the api key field or disabling the backend user.

### Rate limiting

Failing authentications are limited per remote address, 20 in 15 minutes by default. A successful
authentication resets the counter, so only failing requests count. Configurable with
`$GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter']['in2mcp']` (keys `limit` and `interval`).

### Error responses

| Status | Meaning                                                        |
|--------|----------------------------------------------------------------|
| `401`  | No or an invalid api key was sent                               |
| `429`  | Too many failed authentications from this ip address            |
| `405`  | Request method not supported (only `POST`, `DELETE`, `OPTIONS`) |

---

## Architecture

| Class                          | Task                                                                          |
|--------------------------------|-------------------------------------------------------------------------------|
| `Middleware\McpServer`         | Answers `/typo3/mcp` with the MCP server instead of the backend                |
| `ApiKeyAuthenticationService`  | TYPO3 authentication service, finds the backend user of the api key            |
| `BackendUserAuthenticator`     | Initializes that user for the request and removes the session afterwards       |
| `BackendUserRepository`        | Looks up the hashed api key of a backend user                                  |
| `RateLimiter\RateLimiterFactory`| Sliding window limiter for failing authentications                            |
| `Mcp\ServerFactory`            | Builds the MCP server and registers every tool                                 |
| `Mcp\Converter\McpToolSchemaConverter` | Turns a tool parameter definition into a json schema                   |
| `Mcp\Executer\ToolExecuter`    | Runs a tool for a `tools/call` and formats the result                          |
| `Mcp\Tool\ToolRegistry`        | Collects everything tagged `in2mcp.tool`                                       |
| `Service\DataHandlerService`   | The only place that writes - always through the DataHandler                     |
| `Service\BackendUserService`   | Single point of access to the authenticated user and its permissions            |
| `Service\TcaService`           | Answers what page types, content types and fields exist here                    |

The [official MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk) (`mcp/sdk`) handles the protocol.
Sessions are stored in the filesystem (`var/in2mcp/mcp`), because a client sends its session id in every request.

### Adding a tool

Implement `ToolInterface` (or extend `AbstractTool`) anywhere under `Classes/Domain/Mcp/Tool/`. The service
configuration tags every implementation as `in2mcp.tool` and the `ToolRegistry` picks it up - no registration
needed.

### Tool results

The `ToolExecuter` builds the result itself instead of letting the sdk do it, because the sdk would use any
array as `structuredContent` - including a list, which the MCP specification does not allow and which makes
clients fail schema validation. Therefore a list is wrapped into `{"result": [...]}`, a record is used as it is
and a scalar gets no `structuredContent` at all.
