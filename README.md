# Munitunnel Client

Command-line client for the Munitunnel WebSocket control plane. It connects to a
control server, registers a subdomain, and maintains a persistent proxy socket
to answer HTTP requests routed through the tunnel.

## What it does

- Connects to a control WebSocket (`connect` command, default `ws://127.0.0.1:8081`).
- Registers the client with a subdomain (configurable via CLI).
- Opens a persistent proxy WebSocket (`ws://127.0.0.1:8082` by default).
- Forwards `httpRequest` events to a local upstream and returns the real response.

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

Forward to a local upstream (default is `http://127.0.0.1:3000`):

```bash
./application connect --upstream=http://127.0.0.1:8000
```

Connect to a hosted server (TLS + token):

```bash
./application connect \
  --url=wss://control.example.com \
  --proxy-url=wss://proxy.example.com \
  --subdomain=demo \
  --token=token-1 \
  --upstream=http://127.0.0.1:8000
```

The subdomain you register must match a wildcard DNS record pointed at the
server (for example `*.example.com`).

If the server runs in per-subdomain auth mode, you must pass the matching token
with `--token` or set `MUNITUNNEL_AUTH_TOKEN`.

You can also set defaults via environment variables:
```bash
export MUNITUNNEL_AUTH_TOKEN=token-1
export MUNITUNNEL_UPSTREAM_URL=http://127.0.0.1:8000
```

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
