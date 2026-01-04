# Munitunnel Client

Command-line client for the Munitunnel WebSocket control plane. It connects to a
control server, registers a subdomain, and maintains a persistent proxy socket
to answer HTTP requests routed through the tunnel.

## What it does

- Connects to a control WebSocket (`connect` command, default `ws://127.0.0.1:8081`).
- Registers the client with a subdomain (configurable via CLI).
- Opens a persistent proxy WebSocket (`ws://127.0.0.1:8082` by default).
- Handles `httpRequest` events on the proxy socket and responds with a basic payload.

## Requirements

- PHP 8.2+
- Composer

## Installation

```bash
composer install
```

## Setup from git clone
```bash
git clone <repo-url>
cd munitunnel/client
composer install
```

## Usage

Run the CLI with defaults:

```bash
./application connect
```

Point to another control server:

```bash
./application connect --url=ws://127.0.0.1:8081
```

Use a custom subdomain and proxy URL:

```bash
./application connect --subdomain=demo --proxy-url=ws://127.0.0.1:8082
```

Connect to a hosted server:

```bash
./application connect --url=ws://example.com:8081 --subdomain=demo --proxy-url=ws://example.com:8082
```

The subdomain you register must match a wildcard DNS record pointed at the
server (for example `*.example.com`).

## Protocol notes

The client expects JSON messages over WebSocket:

- Control socket messages include `event: registered` with `data.publicUrl`.
- Proxy socket messages include `event: httpRequest` with request metadata.
- The client responds with `event: httpResponse` and includes `requestId`, `status`,
  and `body`.
- The server sends periodic `ping` messages; the client replies with `pong`.

You can adjust the registration payload, control/proxy URLs, and response body in:

- `app/Commands/ConnectCommand.php`

## Development

List available commands:

```bash
./application list
```

Run the sample command:

```bash
./application inspire
```

## Tests

```bash
vendor/bin/pest
```

## License

MIT
