/**
 * Cloudflare Pages middleware: content negotiation for the homepage.
 *
 * A request for / (or /index.html) that asks for markdown — an Accept header
 * naming text/markdown — is answered with the built /index.md instead of the
 * HTML page. Browsers never send text/markdown, so a simple containment check
 * is sufficient; everything else falls through to the static assets.
 *
 * If the site is ever deployed somewhere other than Cloudflare Pages, this
 * negotiation needs an equivalent at that edge.
 */
export async function onRequest(context) {
  const { request, env, next } = context;
  const url = new URL(request.url);
  const wantsMarkdown = (request.headers.get('accept') ?? '').includes('text/markdown');

  if (wantsMarkdown && (url.pathname === '/' || url.pathname === '/index.html')) {
    const asset = await env.ASSETS.fetch(new URL('/index.md', url));
    const res = new Response(asset.body, asset);
    res.headers.set('Content-Type', 'text/markdown; charset=utf-8');
    res.headers.set('Vary', 'Accept');
    return res;
  }

  const res = await next();
  if (url.pathname === '/' || url.pathname === '/index.html') {
    const out = new Response(res.body, res);
    out.headers.set('Vary', 'Accept');
    return out;
  }
  return res;
}
