"""Loopback-only HTTP/1.1 fixture with fictional request data and socket counts."""
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class Server(ThreadingHTTPServer):
    daemon_threads = True
    accepted_connections = 0

    def get_request(self):
        connection = super().get_request()
        self.accepted_connections += 1
        return connection


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def do_GET(self):
        body = json.dumps({"connections": self.server.accepted_connections,
                           "authorization": self.headers.get("Authorization"),
                           "cookie": self.headers.get("Cookie")}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Set-Cookie", "fixture_session=must-not-persist; Path=/")
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *_):
        pass


server = Server(("127.0.0.1", 0), Handler)
print(server.server_port, flush=True)
server.serve_forever()
