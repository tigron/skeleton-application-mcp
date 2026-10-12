# skeleton-application-mcp

Skeleton application for creating an MCP (Model Context Protocol) server.
Protocol documentation is [here](https://modelcontextprotocol.io/docs/develop/build-server).

## Installation

Installation via composer:

    composer require tigron/skeleton-application-mcp

## How it works

An MCP client speaks JSON-RPC and uses a few set methods

  * `initialize`: basic metadata about the server
  * `tools/list`: list of tools available to the client, and how to call them
  * `tools/call`: actual call to a tool
  * `ping`: well, ping

## Setup the application

A `skeleton-application-mcp` needs to define the following:


    - app-directory from skeleton-core
      - your app name
        - config
        - tool
        - security (optional)
        - event (optional)

Namespaces work like usual:

    tool: \App\{APP_NAME}\Tool
    security: \App\{APP_NAME}\Security
    event: \App\{APP_NAME}\Event


## Configuration

| Configuration      | Description                                                    | Default      |
| ------------------ | -------------------------------------------------------------- | ------------ |
| `application_type` | (required) Must be set to `\Skeleton\Application\Mcp`          | `\Skeleton\Application\Web` |
| `hostnames`        | (required) Array of hostnames to listen on; wildcards via `*`  | `[]`         |
| `server_name`      | Name reported in the `initialize` call                         | app name     |
| `server_version`   | Version reported in the `initialize` call                      | `1.0.0`      |
| `protocol_version` | MCP protocol version                                           | `2025-06-18` |
| `instructions`     | Instructions for the LLM on how to use your MCP server         | `''`         |

## Tools

A "tool" is a single callable endpoint. Every tool extends from
`\Skeleton\Application\Mcp\Tool` and needs to declares an `execute()` method.

This method can accept whatever inputs are needed, the JSON input schema gets
generated from the docblock.

Example:

```php
<?php
namespace App\Mcp\Tool;

/**
 * This will be provided as the description of the tool. It will be used by the
 * LLM to evaluate whether this call is suitable to solve the current prompt, so
 * make sure this is accurate and descriptive. Example:
 *
 * Returns the customer profile. Use this before other customer tools to resolve
 * customer daya (name, e-mail, ...) into an ID.
 */
class Find_Customer extends \Skeleton\Application\Mcp\Tool {

    /**
	   * @param string $query An e-mail address or customer number
	   * @return array The matching customer (or an error if there's no match)
	   */
	  public function execute(string $query): array {
	      try {
            $customer = \Customer::find($query);
		    } catch (\Exception $e) {
			      throw new \Skeleton\Application\Mcp\Exception('Customer not found');
		    }
	  }
}
```

The advertised tool name is the classname, in lowercase. Tools in lower
namespaces are flattened with an underscore. If you want to use a custom name,
override the `get_name()` method.

### Parameters

Each parameter of `execute()` becomes a property in the JSON schema. The type
gets converted from a PHP type into a JSON one, the description comes from the
`@param` docblock. Parameters are required unless you set a default value.

## Security

If you want to add authentication, you need to implement a security handler. A
tool can be made to require a given handler by using the `@security` docblock.

Example:

```php
/**
 * @param int $customer_id Some customer_id
 * @security \App\Mcp\Security\Bearertoken
 * @return array
 */
public function execute(int $customer_id): array { }
```

A security handler needs to extend `\Skeleton\Application\Mcp\Security` and
implement a `handle()` method. If it did not validate, it needs to throw a
`\Skeleton\Application\Mcp\Jsonrpc\Exception`.

Example:

```php
<?php
namespace App\Mcp\Security;

class Bearertoken extends \Skeleton\Application\Mcp\Security {
	public function handle(): void {
    // check whatever header you need

		if ($valid === false) {
			throw new \Skeleton\Application\Mcp\Jsonrpc\Exception('Unauthorized', -32001);
		}
	}
}
```

## Events

For `Tool`, the usual `bootstrap()` and `teardown()` events are supported.