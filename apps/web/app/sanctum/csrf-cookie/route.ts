const API_BASE = process.env.API_INTERNAL_URL ?? "http://localhost:8000";

export async function GET(request: Request) {
  const target = new URL("/sanctum/csrf-cookie", API_BASE);
  const headers = new Headers(request.headers);
  headers.delete("host");
  headers.delete("accept-encoding");
  const response = await fetch(target, { headers, redirect: "manual" });
  return new Response(response.body, { status: response.status, headers: response.headers });
}
