export const dynamic = "force-dynamic";

export async function GET(request: Request) {
  const nonce =
    new URL(request.url).searchParams.get("nonce") ??
    request.headers.get("x-status-nonce") ??
    request.headers.get("x-status-probe-nonce");
  if (!nonce || nonce.length > 256) {
    return Response.json(
      { status: "invalid", code: "nonce_required" },
      { status: 422, headers: { "Cache-Control": "no-store, max-age=0" } },
    );
  }

  return Response.json(
    {
      status: "ok",
      nonce,
      checked_at: new Date().toISOString(),
      runtime: "nextjs",
    },
    {
      headers: {
        "Cache-Control": "no-store, max-age=0",
        "X-Status-Nonce": nonce,
      },
    },
  );
}
