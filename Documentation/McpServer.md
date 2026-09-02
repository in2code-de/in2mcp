# MCP server of in2mcp

in2mcp turns a TYPO3 installation into a **Model Context Protocol server**. An MCP client - Claude (web,
desktop, code), ChatGPT, Gemini CLI, Antigravity - connects to the endpoint, authenticates with the api key of a
TYPO3 backend user and then works on the CMS **with exactly the permissions of that user**.

---

## Setup

### 1. Activate the server

| Setting         | Description                                  | Default |
|-----------------|----------------------------------------------|---------|
| `mcpServer`     | Answers MCP requests on the endpoint         | `0`     |
| `mcpServerPath` | Path of the endpoint below the TYPO3 backend | `mcp`   |

The endpoint is `/typo3/mcp` by default and is configured with `mcpServerPath`, which is a path **below the
TYPO3 backend**: `mcp` answers on `/typo3/mcp`, `intern/mcp-a7f3` answers on `/typo3/intern/mcp-a7f3`. A
customised `$GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint']` is followed automatically, and an empty or invalid
value falls back to `mcp`.

The endpoint cannot be moved out of the backend, and that is deliberate. TYPO3 decides by the entry point
whether a request is a backend request, and that decision is what makes `StoragePermissionsAspect` apply the
file mounts and file permissions of the user. An endpoint below the frontend would either never reach this
middleware at all or, if it did, run without those checks.

Renaming the endpoint hides it from someone scanning for known paths. It is not a secret and not a
replacement for the api key - treat it as one less thing an automated scanner finds, nothing more.

It lives in the **backend** context, because the tools need a backend user and
because a request to a path below `/typo3` must be answered before the backend routing rejects it.

### 2. Create an api key

Every backend user has its own api key in the field *MCP api key* of the backend user record, or via CLI:

```bash
vendor/bin/typo3 in2mcp:apikey <uid|username>     # creates and prints a new key once
vendor/bin/typo3 in2mcp:apikey <uid|username> -r  # revokes the key
```

The key is stored as a salted hash and can never be read back. Emptying the field or disabling the user revokes
the access immediately.

The generated key uses a url safe alphabet (`A-Z a-z 0-9 - _`), so it needs no quoting in a shell, a url or a
json file. The CLI prints it on its own unwrapped line: a key copied from a wrapped terminal line carries the
line break with it and is rejected by clients as an invalid header value.

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
| `get_schema`        | Page types, content element types, writable tables and the fields of a table    |
| `get_record`        | One record of any table                                                         |
| `find_records`      | Records of a table by page, by field values and by language                     |
| `search_files`      | Files of the file abstraction layer by name, path or title                      |

### Writing

| Tool                     | Purpose                                              |
|--------------------------|------------------------------------------------------|
| `create_page`            | New page below a parent page                                     |
| `update_page`            | Change fields of a page                                          |
| `create_content_element` | New content element in a column of a page or inside a container  |
| `update_content_element` | Change fields of a content element                               |
| `create_record`          | New record of any other table, for example a form or a news entry |
| `add_child_record`       | New record inside an inline field of another record               |
| `localize_record`        | Translation of a record in a language                             |
| `clear_cache`            | Clear the cache so changes show up in the frontend                |
| `update_record`          | Change fields of a record of any table                           |
| `add_file_from_url`      | Download a file from a public url into the file storage           |
| `add_file_reference`     | Connect an existing file to a file field of a record             |
| `delete_record`          | Delete a record of any table (recoverable)                       |
| `move_record`            | Move a record, including into another column or container        |

`get_schema` exists because page types, content types, tables and fields differ per installation. A client that
asks first does not have to guess field names - and unknown field names are rejected with a message that points
back to `get_schema` instead of being dropped silently. Call it with a `table` to get the fields of that table
before writing a record with `create_record`.

### Records inside other records

An inline field - the pages of a form, the fields of such a page - is two things at once: its database column
holds the **number** of children, the DataHandler expects the **list of their uids**. Reading "2" and writing
"3" therefore does not add a child, it attaches the record with uid 3 to this parent and takes it away from
wherever it belonged.

in2mcp closes that trap from both sides. Every read replaces the counter with the real list, so `get_record`
answers `"pages": "124,125"` and never `"pages": 2`. Every write is checked against the children of that parent
and refused with the reason when it would steal a foreign record. And `add_child_record` creates a child the way
the backend does, writing the child and the list of the parent in one DataHandler run - which is what keeps the
counter correct. A child created with `create_record` alone leaves the parent at zero, and a parent whose
counter says zero has no children as far as Extbase is concerned, however many records point at it.

### Sorting

`sorting` is readable on every record and on the content elements of `get_page`, so a client can tell in which
order records actually are. It cannot be written: the sorting is the result of a position, and the position is
set with `move_record`. Moving into a page appends at the end by default, `"position": "start"` puts the record
first.

### Content elements in containers

Container extensions such as `b13/container` nest content elements: a child carries the uid of its container in
`tx_container_parent`, and the column numbers of a container are reused by **every** container of the page. The
column alone therefore does not describe a position, which is why both tools take the container explicitly:

- `create_content_element` places an element inside a container with `containerParentUid` plus the `colPos` of
  the target column within that container. Appending looks for the last element of that column **in that
  container**, so a second container on the same page does not attract the new element.
- `move_record` writes `colPos` and `containerParentUid` along with the move. The move target only decides the
  position in the sorting; column and container are ordinary fields and stay untouched when they are not given.

The uid of a container is a valid `afterContentUid` and a valid `afterRecordUid`: the element is then placed
behind the whole container, not in front of its children. This only works because every child of a container is
sorted **after** its container element - the first child of an empty container column is therefore anchored
behind the container itself, never at the top of the page.

### Files

`search_files` finds files that already exist in this installation and returns the file uid that
`add_file_reference` needs. Existing files of a field are kept, a new one is appended. Which fields accept a file
is visible in `get_schema` as fields of type `file`.

`add_file_from_url` brings a file that is not here yet into the storage. It is **switched off by default**: the
server, not the client, performs the download, so this has to be a decision of the installation.

| Setting                  | Purpose                                                              | Default    |
|--------------------------|----------------------------------------------------------------------|------------|
| `fileImport`             | Allow the import at all                                              | `0`        |
| `fileImportMaximumSize`  | Maximum file size in bytes, larger downloads are aborted             | `10485760` |
| `fileImportAllowedHosts` | Comma separated hosts a file may come from, empty allows every host  | empty      |

What the import refuses, in this order:

1. everything but `http` and `https`
2. hosts outside `fileImportAllowedHosts`, when that list is filled
3. hosts that resolve to a local, private, link local or carrier grade NAT address - **every** address a host
   resolves to has to be public, and every redirect hop is validated again, so a public url cannot redirect the
   server into the internal network
4. more than three redirects, and relative redirect targets
5. a `Content-Length` above the maximum, and a body that exceeds it while streaming
6. file names with a path in them, and names the `fileDenyPattern` of the installation rejects

Afterwards the file goes into the storage through `ResourceStorage::addFile()`, which applies what the backend
applies to an upload: the file operation permissions of the user (`addFile`), the **file mounts**, the writable
flag of the storage, the `fileDenyPattern` again and the file name sanitizing of the driver. An existing file of
the same name is never overwritten, the new one is renamed. The import is written to the file section of
`sys_log`, next to the uploads of the backend, and a refused url is logged there as a security notice.

---

## Security

The endpoint is reachable without a session, so it is treated as hostile until the api key proves otherwise.

**The key names its user.** An api key has the form `<uid>.<secret>`; only the secret is stored, as a salted
hash. Without the user part the server would have to verify an incoming key against the hash of every user that
has one, and hashing is deliberately slow - that turns an unauthenticated endpoint into a lever whose cost grows
with every editor who gets a key. One request now costs exactly one hash verification.

**Guessing a key is not a threat that is defended against, because it is not one.** The secret is 96 random
bytes, so there is nothing an attacker can do with more attempts. What the endpoint does defend against is
everything that comes with *having* to answer wrong keys:

- **Failing authentications are rate limited** per remote address (20 in 15 minutes by default, configurable via
  `$GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter']['in2mcp']`). A successful authentication resets the
  counter. `X-Forwarded-For` is only trusted when `reverseProxyIP` is configured, so the address cannot be
  spoofed - but an installation behind a proxy **without** that setting sees every client as one address and
  shares one limit between them.
- **A wrong key always costs exactly one hash verification**, whether the named user has a key, has none or does
  not exist at all. Otherwise the answer time would tell an attacker which backend users an api key was created
  for. The hash an unknown key is verified against is generated by the installation itself and kept in the hash
  cache, so it costs the same as a real one.

The realistic risk is not the key being guessed but the key being **leaked** - it lives in the configuration
file of a MCP client, in plain text. Treat it like a password: one key per person, and create a new one with
`in2mcp:apikey` whenever a machine is lost or a client configuration is shared. Emptying the field or disabling
the backend user revokes it immediately.

**A key is only as valid as its user.** Disabling, deleting or time-limiting the backend user revokes the access
immediately, because the regular TYPO3 authentication chain runs afterwards.

**Multi factor authentication is skipped** for MCP requests - a client without a browser cannot solve it, and
the key itself is the credential. An installation that enforces MFA should know that an api key is a way past
it, and should hand keys out accordingly.

**Content is written through the DataHandler**, so it is transformed exactly as a backend save transforms it.
Rich text goes through the `RteHtmlParser` of the installation, and a `CType` the user is not allowed to use is
refused by TYPO3 - which also means that raw HTML stays raw for a user who may use the `html` content element,
and gets sanitized for everybody else. in2mcp adds no sanitizing of its own; doing so would silently break the
content elements that are meant to carry markup.

**The one outgoing request** the extension can make is `add_file_from_url`, which is off by default. See
[Files](#files) for what it refuses.

---

## Permissions

There is no permission concept of its own. The api key identifies a backend user, that user is initialized like
in a regular backend request, and every read and write runs through the regular TYPO3 checks:

- **Reading** applies `getPagePermsClause()` **and** the page mounts of the user. Both are needed: a page can be
  readable by permission (`perms_everybody`) and still lie outside the tree of that user, exactly as in the
  backend page tree. An empty list of page mounts never means "no restriction": for an administrator it is the
  whole tree, for everybody else it is no page at all.
- **Writing** goes through the **DataHandler**, so page permissions, `tables_modify`, `non_exclude_fields`, web
  mounts, hooks, reference index, slug generation, workspaces and the record history all apply unchanged. A
  refused write is reported back to the client with the reason TYPO3 gave.
- New pages are created **hidden**, because that is the TCA default of TYPO3 for pages - a human decides when a
  page goes live.
- Every write is written to `sys_log` by the DataHandler, so the backend history shows what happened.

- **Files** follow the **file mounts** of the user, not the table permissions of `sys_file`. An editor browses
  files in the filelist module without that table ever appearing in `tables_select`, so using it as the gate
  would either lock every editor out or, once an integrator adds it, hand out the file inventory of the whole
  installation. `search_files` and `add_file_reference` therefore restrict themselves to the file mounts; an
  administrator sees everything, a user without a mount sees nothing.
- **Clearing the cache** of a single page needs that page to be in a page mount. The DataHandler itself checks
  nothing there - any uid clears any page - so in2mcp answers that question. Flushing the whole page cache or
  every cache follows `options.clearCache.pages` and `options.clearCache.all` of the user TSconfig, and a
  refusal is reported instead of silently doing nothing.
- Tables that hold authentication data or internal bookkeeping are refused for **every** user, administrators
  included: `be_users`, `be_groups`, `be_sessions`, `fe_users`, `fe_groups`, `fe_sessions`, `sys_history`,
  `sys_log`, `sys_refindex`, `sys_registry`, `sys_file_processedfile` and `sys_lockedrecords`. This is the one
  deliberate deviation from "the client may do what the user may do": an api key that leaked must not be able to
  create a backend user. The list is `TableAccessService::DENIED_TABLES`.

Everything else follows `tables_modify` of the backend user, so `get_schema` reports the writable tables of
exactly this connection.

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
| `Middleware\McpServer`         | Answers the configured endpoint with the MCP server instead of the backend     |
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
| `Service\TableAccessService`   | Decides which tables may be read and written at all                             |
| `Repository\RecordRepository`  | Reads records of any table                                                      |
| `Repository\FileRepository`    | Reads files and the references that point at them                               |
| `Service\FileImportService`    | Downloads a file into the storage, streamed and size limited                    |
| `Service\UrlValidationService` | The only guard on the one outgoing request this extension makes                 |
| `Service\InlineRelationService`| Translates between the child counter and the list of child uids                 |

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
