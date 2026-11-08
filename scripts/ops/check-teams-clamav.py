#!/usr/bin/env python3
"""Exercise the local Teams INSTREAM contract with synthetic bytes only."""

import argparse
import datetime
import json
import socket
import struct
import sys
import time

HOST = "127.0.0.1"
UPLOAD_LIMIT = 10 * 1024 * 1024


def receive(client):
    reply = bytearray()
    while len(reply) < 4096:
        part = client.recv(1024)
        if not part:
            break
        reply.extend(part)
        if b"\0" in reply:
            return bytes(reply).split(b"\0", 1)[0].decode("utf-8", errors="strict")
    raise RuntimeError("Scanner did not return a bounded NUL-terminated reply")


def command(port, name):
    with socket.create_connection((HOST, port), timeout=3) as client:
        client.settimeout(10)
        client.sendall(b"z" + name.encode("ascii") + b"\0")
        return receive(client)


def scan(port, payload):
    with socket.create_connection((HOST, port), timeout=3) as client:
        client.settimeout(10)
        client.sendall(b"zINSTREAM\0")
        try:
            for offset in range(0, len(payload), 65536):
                part = payload[offset:offset + 65536]
                client.sendall(struct.pack("!I", len(part)) + part)
            client.sendall(struct.pack("!I", 0))
        except (BrokenPipeError, ConnectionResetError):
            # An oversize stream may be closed early. A missing reply still
            # fails; an early close alone does not prove correct enforcement.
            pass
        return receive(client)


def check(port, boundaries=False):
    started = time.monotonic()
    ping = command(port, "PING")
    if ping != "PONG":
        raise RuntimeError("Scanner PING did not return PONG")
    version = command(port, "VERSION")
    segments = version.split("/", 2)
    if len(segments) != 3 or not segments[0].startswith("ClamAV "):
        raise RuntimeError("Scanner version/signature date was not available")
    # Dedicated units run with TZ=UTC. Unknown dates fail instead of being
    # treated as fresh. VERSION supplies the engine's loaded database date.
    database_date = datetime.datetime.strptime(segments[2], "%a %b %d %H:%M:%S %Y").replace(tzinfo=datetime.timezone.utc)
    age = datetime.datetime.now(datetime.timezone.utc) - database_date
    if age.total_seconds() < -86400 or age > datetime.timedelta(days=7):
        raise RuntimeError("Loaded signatures are stale or their date is invalid")
    benign = scan(port, b"Everbranch synthetic scanner health check.\n")
    if benign != "stream: OK":
        raise RuntimeError("Benign synthetic stream was not clean")
    # Standard harmless antivirus test string, assembled without creating a file.
    eicar = b"X5O!P%@AP[4\\PZX54(P^)7CC)7}" + b"$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*"
    detection = scan(port, eicar)
    if "EICAR" not in detection.upper() or not detection.endswith(" FOUND"):
        raise RuntimeError("The standard antivirus test was not detected")
    boundary = None
    if boundaries:
        clean_limit = scan(port, b"x" * UPLOAD_LIMIT)
        if clean_limit != "stream: OK":
            raise RuntimeError("A benign 10 MiB stream did not match the app limit")
        over_limit = scan(port, b"x" * (UPLOAD_LIMIT + 1))
        if "size limit exceeded" not in over_limit.lower() or not over_limit.endswith("ERROR"):
            raise RuntimeError("The stream limit was not explicitly enforced")
        boundary = {"at_limit_clean": True, "above_limit_rejected": True}
    return {"ok": True, "host": HOST, "port": port, "version": version,
            "database_age_hours": round(age.total_seconds() / 3600, 1),
            "benign_clean": True, "standard_test_detected": True,
            "boundary": boundary, "elapsed_ms": round((time.monotonic() - started) * 1000)}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port", type=int, default=3310)
    parser.add_argument("--boundaries", action="store_true", help="Also test 10 MiB and 10 MiB + 1 byte")
    args = parser.parse_args()
    if not 1 <= args.port <= 65535:
        parser.error("Use a valid local TCP port")
    try:
        print(json.dumps(check(args.port, args.boundaries), sort_keys=True))
        return 0
    except (OSError, RuntimeError, ValueError, UnicodeError) as error:
        print(json.dumps({"ok": False, "host": HOST, "port": args.port, "error": str(error)}), file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
