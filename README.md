# Munitunnel Client

Command-line client for the Munitunnel WebSocket control plane. It connects to a
control server, registers a subdomain, and spins up per-request proxy sockets to
answer HTTP requests routed through the tunnel.

## What it does

- Connects to a control WebSocket (`connect` command, default `ws://127.0.0.1:8081`).
- Registers the client with a subdomain (currently hard-coded in code).
- Listens for `createProxy` events and opens a secondary WebSocket (`ws://127.0.0.1:8082`).
- Handles `httpRequest` events on the proxy socket and responds with a basic payload.

## Requirements

- PHP 8.2+
- Composer

## Installation

```bash
composer install
```

## Usage

Run the CLI with the default control server:

```bash
./application connect
```

Point to another control server:

```bash
./application connect --url=ws://127.0.0.1:8081
```

## Protocol notes

The client expects JSON messages over WebSocket:

- Control socket messages include `event: createProxy` with `data.requestId`.
- Proxy socket messages include `event: httpRequest` with `data.path`.
- The client responds with `event: httpResponse` and includes `requestId`, `status`,
  and `body`.

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
