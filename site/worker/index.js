/**
 * The site's edge entry point: a Worker in front of the static build in dist/.
 *
 * Two things happen here that a plain asset host cannot do. A request on a
 * www. hostname or over plain http is redirected to https on the bare apex, so
 * the site has one canonical origin. And a request for / (or /index.html) that asks for markdown — an
 * Accept header naming text/markdown — is answered with the built /index.md
 * instead of the HTML page. Browsers never send text/markdown, so a simple
 * containment check is sufficient; everything else falls through to the
 * static assets.
 *
 * `run_worker_first` in wrangler.jsonc is what routes every request through
 * here before the asset server sees it; without it the redirect and the
 * negotiation would both be bypassed for any path that matches a file.
 */
export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    const isLocal = url.hostname === 'localhost' || url.hostname === '127.0.0.1';
    const canonical = new URL(url);
    if (canonical.hostname.startsWith('www.')) canonical.hostname = canonical.hostname.slice(4);
    if (!isLocal) canonical.protocol = 'https:';
    if (canonical.toString() !== url.toString()) {
      return Response.redirect(canonical.toString(), 301);
    }

    const isHome = url.pathname === '/' || url.pathname === '/index.html';
    const wantsMarkdown = (request.headers.get('accept') ?? '').includes('text/markdown');

    if (isHome && wantsMarkdown) {
      const asset = await env.ASSETS.fetch(new URL('/index.md', url));
      const res = new Response(asset.body, asset);
      res.headers.set('Content-Type', 'text/markdown; charset=utf-8');
      res.headers.set('Vary', 'Accept');
      return res;
    }

    const res = await env.ASSETS.fetch(request);
    if (isHome) {
      const out = new Response(res.body, res);
      out.headers.set('Vary', 'Accept');
      return out;
    }
    return res;
  },
};
