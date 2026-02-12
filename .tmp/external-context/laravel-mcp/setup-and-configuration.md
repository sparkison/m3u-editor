---
source: Official Laravel Docs
library: Laravel MCP
package: laravel/mcp
topic: setup-and-configuration
fetched: 2026-02-12T00:00:00Z
official_docs: https://laravel.com/docs/mcp
---

## Introduction

Laravel MCP allows you to rapidly build MCP servers for your Laravel applications. MCP servers allow AI clients to interact with your Laravel application through the [Model Context Protocol](https://modelcontextprotocol.io/docs/getting-started/intro).

## Installation

To get started, install Laravel MCP into your project using the Composer package manager:

```
composer require laravel/mcp
```

### Publishing Routes

After installing Laravel MCP, execute the `vendor:publish` Artisan command to publish the `routes/ai.php` file where you will define your MCP servers:

```
php artisan vendor:publish --tag=ai-routes
```

This command creates the `routes/ai.php` file in your application's `routes` directory, which you will use to register your MCP servers.

## Creating Servers

You can create an MCP server using the `make:mcp-server` Artisan command. Servers act as the central communication point that exposes MCP capabilities like tools, resources, and prompts to AI clients:

```
php artisan make:mcp-server WeatherServer
```

This command will create a new server class in the `app/Mcp/Servers` directory. The generated server class extends Laravel MCP's base `Laravel\Mcp\Server` class and provides properties for registering tools, resources, and prompts:

```php
<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;

class WeatherServer extends Server
{
    /**
     * The MCP server's name.
     */
    protected string $name = 'Weather Server';

    /**
     * The MCP server's version.
     */
    protected string $version = '1.0.0';

    /**
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = 'This server provides weather information and forecasts.';

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        // GetCurrentWeatherTool::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Resource>>
     */
    protected array $resources = [
        // WeatherGuidelinesResource::class,
    ];

    /**
     * The prompts registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Prompt>>
     */
    protected array $prompts = [
        // DescribeWeatherPrompt::class,
    ];
}
```

### Server Registration

Once you've created a server, you must register it in your `routes/ai.php` file to make it accessible. Laravel MCP provides two methods for registering servers: `web` for HTTP-accessible servers and `local` for command-line servers.

### Web Servers

Web servers are the most common types of servers and are accessible via HTTP POST requests, making them ideal for remote AI clients or web-based integrations. Register a web server using the `web` method:

```php
use App\Mcp\Servers\WeatherServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/weather', WeatherServer::class);
```

Just like normal routes, you may apply middleware to protect your web servers:

```php
Mcp::web('/mcp/weather', WeatherServer::class)
    ->middleware(['throttle:mcp']);
```

### Local Servers

Local servers run as Artisan commands, perfect for building local AI assistant integrations like [Laravel Boost](/docs/12.x/installation#installing-laravel-boost). Register a local server using the `local` method:

```php
use App\Mcp\Servers\WeatherServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('weather', WeatherServer::class);
```

Once registered, you should not typically need to manually run the `mcp:start` Artisan command yourself. Instead, configure your MCP client (AI agent) to start the server or use the [MCP Inspector](#mcp-inspector).

## Authentication

Laravel MCP integrates with Laravel's built-in authentication systems to secure your MCP servers.

### OAuth 2.1

For web servers, you can use OAuth 2.1 to authenticate AI clients. Laravel MCP provides built-in support for OAuth 2.1 flows:

```php
Mcp::web('/mcp/weather', WeatherServer::class)
    ->oauth();
```

### Sanctum

You can also use Laravel Sanctum for API token authentication:

```php
Mcp::web('/mcp/weather', WeatherServer::class)
    ->sanctum();
```

## Authorization

Laravel MCP allows you to authorize access to your MCP servers using Laravel's authorization features. You can define policies for your servers and check permissions within your server classes or individual tools/resources/prompts.

## Testing Servers

Laravel MCP provides tools to test your MCP servers during development.

### MCP Inspector

The MCP Inspector is a web-based tool that allows you to interact with your MCP servers directly in the browser. To start the inspector, run the `mcp:inspect` Artisan command:

```
php artisan mcp:inspect
```

This will start a local web server where you can test your server's tools, resources, and prompts.

### Unit Tests

You can write unit tests for your MCP servers using PHPUnit. Laravel MCP provides base test classes and helpers to make testing easier.

For example, to test a tool:

```php
<?php

namespace Tests\Mcp\Tools;

use Laravel\Mcp\Testing\McpToolTestCase;
use Tests\TestCase;

class CurrentWeatherToolTest extends McpToolTestCase
{
    public function test_tool_returns_weather_data()
    {
        $response = $this->callTool(CurrentWeatherTool::class, [
            'location' => 'New York',
        ]);

        $response->assertSuccessful();
        $response->assertHasContent('Sunny');
    }
}
```