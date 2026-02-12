---
source: Official Laravel Docs
library: Laravel Boost
package: laravel-boost
topic: installation-setup-and-mcp-configuration
fetched: 2026-02-12T00:00:00Z
official_docs: https://laravel.com/docs/12.x/boost
---

# Laravel Boost

## Introduction

Laravel Boost accelerates AI-assisted development by providing the essential guidelines and agent skills that help AI agents write high-quality Laravel applications that adhere to Laravel best practices.

Boost also provides a powerful Laravel ecosystem documentation API that combines a built-in MCP tool with an extensive knowledge base containing over 17,000 pieces of Laravel-specific information, all enhanced by semantic search capabilities using embeddings for precise, context-aware results. Boost instructs AI agents like Claude Code and Cursor to use this API to learn about the latest Laravel features and best practices.

## Installation

Laravel Boost can be installed via Composer:

```
composer require laravel/boost --dev
```

Next, install the MCP server and coding guidelines:

```
php artisan boost:install
```

The `boost:install` command will generate the relevant agent guideline and skill files for the coding agents you selected during the installation process.

Once Laravel Boost has been installed, you're ready to start coding with Cursor, Claude Code, or your AI agent of choice.

Feel free to add the generated MCP configuration file (`.mcp.json`), guideline files (`CLAUDE.md`, `AGENTS.md`, `junie/`, etc.), and the `boost.json` configuration file to your application's `.gitignore`, as these files are automatically regenerated when running `boost:install` and `boost:update`.

### Keeping Boost Resources Updated

You may want to periodically update your local Boost resources (AI guidelines and skills) to ensure they reflect the latest versions of the Laravel ecosystem packages you have installed. To do so, you can use the `boost:update` Artisan command.

```
php artisan boost:update
```

You may also automate this process by adding it to your Composer "post-update-cmd" scripts:

```
{
  "scripts": {
    "post-update-cmd": [
      "@php artisan boost:update --ansi"
    ]
  }
}
```

### Set Up Your Agents

Cursor Claude Code Codex Gemini CLI GitHub Copilot (VS Code) Junie

```
1. Open the command palette (`Cmd+Shift+P` or `Ctrl+Shift+P`)
2. Press `enter` on "/open MCP Settings"
3. Turn the toggle on for `laravel-boost`
```

```
Claude Code support is typically enabled automatically. If you find it isn't, open a shell in the project's directory and run the following command:

claude mcp add -s local -t stdio laravel-boost php artisan boost:mcp
```

```
Codex support is typically enabled automatically. If you find it isn't, open a shell in the project's directory and run the following command:

codex mcp add laravel-boost -- php "artisan" "boost:mcp"
```

```
Gemini CLI support is typically enabled automatically. If you find it isn't, open a shell in the project's directory and run the following command:

gemini mcp add -s project -t stdio laravel-boost php artisan boost:mcp
```

```
1. Open the command palette (`Cmd+Shift+P` or `Ctrl+Shift+P`)
2. Press `enter` on "MCP: List Servers"
3. Arrow to `laravel-boost` and press `enter`
4. Choose "Start server"
```

```
1. Press `shift` twice to open the command palette
2. Search "MCP Settings" and press `enter`
3. Check the box next to `laravel-boost`
4. Click "Apply" at the bottom right
```

## MCP Server

Laravel Boost provides an MCP (Model Context Protocol) server that exposes tools for AI agents to interact with your Laravel application. These tools give agents the ability to inspect your application's structure, query the database, execute code, and more.

### Available MCP Tools

Name

Notes

Application Info

Read PHP & Laravel versions, database engine, list of ecosystem packages with versions, and Eloquent models

Browser Logs

Read logs and errors from the browser

Database Connections

Inspect available database connections, including the default connection

Database Query

Execute a query against the database

Database Schema

Read the database schema

Get Absolute URL

Convert relative path URIs to absolute so agents generate valid URLs

Get Config

Get a value from the configuration files using "dot" notation

Last Error

Read the last error from the application's log files

List Artisan Commands

Inspect the available Artisan commands

List Available Config Keys

Inspect the available configuration keys

List Available Env Vars

Inspect the available environment variable keys

List Routes

Inspect the application's routes

Read Log Entries

Read the last N log entries

Search Docs

Query the Laravel hosted documentation API service to retrieve documentation based on installed packages

Tinker

Execute arbitrary code within the context of the application

### Manually Registering the MCP Server

Sometimes you may need to manually register the Laravel Boost MCP server with your editor of choice. You should register the MCP server using the following details:

**Command**

`php`

**Args**

`artisan boost:mcp`

JSON example:

```
{
    "mcpServers": {
        "laravel-boost": {
            "command": "php",
            "args": ["artisan", "boost:mcp"]
        }
    }
}
```