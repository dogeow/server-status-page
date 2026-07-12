const API_BASE = process.env.API_INTERNAL_URL ?? "http://localhost:8000";

async function proxy(request: Request, context: { params: Promise<{ path: string[] }> }) {
  const { path } = await context.params;
  const incoming = new URL(request.url);
  const target = new URL(`/api/${path.join("/")}`, API_BASE);
  target.search = incoming.search;
  const headers = new Headers(request.headers);
  headers.delete("host");
  // Let the internal fetch return an uncompressed body. Forwarding an already
  // decoded stream together with the upstream Content-Encoding can make the
  // browser attempt to decompress it a second time.
  headers.delete("accept-encoding");
  headers.set("accept", headers.get("accept") ?? "application/json");
  const method = request.method.toUpperCase();
  const response = await fetch(target, {
    method,
    headers,
    body: ["GET", "HEAD"].includes(method) ? undefined : await request.arrayBuffer(),
    redirect: "manual",
  });
  return new Response(response.body, { status: response.status, headers: response.headers });
}

export const GET = proxy;
export const POST = proxy;
export const PUT = proxy;
export const PATCH = proxy;
export const DELETE = proxy;
export const OPTIONS = proxy;
