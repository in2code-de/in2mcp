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

The key is stored as a salted hash and can never be read back. A key typed into the backend field by hand has to
be at least 64 characters long (password policy `in2mcp_apikey`, which an installation can override in
`$GLOBALS['TYPO3_CONF_VARS']['SYS']['passwordPolicies']`) - the generator of the field creates 128. Emptying the
field or disabling the user revokes the access immediately.

**An editor additionally needs the permission of a backend group.** A key alone is refused unless one of the
groups of the user has the option *Use MCP clients with an api key* under *Access Lists > Custom module options >
MCP server (in2mcp)* (`custom_options` `in2mcp:access`). Administrators always may. This keeps MCP a decision of
the integrator per group, not a side effect of somebody creating a key.

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
| `get_page_tree`     | The page tree as a nested structure, rooted at the page mounts of the user, at most 500 pages and depth 10 (`truncated` tells when it was cut) |
| `get_page`          | One page with all fields plus its content elements grouped by column position   |
| `search_pages`      | Pages by title, navigation title, subtitle or slug                              |
| `get_schema`        | Page types, content element types of a page, writable tables, fields and values |
| `get_record`        | One record of any table                                                         |
| `find_records`      | Records of a table by page, by field values and by language                     |
| `search_files`      | Files of the file abstraction layer by name, path or title                      |
| `get_recently_changed_pages`   | Pages changed most recently (properties, translations or content), optionally below one page |
| `get_recently_changed_content` | Content elements changed most recently with their page, optionally below one page            |
| `get_backend_users`  | Administrators only: backend users with last login, active MFA providers and last change   |
| `get_recent_changes` | Administrators only: newest entries of the record history - who changed which record when |
| `get_workspaces`     | Live and the workspaces the user may enter, with the current one                          |

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
| `delete_file`            | Delete a file that is not used anymore, through the file storage |
| `delete_record`          | Delete a record of any table (recoverable)                       |
| `move_record`            | Move a record, including into another column or container        |
| `switch_workspace`       | Change the workspace, like the workspace selector of the backend  |

`get_schema` exists because page types, content types, tables and fields differ per installation. A client that
asks first does not have to guess field names - and unknown field names are rejected with a message that points
back to `get_schema` instead of being dropped silently. Call it with a `table` to get the fields of that table
before writing a record with `create_record`.

### Content element types per page

The TCA lists every content element type that is registered in the installation, but an integrator narrows that
list per page with `TCEFORM.tt_content.CType` in the page TSconfig - often drastically, for example by removing
the core types `text` or `header` in favour of a single `textmedia`. The backend form applies this configuration,
the DataHandler does not, so a client that only knows the TCA list would create elements an editor could never
have created.

Call `get_schema` with the `pageUid` of the page you are going to work on. `contentTypes` then contains only what
an editor can choose on that page: `addItems`, `keepItems` and `removeItems` are applied in the same order as the
backend form does (`keepItems`, then `addItems`, then `removeItems`). Without `pageUid` the unrestricted list of
the TCA is returned.

`defaultContentType` is the type the backend preselects for a new element on that page: `TCAdefaults` of the page
TSconfig, then of the user TSconfig, then the default of the TCA - the order in which the backend form
initializes a new record. A default that is not available on the page is replaced by the first type that is.
It is the type of choice when a client simply wants to put text on a page.

A content type that is not available on the page is refused, whichever tool writes it - `create_content_element`,
`update_content_element`, `create_record` or `update_record`. TYPO3 itself does not check this: the backend form
hides such types, the DataHandler writes them anyway. The error lists the types that are available instead. Only
a change of the type is checked, so an element whose type was hidden from editors after it was created stays
editable, as it does in the backend form.

### Values of select fields

Every select, radio and multi checkbox field lists the values an editor can choose as `items`, so a client can
write `imageorient`, `frame_class` or a select of its own extension without guessing:

```json
"imageorient": {
    "type": "select",
    "label": "Position and Alignment",
    "required": false,
    "items": [
        {"value": 0, "label": "Above, center"},
        {"value": 8, "label": "Below, center"},
        {"value": 125, "label": "Next to text, centered right"}
    ]
}
```

The value is what is written, never the label. The values of a multi checkbox are the bits of the stored bitmask
(1, 2, 4, ...) and are combined by adding them up. The values of `CType` and `doktype` are not repeated here, they
are `contentTypes` and `pageTypes`. Select fields that point to records of another table (`foreign_table`) list
no items - they hold records, not a fixed choice.

The values are built the way the backend form builds them:

1. the items of the TCA, with the `columnsOverrides` of the record type
2. the `itemsProcFunc` of the field, called through the core `ItemProcessingService` with a new record of the
   requested type on the requested page - a function that fails without a real backend form request falls back
   to the items of the TCA
3. `keepItems`, `addItems`, `removeItems` and `altLabels` of `TCEFORM.<table>.<field>` in the page TSconfig of
   `pageUid`, with `TCEFORM.<table>.<field>.types.<recordType>` merged over it
4. the `authMode` of the field for the backend user

Labels are translated into the backend language of the user. A field that is switched off with
`TCEFORM.<table>.<field>.disabled` on that page is marked `"disabled": true` and lists no items.

Because the values often differ per content type - one type may offer other image positions than another - pass
the type as `recordType`: `get_schema` with `"pageUid": 12, "recordType": "textmedia"` returns only the fields of
`textmedia` with exactly the values an editor sees for such an element on page 12. `recordType` belongs to
`table`; without `table` it is the content type of the `tt_content` fields. For `pages` it is the page type, for
example `"1"`.

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

A file field is the same kind of field: its children are the records in `sys_file_reference`, so it reads as the
list of its reference uids as well, and a write that would move a reference away from another record is refused.
File references are created with `add_file_reference`, never with `add_child_record`.

Whose child a record is, is decided by the uid of the parent **and** - where the TCA defines them - by the
parent table in `foreign_table_field` and the values of `foreign_match_fields`. A child table shared by several
tables or fields (like `sys_file_reference`, which tells its parents apart by `tablenames` and `fieldname`)
therefore never mixes up the children of `tt_content:5` and `pages:5`.

### Fields of a translation

Not every field of a translation can be written. A field with `l10n_mode` `exclude` always has the value of the
record in the default language, and a field with `allowLanguageSynchronization` takes that value as long as its
localization state is not `custom` - in the backend form it is greyed out behind the checkbox "Use value of
default language". The DataHandler drops a value for such a field silently and still reports success, so in2mcp
refuses it with the reason instead, for `update_*` as well as for `add_file_reference` and `add_child_record`.

The state is changed with the field `l10n_state`, exactly like that checkbox: `{"author": "Own author",
"l10n_state": {"author": "custom"}}` gives the translation its own value, `{"l10n_state": {"author": "parent"}}`
takes the value of the default language again. Before a file or a child record is added to a synchronized field,
switch the field to `custom` with an update that contains only `l10n_state`.

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

`add_file_reference` takes optional `fields` of the new `sys_file_reference`, which are written in the same
DataHandler run as the reference itself - no `update_record` afterwards:

```json
{
    "fileUid": 42,
    "table": "tt_content",
    "uid": 815,
    "fieldName": "assets",
    "fields": {
        "title": "Laboratory",
        "alternative": "Researcher at a microscope",
        "description": "Our laboratory in Neuherberg",
        "link": "t3://page?uid=12"
    }
}
```

Unknown fields are reported instead of ignored, and fields the backend user may not write are refused, as with
`update_record`. The fields that connect the reference to the file and the record - `uid_local`, `table_local`,
`uid_foreign`, `tablenames`, `fieldname`, `sorting_foreign`, `pid`, the language and workspace fields - are set
by TYPO3 and cannot be given.

`add_file_from_url` brings a file that is not here yet into the storage. It is **switched off by default**: the
server, not the client, performs the download, so this has to be a decision of the installation.

| Setting                  | Purpose                                                              | Default    |
|--------------------------|----------------------------------------------------------------------|------------|
| `fileImport`             | Allow the import at all                                              | `0`        |
| `fileImportMaximumSize`  | Maximum file size in bytes, larger downloads are aborted             | `10485760` |
| `fileImportAllowedHosts` | Comma separated hosts a file may come from, empty allows every host  | empty      |

What the import refuses, in this order:

1. a server without the PHP extension `curl` - only curl can be told which address to connect to
2. everything but `http` and `https`, and every port but `80` and `443`
3. hosts outside `fileImportAllowedHosts`, when that list is filled
4. hosts that resolve to a local, private, link local or carrier grade NAT address - **every** address a host
   resolves to has to be public, and every redirect hop is validated again, so a public url cannot redirect the
   server into the internal network. An IPv6 address that carries an IPv4 address (IPv4-mapped `::ffff:0:0/96`,
   IPv4-compatible, 6to4 and NAT64) is judged by that IPv4 address, Teredo and NAT64 for local use are refused.
   The download then connects to exactly the address that was validated (`CURLOPT_RESOLVE`), so a name server
   cannot answer with a public address for the check and an internal one for the download (DNS rebinding)
5. more than three redirects, and relative redirect targets
6. a file above the maximum - announced or not, curl aborts the download as soon as it exceeds it
7. file names with a path in them, and names the `fileDenyPattern` of the installation rejects
8. the fallback storage `0:`, which is the web root and evaluates no permissions - the core refuses it for every
   user, administrators included, and so does every file tool of this extension

Afterwards the file goes into the storage through `ResourceStorage::addFile()`, which applies what the backend
applies to an upload: the file operation permissions of the user (`addFile`), the **file mounts**, the writable
flag of the storage, the `fileDenyPattern` again and the file name sanitizing of the driver. An existing file of
the same name is never overwritten, the new one is renamed. The import is written to the file section of
`sys_log`, next to the uploads of the backend, and a refused url is logged there as a security notice.

`delete_file` deletes a file through `ResourceStorage::deleteFile()`, which checks what the backend checks: the
file operation permissions of the user, the **file mounts** and the `fileDenyPattern`. A file outside the file
mounts is reported as not found. If the storage has a recycler folder (`_recycler_`), the file is moved there
instead of being deleted, and the answer says so with `movedToRecycler`.

A file that is **still used** is not deleted, like in the filelist module. The usages come from the reference
index - file fields as well as links in text fields like `t3://file?uid=42` - and the refusal lists them:

```
The file "stage.jpg" is still used and was not deleted: tt_content:815 field assets via sys_file_reference:4711
```

A client removes the usages first - a `sys_file_reference` with `delete_record`, a link by changing the text -
and calls `delete_file` again. The deletion is written to the file section of `sys_log`.

`sys_file` itself is **read only** for the record tools: `create_record`, `update_record` and `delete_record`
refuse it, because the DataHandler would remove the record and leave the file in the storage and its references
behind. `delete_file` is the way to delete a file.

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
  counter. An IPv6 address is counted per **/64 network** - even a home connection gets at least one of them, so
  counting single addresses would hand out a fresh limit for every request. An IPv4 address a dual stack server
  sees as `::ffff:a.b.c.d` is counted as that IPv4 address. The counter of a client is read and written under an
  exclusive lock, so requests sent at the same moment cannot all pass the limit before one of them has raised it. `X-Forwarded-For` is only trusted when `reverseProxyIP` is configured, so the address cannot be
  spoofed - but an installation behind a proxy **without** that setting sees every client as one address and
  shares one limit between them.
- **A key that can never be valid is refused first**: anything but `<uid>.<secret>` with a secret of 64 to 1024
  characters without whitespace is answered with `401` before the database is asked or a hash is verified, so a
  flood of short or broken keys costs almost nothing. A key set by hand in the backend before the minimum length
  was introduced has to be replaced if it is shorter.
- **A well formed wrong key always costs exactly one hash verification**, whether the named user has a key, has
  none or does not exist at all. Otherwise the answer time would tell an attacker which backend users an api key was created
  for. The hash an unknown key is verified against is generated by the installation itself and kept in the hash
  cache, so it costs the same as a real one.

The realistic risk is not the key being guessed but the key being **leaked** - it lives in the configuration
file of a MCP client, in plain text. Treat it like a password: one key per person, and create a new one with
`in2mcp:apikey` whenever a machine is lost or a client configuration is shared. Emptying the field or disabling
the backend user revokes it immediately.

**Content can carry instructions for the model** (prompt injection). A text an anonymous visitor entered into a
form, or a page an external author wrote, ends up in the context of the model as soon as a tool reads it, and a
model may follow what it finds there. This cannot be solved on the server, but in2mcp tells the client which
tools are harmless: every tool carries the MCP annotations `readOnlyHint`, `destructiveHint` and `openWorldHint`.
Reading tools are marked read only, `update_*`, `delete_*` and `move_record` destructive, and `add_file_from_url`
open world, because it makes the server send a request to an address the model chose. Clients use these hints to
decide what they run without asking. Recommendations:

- never let a client run the writing tools without confirmation, only the read only ones
- switch `fileImport` on only where it is needed - it is the one tool that reaches outside of the installation
- give an api key to a backend user with exactly the permissions the work needs, not to an administrator

**A key is only as valid as its user.** Disabling, deleting or time-limiting the backend user revokes the access
immediately, because the regular TYPO3 authentication chain runs afterwards.

**The api key is the only credential.** The middleware removes every cookie from the request before anything
looks at it, so a backend session can never authenticate a MCP request. Without that, the core would win before
the api key is ever asked for: it takes the user of an existing session as authenticated and only asks an
authentication service when there is no session. A backend user without an api key would otherwise reach every
tool from a browser that is logged into the backend. The backend user the authentication ends up with is
verified to be the user of the key as well, so nothing but the key decides who a request runs as.

**Multi factor authentication is skipped** for MCP requests - a client without a browser cannot solve it, and
the key itself is the credential. This applies to a user the api key authenticated and to nobody else: a
session that is still waiting for its second factor is refused, so a stolen password of an MFA protected
account does not become MCP access. An installation that enforces MFA should still know that an api key is a
way past it, and should hand keys out accordingly.

**A locked backend is locked for MCP as well.** While `[BE][adminOnly]` restricts the backend to administrators,
every other api key is answered with `401 Unauthorized`, exactly as the backend login refuses that user.

**A MCP request is no login.** Every request authenticates on its own, so it does not dispatch the
`AfterUserLoggedInEvent`: login notification mails (`warning_email_addr`, "Notify me by email when somebody logs
in from my account") and other listeners of that event are not triggered by MCP clients.

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
  whole tree, for everybody else it is no page at all. This applies to pages read with `get_record` and
  `find_records` as well, and records of other tables follow the page they are stored on.
- **Languages**: a user with a list of allowed languages reads records only in these languages, as the page and
  the list module show only these. The pages of the default language stay readable, because the page tree shows
  them to everybody. `get_page` and `get_page_tree` refuse a language the user may not access, and `get_page`
  returns content elements only to a user who may read `tt_content`. Administrators and users without such a
  list are not checked at all.
- **Password fields** (TCA type `password`) are never returned, not even masked, for administrators neither: a
  client that sends a record back with a mask would store the mask as new password. Writing them stays possible
  for whoever may write the field, the DataHandler hashes the value as usual.
- **Page tree, page search and recently changed pages** return `nav_title` and search `nav_title` and
  `subtitle` only for a user who may read these exclude fields. Page type and visibility stay, because the page
  tree of the backend shows them to everybody.
- **Exclude fields** are only returned when `non_exclude_fields` allows them, and only those can be used as a
  filter of `find_records`. The language fields and the container of a content element stay readable, because
  they describe where a record belongs.
- **Workspaces**: a request works in the workspace the user has selected in the backend, without
  EXT:workspaces that is always live. Every read returns live records and the records of that workspace, never
  the drafts of another workspace. Inside a workspace a record is returned as it looks there, under the uid of
  its live record like in the backend, and a record deleted in the workspace is left out. Two limits remain:
  filters and searches compare the live values, and a record moved inside the workspace is still listed on its
  live page. `get_workspaces` lists the workspaces the user may enter, and `switch_workspace` changes the
  workspace exactly like the selector of the backend: the choice is stored at the backend user, so the backend
  follows after its next reload. Without EXT:workspaces both tools answer with an error and everything is live.
- **Writing** goes through the **DataHandler**, so page permissions, `tables_modify`, `non_exclude_fields`, web
  mounts, hooks, reference index, slug generation, workspaces and the record history all apply unchanged. A
  refused write is reported back to the client with the reason TYPO3 gave. An update of an exclude field the
  user may not write is refused as a whole instead of being dropped silently by the DataHandler. A translation
  is refused as well when the user may not write the language field of the table.
- New pages are created **hidden**, because that is the TCA default of TYPO3 for pages - a human decides when a
  page goes live.
- Every write is written to `sys_log` by the DataHandler, so the backend history shows what happened.

- **Files** follow the **file mounts** of the user, not the table permissions of `sys_file`. An editor browses
  files in the filelist module without that table ever appearing in `tables_select`, so using it as the gate
  would either lock every editor out or, once an integrator adds it, hand out the file inventory of the whole
  installation. `search_files`, `add_file_reference` and `delete_file` therefore restrict themselves to the file
  mounts; an administrator sees everything, a user without a mount sees nothing.
- **Clearing the cache** of a single page needs that page to be in a page mount. The DataHandler itself checks
  nothing there - any uid clears any page - so in2mcp answers that question. Flushing the whole page cache or
  every cache follows `options.clearCache.pages` and `options.clearCache.all` of the user TSconfig, and a
  refusal is reported instead of silently doing nothing.
- Tables that hold authentication data, internal bookkeeping or scheduled commands are refused for **every**
  user, administrators included: `be_users`, `be_groups`, `be_sessions`, `fe_users`, `fe_groups`, `fe_sessions`,
  `sys_history`, `sys_log`, `sys_refindex`, `sys_registry`, `sys_file_processedfile`, `sys_lockedrecords`,
  `tx_scheduler_task` and `tx_scheduler_task_group`. Tables that decide what users may do or where files go are
  **read only** for every user: `sys_file_storage`, `sys_filemounts`, `sys_workspace`, `sys_workspace_stage` and
  `sys_template`, next to `sys_file`. This is the one deliberate deviation from "the client may do what the user
  may do": an api key lives in plain text in the configuration of a client, and one that leaked must not be able to
  create a backend user, widen the permissions of others, change where files are written or schedule a command.
  The lists are `TableAccessService::DENIED_TABLES` and `READ_ONLY_TABLES`.
- `get_backend_users` and `get_recent_changes` are the only tools that read `be_users` and `sys_history`, and
  they answer **administrators only**. They return a fixed set of fields - username, real name, admin and
  disabled flag, last login, the names of the active MFA providers, and for a change its table, record, action and
  the names of the changed fields. Passwords, api key hashes, email addresses, MFA secrets and the values of a
  change never leave the server. Note that every MCP request logs its backend user in, so the last login of a user
  connected through MCP is the time of its last request.
- `get_recently_changed_pages` and `get_recently_changed_content` apply the page permissions, the page mounts,
  the allowed languages and the exclude fields of the user, just like the other reading tools. Only for
  administrators they add `changedBy`, the username of the last change taken from the record history; for every
  other user that key is missing.
- `sys_file` can be read, but not written with the record tools - a file is deleted with `delete_file`, which
  goes through the file storage. The list is `TableAccessService::READ_ONLY_TABLES`.

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

A tool that fails answers with a tool result marked as error, not with an http status. Its text tells the client
what went wrong and what to call instead - but only for the errors in2mcp raises itself. Any other error, like a
database error or a message of a third party library, can carry internals such as paths, table structures or
addresses of the internal network. The client then only gets "Internal error while running ..." with a reference,
and the full message is written to the TYPO3 log under that reference. For the same reason a refused import does
not name the internal address a host resolved to.

---

## Architecture

| Class                          | Task                                                                          |
|--------------------------------|-------------------------------------------------------------------------------|
| `Middleware\McpServer`         | Answers the configured endpoint with the MCP server instead of the backend     |
| `ApiKeyAuthenticationService`  | TYPO3 authentication service, finds the backend user of the api key            |
| `AuthenticationContext`        | Carries the api key through the authentication and records the user it belongs to |
| `BackendUserAuthenticator`     | Initializes that user for the request and removes the session afterwards       |
| `BackendUserRepository`        | Looks up the hashed api key of a backend user                                  |
| `RateLimiter\RateLimiterFactory`| Sliding window limiter for failing authentications                            |
| `Mcp\ServerFactory`            | Builds the MCP server and registers every tool                                 |
| `Mcp\Converter\McpToolSchemaConverter` | Turns a tool parameter definition into a json schema                   |
| `Mcp\Executer\ToolExecuter`    | Runs a tool for a `tools/call` and formats the result                          |
| `Mcp\Tool\ToolRegistry`        | Collects everything tagged `in2mcp.tool`                                       |
| `Service\DataHandlerService`   | The only place that writes - always through the DataHandler                     |
| `Service\BackendUserService`   | Single point of access to the authenticated user and its permissions            |
| `Service\TcaService`           | Answers what page types, content types and fields exist here (Schema API)       |
| `Service\ContentTypeService`   | Restricts the content types to what the page TSconfig of a page allows          |
| `Service\FieldItemService`     | Values of select, radio and multi checkbox fields, as the backend form builds them |
| `Service\TceFormService`       | Reads `TCEFORM` of the page TSconfig, including the part for a record type       |
| `Service\TableAccessService`   | Decides which tables may be read and written at all                             |
| `Repository\RecordRepository`  | Reads records of any table                                                      |
| `Repository\FileRepository`    | Reads files and the references that point at them                               |
| `Repository\RecordQueryBuilderFactory` | Query builder for records: no deleted ones, no drafts of other workspaces |
| `Service\WorkspaceService`     | The workspace of a request, and the overlay of a record with its workspace version |
| `Service\FileImportService`    | Downloads a file into the storage, streamed and size limited                    |
| `Service\UrlValidationService` | The only guard on the one outgoing request this extension makes                 |
| `Service\InlineRelationService`| Translates between the child counter and the list of child uids                 |

The [official MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk) (`mcp/sdk`) handles the protocol.
Sessions are stored in the filesystem (`var/in2mcp/mcp`), because a client sends its session id in every request.

### Adding a tool

Implement `ToolInterface` (or extend `AbstractTool`) anywhere under `Classes/Domain/Mcp/Tool/`. The service
configuration tags every implementation as `in2mcp.tool` and the `ToolRegistry` picks it up - no registration
needed.

A tool that extends `AbstractTool` is taken as destructive unless it says otherwise, so a client asks before it
runs it. A tool that only reads sets `protected const READ_ONLY = true;`, a tool that only adds something sets
`protected const DESTRUCTIVE = false;`, and a tool that reaches outside of the installation sets
`protected const OPEN_WORLD = true;`. These become the MCP tool annotations.

### Tool results

The `ToolExecuter` builds the result itself instead of letting the sdk do it, because the sdk would use any
array as `structuredContent` - including a list, which the MCP specification does not allow and which makes
clients fail schema validation. Therefore a list is wrapped into `{"result": [...]}`, a record is used as it is
and a scalar gets no `structuredContent` at all.
