# in2mcp - MCP Server for TYPO3

Turns a TYPO3 installation into a **Model Context Protocol (MCP) server**, so that MCP clients like
Claude (Desktop, Web, Code) or Gemini can connect to the CMS and work on it - reading the page tree,
inspecting existing content and creating or editing records within the permissions of the connected
backend user.

> Status: **alpha** - under active development, no stable API yet.

## Table of Contents

- [Introduction](#introduction)
- [Requirements](#requirements)
- [Installation](#installation)
- [Contribution with DDEV](#contribution-with-ddev)
- [Changelog](#changelog)

## Introduction

MCP knows three roles: a **host** (the chat application), a **client** (the connector inside the host)
and a **server** (the system that offers the capabilities). in2mcp is the **server** part: TYPO3
exposes `tools`, `resources` and `prompts`, the LLM decides when to call them.

## Requirements

- TYPO3 13.4 LTS or 14.x
- PHP 8.2 or higher

## Installation

### With composer

```
composer require in2code/in2mcp
```

### With extension manager

Search for `in2mcp` in the TYPO3 extension manager and install it.

## Contribution with DDEV

This repository ships a ready to use DDEV environment including a small TYPO3 test instance
(database dump and fileadmin files).

```bash
ddev start
ddev initialize
```

`ddev initialize` links the TYPO3 configuration from `.ddev/TYPO3/` into `config/`, imports the
database dump from `.ddev/data/db.sql.gz`, extracts the fileadmin archive, runs `composer install`
and copies the default `.htaccess`.

- Frontend: https://in2mcp.ddev.site
- Backend: https://in2mcp.ddev.site/typo3 (admin / admin)

### Notes on `ddev start`

- `additional.php already exists and is managed by the user.` is expected. `config/system/additional.php`
  is a symlink to `.ddev/TYPO3/additional.php` and therefore not DDEV managed - exactly what we want.
- `.ddev/web-build/Dockerfile` forces git to HTTP/1.1 inside the web container. Without it, git 2.39.5
  fails on GitHub's HTTP/2 ref listing (`could not read Username` / `expected flush after ref listing`),
  which breaks `install_nvm.sh` on every container start and any git based composer repository.

Useful commands:

```bash
ddev composer install                       # install dependencies
ddev exec .Build/bin/typo3 cache:flush      # flush all caches
ddev mysql                                  # database shell
ddev createdumpfile                         # update .ddev/data/db.sql.gz from current database
ddev createfilesarchive                     # update .ddev/data/fileadmin.tar.gz from current files
```

## Changelog

| Version | Date       | State | Description             |
|---------|------------|-------|-------------------------|
| 0.0.1   | 2026-09-02 | alpha | Initial extension setup |
